<?php
declare(strict_types=1);

ini_set('max_execution_time', '900');
ini_set('max_input_time', '900');
ini_set('memory_limit', '768M');
ini_set('display_errors', '1');
ini_set('log_errors', '1');

set_time_limit(900);
ignore_user_abort(true);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();
$canSelectGenerationProvider = ProviderSettings::canSelectGenerationProvider(
    Auth::isAdmin($currentUser),
    (string)($_SERVER['HTTP_HOST'] ?? '')
);
$requestedGenerationProvider = strtolower(trim((string)($_POST['generation_provider'] ?? '')));
$allowedGenerationProviders = ['gemini', 'openai'];
if ($canSelectGenerationProvider && !in_array($requestedGenerationProvider, $allowedGenerationProviders, true)) {
    http_response_code(400);
    echo 'Choose Vertex or OpenAI before starting generation.';
    exit;
}
$generationProvider = $canSelectGenerationProvider
    ? ServiceFactory::generationProvider($requestedGenerationProvider)
    : ServiceFactory::generationProvider();

function fail(string $msg): void
{
    http_response_code(400);
    echo $msg;
    exit;
}

function safe_ext(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'png';
}

function save_uploaded_file(array $file, string $targetPath): bool
{
    return isset($file['tmp_name']) && is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $targetPath)
        : false;
}

function selected_provider_settings(): array
{
    return ProviderSettings::all();
}

