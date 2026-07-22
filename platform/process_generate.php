<?php
declare(strict_types=1);

ini_set('max_execution_time', '420');
ini_set('max_input_time', '420');
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
ini_set('log_errors', '1');

set_time_limit(420);
ignore_user_abort(true);

require_once __DIR__ . '/app/bootstrap.php';

$jobId = '';

if (defined('PROCESS_JOB_ID')) {
    $jobId = PROCESS_JOB_ID;
} elseif (!empty($argv[1])) {
    $jobId = $argv[1];
} elseif (!empty($_GET['job'])) {
    $jobId = $_GET['job'];
}

$jobId = basename((string)$jobId);

if (!$jobId) {
    exit("Falta job_id\n");
}

$jobDir = __DIR__ . '/jobs/' . $jobId;
$statusFile = $jobDir . '/status.json';

if (!is_dir($jobDir)) {
    mkdir($jobDir, 0775, true);
}

if (!is_file($statusFile) && StorageService::isGcsActive()) {
    StorageService::downloadFile('jobs/' . $jobId . '/status.json', $statusFile);
}

if (!is_file($statusFile)) {
    http_response_code(404);
    exit("No existe el trabajo\n");
}

// Configurar el log de errores de PHP en el directorio del trabajo
ini_set('error_log', $jobDir . '/process_err.log');

if (PHP_SAPI !== 'cli' && !defined('PROCESS_GENERATE_ALLOW_HTTP')) {
    http_response_code(403);
    exit("La ejecucion por HTTP esta deshabilitada. Este script solo puede ejecutarse en segundo plano desde CLI.\n");
}

function read_status_file(string $statusFile): array
{
    $data = json_decode((string)file_get_contents($statusFile), true);
    return is_array($data) ? $data : [];
}

