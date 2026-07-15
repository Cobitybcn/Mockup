<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$file = basename((string)($_GET['file'] ?? ''));

if ($file === '' || !preg_match('/\.(jpe?g|png)$/i', $file) || !str_contains(strtolower($file), 'mockup')) {
    http_response_code(404);
    exit;
}

if (str_contains(strtolower($file), '.original.')) {
    http_response_code(404);
    exit;
}

$path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;

if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = @mime_content_type($path);
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
readfile($path);