function measurement_to_cm_string(string $value, string $unit): string
{
    $normalized = trim(str_replace(',', '.', $value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return $value;
    }

    $number = (float)$normalized;
    if (strtolower(trim($unit)) === 'in') {
        $number *= 2.54;
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

if (empty($_FILES['main_artwork']) || $_FILES['main_artwork']['error'] !== UPLOAD_ERR_OK) {
    fail('Did not receive the main artwork correctly.');
}

$tmpPath = (string)($_FILES['main_artwork']['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    fail('Uploaded file is not valid.');
}

$imageInfo = @getimagesize($tmpPath);
if ($imageInfo === false) {
    fail('The uploaded file is not a valid image.');
}

$jobsDir = __DIR__ . '/jobs';
$resultsDir = RESULTS_DIR;

if (!is_dir($jobsDir)) {
    mkdir($jobsDir, 0775, true);
}

if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0775, true);
}

$jobId = 'job_' . time() . '_' . random_int(1000, 9999);
$jobDir = $jobsDir . '/' . $jobId;

if (!mkdir($jobDir, 0775, true)) {
    fail('Could not create the job directory.');
}

/* =========================
   GUARDAR IMAGEN PRINCIPAL
========================= */

$mainExt = safe_ext($_FILES['main_artwork']['name']);
$mainInputFile = $jobDir . '/main_artwork.' . $mainExt;

if (!save_uploaded_file($_FILES['main_artwork'], $mainInputFile)) {
    fail('Could not save the main artwork.');
}

if (StorageService::isGcsActive()) {
    StorageService::uploadFile('jobs/' . $jobId . '/' . basename($mainInputFile), $mainInputFile);
}

/* =========================
   GUARDAR IMÁGENES ADICIONALES
========================= */

$extraFiles = [];

/* =========================
   DATOS DEL FORMULARIO
========================= */

$isUserSceneFlow = !empty($_POST['user_scene_flow']);
$realDimensionsEnabled = !empty($_POST['real_dimensions_enabled']);
$width  = trim((string)($_POST['width'] ?? ''));
$height = trim((string)($_POST['height'] ?? ''));
$depth  = trim((string)($_POST['depth'] ?? '3'));
$unit   = trim((string)($_POST['unit'] ?? 'cm'));

if ($isUserSceneFlow && ($width === '' || $height === '')) {
    fail('Please enter the artwork width and height.');
}

$originalMeasurements = [
    'width' => $width,
    'height' => $height,
    'depth' => $depth,
    'unit' => $unit,
];
if (strtolower($unit) === 'in') {
    $width = measurement_to_cm_string($width, $unit);
    $height = measurement_to_cm_string($height, $unit);
    $depth = measurement_to_cm_string($depth, $unit);
    $unit = 'cm';
}

$widthNumber = (float)str_replace(',', '.', $width);
$heightNumber = (float)str_replace(',', '.', $height);
if ($isUserSceneFlow && ($widthNumber <= 0 || $heightNumber <= 0)) {
    fail('Artwork width and height must be greater than zero.');
}

$artworkShape = 'square';
if ($widthNumber > $heightNumber) {
    $artworkShape = 'landscape';
} elseif ($heightNumber > $widthNumber) {
    $artworkShape = 'portrait';
}

$sceneCategory = trim(str_replace(['\\', '/'], '', (string)($_POST['scene_category'] ?? 'selected')));
$sceneBoard = max(1, min(3, (int)($_POST['scene_board'] ?? 1)));
$sceneLimit = max(1, min(4, (int)($_POST['scene_limit'] ?? 4)));

$artistNotes = '';
$providerSettings = selected_provider_settings();

/* =========================
   CREAR STATUS.JSON
========================= */

$status = [
    'ok' => true,
    'job_id' => $jobId,
    'status' => 'queued',
    'created_at' => date('c'),
    'updated_at' => date('c'),
    'message' => 'Job created. Preparing generation.',
    'main_file' => basename($mainInputFile),
    'extra_files' => $extraFiles,
    'result_file' => null,
    'error' => null,
    'measurements' => [
        'width' => $width,
        'height' => $height,
        'depth' => $depth,
        'unit' => $unit,
        'original_user_measurements' => $originalMeasurements,
        'real_dimensions_enabled' => $realDimensionsEnabled,
        'artwork_shape' => $artworkShape,
    ],
    'artist_notes' => $artistNotes,
    'provider_settings' => $providerSettings,
    'user_id' => (int)$currentUser['id'],
    'user_scene_flow' => $isUserSceneFlow,
    'generation_provider' => $generationProvider,
    'scene_category' => $sceneCategory,
    'scene_board' => $sceneBoard,
    'scene_limit' => $sceneLimit,
];

file_put_contents(
    $jobDir . '/status.json',
    json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

if (StorageService::isGcsActive()) {
    StorageService::uploadFile('jobs/' . $jobId . '/status.json', $jobDir . '/status.json');
}

$stmt = Database::connection()->prepare("
    INSERT INTO artworks (user_id, job_id, main_file, status, width, height, depth, unit, created_at, updated_at)
    VALUES (:user_id, :job_id, :main_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
");
$stmt->execute([
    'user_id' => (int)$currentUser['id'],
    'job_id' => $jobId,
    'main_file' => basename($mainInputFile),
    'status' => 'queued',
    'width' => $width,
    'height' => $height,
    'depth' => $depth,
    'unit' => $unit,
    'created_at' => $status['created_at'],
    'updated_at' => $status['updated_at'],
]);

/* =========================
   REDIRECCIÓN INMEDIATA
========================= */

if ($isUserSceneFlow) {
    header('Location: create_scenes_wait.php?job=' . urlencode($jobId));
} else {
    header('Location: waiting.php?job=' . urlencode($jobId));
}

$phpPath = PHP_BINARY ?: 'php';

// Si es httpd.exe o apache (módulo de Apache), no es el ejecutable de PHP y debemos buscarlo
if (str_contains(strtolower($phpPath), 'httpd') || str_contains(strtolower($phpPath), 'apache')) {
    $phpPath = 'php';
}

$phpPath = str_replace(['php-cgi.exe', 'php-cgi'], ['php.exe', 'php'], $phpPath);

if ($phpPath === 'php' || $phpPath === 'php.exe') {
    // Punto #2: PHP_BINARY_PATH configurado en .env tiene prioridad total
    $envPhpPath = defined('PHP_BINARY_PATH') ? trim((string)PHP_BINARY_PATH) : '';
    if ($envPhpPath !== '' && is_file($envPhpPath)) {
        $phpPath = $envPhpPath;
    } else {
        // Auto-detección: busca cualquier versión de PHP en Laragon sin hardcodear la versión exacta
        $baseDir = 'C:\\laragon\\bin\\php';
        $found = false;

        if (is_dir($baseDir)) {
            $folders = @scandir($baseDir);
            if (is_array($folders)) {
                // Ordenar descendente para preferir versiones más recientes
                rsort($folders);
                foreach ($folders as $folder) {
                    if ($folder === '.' || $folder === '..') {
                        continue;
                    }
                    $exe = $baseDir . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'php.exe';
                    if (is_file($exe)) {
                        $phpPath = $exe;
                        $found = true;
                        break;
                    }
                }
            }
        }

        // Fallback final: php del PATH del sistema
        if (!$found) {
            $phpPath = 'php';
        }
    }
}

// Liberar el bloqueo de sesión para evitar que el proceso hijo herede el bloqueo y congele Laragon
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Ejecutar en segundo plano desacoplado usando un cascade de fallbacks para Windows (evitando heredar handles y congelar Apache)
$scriptPath = __DIR__ . DIRECTORY_SEPARATOR . 'process_generate.php';
$logOut = $jobDir . DIRECTORY_SEPARATOR . 'process_out.log';
$logErr = $jobDir . DIRECTORY_SEPARATOR . 'process_err.log';

$started = false;

if (ProviderSettings::allowRealApi() && class_exists('Google\\Cloud\\Tasks\\V2\\CloudTasksClient')) {
    try {
        CloudTasksService::enqueueRootGeneration($jobId, (int)$currentUser['id']);
        file_put_contents($jobDir . '/start_method.txt', 'Cloud Tasks root_worker');
        exit;
    } catch (Throwable $e) {
        $status['status'] = 'error';
        $status['message'] = 'Could not enqueue artwork preparation.';
        $status['error'] = $e->getMessage();
        $status['updated_at'] = date('c');
        file_put_contents($jobDir . '/status.json', json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (StorageService::isGcsActive()) {
            StorageService::uploadFile('jobs/' . $jobId . '/status.json', $jobDir . '/status.json');
        }
        error_log('Could not enqueue root generation: ' . $e->getMessage());
        exit;
    }
}

// Método 0: Linux/Cloud Run - lanzar PHP CLI en segundo plano.
// En localhost Windows se salta y cae en los métodos COM/start/WMIC.
if (!$started && DIRECTORY_SEPARATOR === '/') {
    try {
        $phpCli = PHP_BINARY ?: 'php';
        $cmd = sprintf(
            'nohup %s %s %s > %s 2> %s &',
            escapeshellarg($phpCli),
            escapeshellarg($scriptPath),
            escapeshellarg($jobId),
            escapeshellarg($logOut),
            escapeshellarg($logErr)
        );
        @exec($cmd);
        $started = true;
        file_put_contents($jobDir . '/start_method.txt', 'linux nohup');
    } catch (Throwable $e) {
        // Fallback al siguiente método
    }
}

// Método 1: COM (WScript.Shell) - Excelente para Windows bajo Apache ya que no hereda handles ni bloquea FastCGI
if (class_exists('COM')) {
    try {
        $wsh = new COM("WScript.Shell");
        $command = sprintf(
            'cmd.exe /c "%s" "%s" "%s" > "%s" 2> "%s"',
            $phpPath,
            $scriptPath,
            $jobId,
            $logOut,
            $logErr
        );
        // 0 = Ocultar ventana, false = ejecutar de forma asíncrona
        $wsh->Run($command, 0, false);
        $started = true;
        file_put_contents($jobDir . '/start_method.txt', 'COM WScript.Shell');
    } catch (Throwable $e) {
        // Fallback al siguiente método
    }
}

// Método 2: popen con "start /B" (Comando nativo de Windows para ejecutar en segundo plano)
if (!$started) {
    try {
        $cmd = sprintf(
            'start /B "" "%s" "%s" "%s" > "%s" 2> "%s"',
            $phpPath,
            $scriptPath,
            $jobId,
            $logOut,
            $logErr
        );
        $handle = @popen($cmd, "r");
        if ($handle) {
            pclose($handle);
            $started = true;
            file_put_contents($jobDir . '/start_method.txt', 'start /B popen');
        }
    } catch (Throwable $e) {
        // Fallback al siguiente método
    }
}

// Método 3: WMIC (Legacy / Windows 10 y anteriores)
if (!$started) {
    try {
        $wmicEscape = function (string $val): string {
            return '\"' . str_replace('"', '\"', $val) . '\"';
        };
        $innerCmd = sprintf(
            'cmd.exe /c %s %s %s > %s 2> %s',
            $wmicEscape($phpPath),
            $wmicEscape($scriptPath),
            $wmicEscape($jobId),
            $wmicEscape($logOut),
            $wmicEscape($logErr)
        );
        $cmd = sprintf(
            'wmic process call create "%s"',
            $innerCmd
        );
        $handle = @popen($cmd, "r");
        if ($handle) {
            pclose($handle);
            $started = true;
            file_put_contents($jobDir . '/start_method.txt', 'WMIC');
        }
    } catch (Throwable $e) {
        // Fallback final
    }
}

exit;
