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

if (empty($_FILES['main_artwork']) || $_FILES['main_artwork']['error'] !== UPLOAD_ERR_OK) {
    fail('No se recibió la imagen principal correctamente.');
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
    fail('No se pudo crear la carpeta del trabajo.');
}

/* =========================
   GUARDAR IMAGEN PRINCIPAL
========================= */

$mainExt = safe_ext($_FILES['main_artwork']['name']);
$mainInputFile = $jobDir . '/main_artwork.' . $mainExt;

if (!save_uploaded_file($_FILES['main_artwork'], $mainInputFile)) {
    fail('No se pudo guardar la imagen principal.');
}

/* =========================
   GUARDAR IMÁGENES ADICIONALES
========================= */

$extraFiles = [];

/* =========================
   DATOS DEL FORMULARIO
========================= */

$width  = trim((string)($_POST['width'] ?? ''));
$height = trim((string)($_POST['height'] ?? ''));
$depth  = trim((string)($_POST['depth'] ?? ''));
$unit   = trim((string)($_POST['unit'] ?? 'cm'));

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
    'message' => 'Trabajo creado. Preparando generación.',
    'main_file' => basename($mainInputFile),
    'extra_files' => $extraFiles,
    'result_file' => null,
    'error' => null,
    'measurements' => [
        'width' => $width,
        'height' => $height,
        'depth' => $depth,
        'unit' => $unit,
    ],
    'artist_notes' => $artistNotes,
    'provider_settings' => $providerSettings,
    'user_id' => (int)$currentUser['id'],
];

file_put_contents(
    $jobDir . '/status.json',
    json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

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

header('Location: waiting.php?job=' . urlencode($jobId));

$phpPath = PHP_BINARY ?: 'php';

// Si es httpd.exe o apache (módulo de Apache), no es el ejecutable de PHP y debemos buscarlo
if (str_contains(strtolower($phpPath), 'httpd') || str_contains(strtolower($phpPath), 'apache')) {
    $phpPath = 'php';
}

$phpPath = str_replace(['php-cgi.exe', 'php-cgi'], ['php.exe', 'php'], $phpPath);

if ($phpPath === 'php' || $phpPath === 'php.exe') {
    $candidates = [
        'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
        'C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php-win.exe',
    ];
    $found = false;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $phpPath = $candidate;
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $baseDir = 'C:\laragon\bin\php';
        if (is_dir($baseDir)) {
            $folders = scandir($baseDir);
            foreach ($folders as $folder) {
                if ($folder === '.' || $folder === '..') {
                    continue;
                }
                $exe = $baseDir . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'php.exe';
                if (is_file($exe)) {
                    $phpPath = $exe;
                    break;
                }
            }
        }
    }
}

// Liberar el bloqueo de sesión para evitar que el proceso hijo herede el bloqueo y congele Laragon
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Ejecutar en segundo plano desacoplado usando WMIC (evita heredar handles de Apache y colapsar FastCGI)
$innerCmd = sprintf(
    'cmd.exe /c %s %s %s > %s 2> %s',
    $phpPath,
    __DIR__ . '/process_generate.php',
    $jobId,
    $jobDir . '/process_out.log',
    $jobDir . '/process_err.log'
);

$cmd = sprintf(
    'wmic process call create "%s"',
    $innerCmd
);

pclose(popen($cmd, "r"));
exit;

