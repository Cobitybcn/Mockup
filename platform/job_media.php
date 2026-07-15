<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$job = basename((string)($_GET['job'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));

if ($job === '' || $file === '') {
    http_response_code(400);
    exit('Missing job or file.');
}

$statusFile = __DIR__ . '/jobs/' . $job . '/status.json';

if (!is_file($statusFile)) {
    http_response_code(404);
    exit('Job not found.');
}

$status = json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status) || (int)($status['user_id'] ?? 0) !== (int)$user['id']) {
    http_response_code(403);
    exit('Access denied.');
}

$allowedFiles = array_merge(
    [basename((string)($status['main_file'] ?? ''))],
    array_map('basename', (array)($status['extra_files'] ?? [])),
    array_map('basename', (array)($status['candidates'] ?? []))
);

if (!in_array($file, $allowedFiles, true)) {
    http_response_code(403);
    exit('File not allowed.');
}

$candidateFiles = array_map('basename', (array)($status['candidates'] ?? []));
$path = in_array($file, $candidateFiles, true)
    ? RESULTS_DIR . DIRECTORY_SEPARATOR . $file
    : __DIR__ . '/jobs/' . $job . DIRECTORY_SEPARATOR . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . addslashes($file) . '"');

readfile($path);
exit;