function write_status_file(string $statusFile, array $data): void
{
    $data['updated_at'] = date('c');

    file_put_contents(
        $statusFile,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if (StorageService::isGcsActive() && !empty($data['job_id'])) {
        StorageService::uploadFile('jobs/' . basename((string)$data['job_id']) . '/status.json', $statusFile);
    }
}

function update_job_status(string $statusFile, string $status, string $message, ?string $resultFile = null, ?string $error = null): void
{
    $data = read_status_file($statusFile);
    $data['status'] = $status;
    $data['message'] = $message;

    if ($resultFile !== null) {
        $data['result_file'] = $resultFile;
    }

    if ($error !== null) {
        $data['error'] = $error;
    }

    write_status_file($statusFile, $data);
    update_artwork_record($data, $status, $resultFile);
}

function update_artwork_record(array $jobData, string $status, ?string $resultFile = null): void
{
    if (empty($jobData['job_id'])) {
        return;
    }

    try {
        $fields = [
            'status' => $status,
            'updated_at' => date('c'),
            'job_id' => (string)$jobData['job_id'],
        ];

        $sql = 'UPDATE artworks SET status = :status, updated_at = :updated_at';

        if ($resultFile !== null) {
            $sql .= ', root_file = :root_file';
            $fields['root_file'] = basename($resultFile);
        }

        $sql .= ' WHERE job_id = :job_id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($fields);
    } catch (Throwable $e) {
        error_log('No se pudo actualizar artwork: ' . $e->getMessage());
    }
}

try {
    $status = read_status_file($statusFile);
    ProviderSettings::set($status['provider_settings'] ?? []);

    $mainFile = basename((string)($status['main_file'] ?? ''));
    if ($mainFile !== '') {
        $mainPath = $jobDir . DIRECTORY_SEPARATOR . $mainFile;
        if (!is_file($mainPath) && StorageService::isGcsActive()) {
            StorageService::downloadFile('jobs/' . $jobId . '/' . $mainFile, $mainPath);
        }
    }

    update_job_status(
        $statusFile,
        'processing',
        'Creating root image candidates...'
    );

    $generationProvider = ServiceFactory::generationProvider((string)($status['generation_provider'] ?? ''));
    $processor = ServiceFactory::artworkProcessor($generationProvider);
    $result = $processor->createRootImage($jobDir, $status);

    // Update status.json with candidates
    $diskStatus = [];
    for ($i = 0; $i < 5; $i++) {
        $diskStatus = read_status_file($statusFile);
        if (!empty($diskStatus) && isset($diskStatus['job_id'])) {
            break;
        }
        usleep(100000); // 100ms
    }

    // Fallback to memory status if disk read failed or is corrupted
    if (empty($diskStatus) || !isset($diskStatus['job_id'])) {
        $diskStatus = $status;
    }

    $candidateFiles = array_values(array_filter(array_map('basename', (array)($result['files'] ?? []))));

    if (StorageService::isGcsActive()) {
        foreach ($candidateFiles as $candidateFile) {
            $candidatePath = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$candidateFile);
            if (is_file($candidatePath)) {
                StorageService::uploadFile('results/' . basename((string)$candidateFile), $candidatePath);
            }
        }
    }

    if (!empty($diskStatus['user_scene_flow']) && $candidateFiles !== []) {
        $selectedRootFile = (string)$candidateFiles[0];
        $diskStatus['status'] = 'processing';
        $diskStatus['message'] = 'Root image prepared. Analyzing the artwork...';
        $diskStatus['candidates'] = $candidateFiles;
        $diskStatus['result_file'] = $selectedRootFile;
        write_status_file($statusFile, $diskStatus);

        update_artwork_record($diskStatus, 'done', $selectedRootFile);

        if (ProviderSettings::isRealMode() && ProviderSettings::allowRealApi() && $generationProvider === 'gemini') {
            try {
                $v2Stmt=Database::connection()->prepare('SELECT * FROM artworks WHERE job_id=? LIMIT 1');$v2Stmt->execute([(string)$diskStatus['job_id']]);$v2Artwork=$v2Stmt->fetch(PDO::FETCH_ASSOC);
                if(is_array($v2Artwork)){
                    $v2Profile=ArtistProfile::findForUser((int)$v2Artwork['user_id']);
                    $v2Generated=(new ArtworkAnalysisV2Service(new GeminiImageClient()))->generateDraft($v2Artwork,$v2Profile,RESULTS_DIR.DIRECTORY_SEPARATOR.$selectedRootFile,(string)($diskStatus['artist_notes']??'Automatic v2 analysis for new artwork.'));
                    (new ArtworkSheetService(Database::connection()))->applyAnalysisV2Draft(
                        (int)$v2Artwork['id'],
                        (int)$v2Artwork['user_id'],
                        (array)$v2Generated['draft']
                    );
                    $diskStatus['artwork_analysis_v2']='draft_ready';write_status_file($statusFile,$diskStatus);
                }
            }catch(Throwable $v2Error){error_log('Artwork v2 analysis was not generated: '.$v2Error->getMessage());$diskStatus['artwork_analysis_v2']='error';$diskStatus['artwork_analysis_v2_error']=$v2Error->getMessage();write_status_file($statusFile,$diskStatus);}
        }

        $metaName = pathinfo($selectedRootFile, PATHINFO_FILENAME) . '.meta.json';
        $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $metaName;
        file_put_contents(
            $metaPath,
            json_encode([
                'source_job_id' => $jobId,
                'user_id' => (int)($diskStatus['user_id'] ?? 0),
                'root_file' => $selectedRootFile,
                'measurements' => $diskStatus['measurements'] ?? [],
                'artist_notes' => $diskStatus['artist_notes'] ?? '',
                'provider_settings' => $diskStatus['provider_settings'] ?? ProviderSettings::all(),
                'generation_provider' => $generationProvider,
                'scale_text' => build_scale_text_for_meta((array)($diskStatus['measurements'] ?? [])),
                'root_source' => 'auto_selected_user_scene_flow',
                'user_scene_flow' => true,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        if (StorageService::isGcsActive()) {
            StorageService::uploadFile('results/' . basename($metaName), $metaPath);
        }
        $diskStatus['status'] = 'done';
        $diskStatus['message'] = 'Root image and artwork analysis are ready. Creating scenes next.';
        write_status_file($statusFile, $diskStatus);
    } else {
        $diskStatus['status'] = 'done';
        $diskStatus['message'] = 'Root image candidates created. Awaiting user selection.';
        $diskStatus['candidates'] = $candidateFiles;
        $diskStatus['result_file'] = null; // No selected root image yet
        write_status_file($statusFile, $diskStatus);

        // Update SQLite database status of artwork to awaiting_selection
        update_artwork_record($diskStatus, 'awaiting_selection', null);
    }

    NextPlatformSync::run();
    exit('DONE candidate generation for job ' . $jobId . "\n");
} catch (Throwable $e) {
    update_job_status($statusFile, 'error', 'Generation error.', null, $e->getMessage());
    exit($e->getMessage() . "\n");
}

function build_scale_text_for_meta(array $measurements): string
{
    $width = trim((string)($measurements['width'] ?? ''));
    $height = trim((string)($measurements['height'] ?? ''));
    $depth = trim((string)($measurements['depth'] ?? ''));
    $unit = trim((string)($measurements['unit'] ?? 'cm'));

    if ($width === '' || $height === '') {
        return 'No physical artwork size was provided. Keep scale plausible for the visible artwork proportions.';
    }

    $text = "The real physical artwork measures {$width} {$unit} wide x {$height} {$unit} high.";
    $text .= " These measurements refer only to the artwork, not to the photo, wall, furniture, background or surrounding objects.";
    $text .= " In mockups, scale the artwork realistically relative to architecture, furniture and human figures.";

    if ($depth !== '') {
        $text .= " Physical stretcher/support depth: {$depth} {$unit}.";
    }

    return $text;
}
