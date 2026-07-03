<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('Admin only.');
}

$file = basename(str_replace('\\', '/', trim((string)($_GET['file'] ?? ''))));
if ($file === '') {
    http_response_code(400);
    exit('Missing file.');
}

$candidates = [
    RESULTS_DIR . DIRECTORY_SEPARATOR . $file,
    __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file,
];

$path = '';
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === '') {
    http_response_code(404);
    exit('File not found.');
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';
if (!str_starts_with($mime, 'image/')) {
    http_response_code(415);
    exit('Unsupported media type.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
