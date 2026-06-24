<?php
declare(strict_types=1);

ini_set('max_execution_time', '120');
ini_set('max_input_time', '120');
ini_set('memory_limit', '512M');
ini_set('display_errors', '1');
ini_set('log_errors', '1');

set_time_limit(300);
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

if (!is_dir($jobDir) || !is_file($statusFile)) {
    exit("No existe el trabajo\n");
}

// Configurar el log de errores de PHP en el directorio del trabajo
ini_set('error_log', $jobDir . '/process_err.log');

if (PHP_SAPI !== 'cli') {
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

    update_job_status(
        $statusFile,
        'processing',
        'Creating root image candidates...'
    );

    $processor = ServiceFactory::artworkProcessor();
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

    $diskStatus['status'] = 'done';
    $diskStatus['message'] = 'Root image candidates created. Awaiting user selection.';
    $diskStatus['candidates'] = $result['files'];
    $diskStatus['result_file'] = null; // No selected root image yet
    write_status_file($statusFile, $diskStatus);

    // Update SQLite database status of artwork to awaiting_selection
    update_artwork_record($diskStatus, 'awaiting_selection', null);

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
