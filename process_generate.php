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
        'Creando imagen raiz en modo ' . ServiceFactory::appMode() . ' con proveedor ' . ProviderSettings::imageProvider() . '.'
    );

    $processor = ServiceFactory::artworkProcessor();
    $result = $processor->createRootImage($jobDir, $status);

    // --- NUEVO: Ejecutar análisis de la obra de arte en segundo plano ---
    update_job_status(
        $statusFile,
        'processing',
        'Analizando la obra de arte con Inteligencia Artificial...'
    );

    $analyzer = ServiceFactory::artworkAnalyzer();
    
    // Preparar metadatos para el análisis
    $measurements = $status['measurements'] ?? [];
    $artistProfile = ArtistProfile::findForUser((int)$status['user_id']);
    
    $metadata = [
        'artist_notes' => $status['artist_notes'] ?? '',
        'region' => '',
        'artist_profile' => $artistProfile,
        'artist_profile_prompt' => ArtistProfile::forPrompt($artistProfile),
        'width_cm' => $measurements['unit'] === 'cm' ? ($measurements['width'] ?? null) : null,
        'height_cm' => $measurements['unit'] === 'cm' ? ($measurements['height'] ?? null) : null,
        'depth_cm' => $measurements['unit'] === 'cm' ? ($measurements['depth'] ?? null) : null,
    ];
    
    $analysisResponse = $analyzer->analyze($result['path'], $metadata);
    
    // Guardar el archivo JSON de análisis en /analysis
    if (!is_dir(ANALYSIS_DIR)) {
        mkdir(ANALYSIS_DIR, 0775, true);
    }
    
    $jsonName = pathinfo(basename($result['path']), PATHINFO_FILENAME) . '.analysis.json';
    file_put_contents(
        ANALYSIS_DIR . DIRECTORY_SEPARATOR . $jsonName,
        json_encode($analysisResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    if (!empty($result['mock'])) {
        $prompt = <<<TXT
MODO MOCK - SIN API

El Formulario 1 produce una imagen raiz fiel de la obra.
En este prototipo local se simula el procesamiento copiando la imagen principal subida.

Reglas de la futura generacion real:
- No calcar la obra.
- No simetrizar la composicion.
- No unificar colores artisticamente.
- No redibujar trazos.
- No embellecer la pintura como una nueva obra.
- No modificar la identidad visual del artista.
- Solo mejorar iluminacion, perspectiva, nitidez, tension del soporte y visibilidad de materialidad.
TXT;

        file_put_contents($jobDir . '/prompt.txt', $prompt);
        file_put_contents($jobDir . '/target_size.txt', 'mock');
    }

    update_job_status(
        $statusFile,
        'done',
        $result['message'],
        $result['file'],
        null
    );

    if (!empty($result['file'])) {
        $metaName = pathinfo((string)$result['file'], PATHINFO_FILENAME) . '.meta.json';
        $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $metaName;
        $measurements = $status['measurements'] ?? [];

        file_put_contents(
            $metaPath,
            json_encode([
                'source_job_id' => $jobId,
                'user_id' => (int)($status['user_id'] ?? 0),
                'root_file' => $result['file'],
                'measurements' => $measurements,
                'artist_notes' => $status['artist_notes'] ?? '',
                'provider_settings' => ProviderSettings::all(),
                'scale_text' => build_scale_text_for_meta($measurements),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    exit('DONE ' . strtoupper(ServiceFactory::appMode()) . ': ' . $result['file'] . "\n");
} catch (Throwable $e) {
    update_job_status($statusFile, 'error', 'Error en modo ' . ServiceFactory::appMode() . '.', null, $e->getMessage());
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
