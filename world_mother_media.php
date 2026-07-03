<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireUser();

$file = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));
$file = ltrim($file, '/');
$parts = explode('/', $file);
$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (
    count($parts) !== 4
    || $parts[0] !== 'storage'
    || $parts[1] !== 'world_mothers'
    || $parts[2] === ''
    || $parts[2] !== basename($parts[2])
    || $parts[2] === '.'
    || $parts[2] === '..'
    || preg_match('/[\x00-\x1F\x7F]/', $parts[2]) === 1
    || !in_array($extension, WorldMotherLibrary::allowedExtensions(), true)
    || $parts[3] !== basename($file)
    || $parts[3] === '.'
    || $parts[3] === '..'
    || preg_match('/[\x00-\x1F\x7F]/', $parts[3]) === 1
) {
    http_response_code(404);
    exit('File not found.');
}

$basePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'world_mothers');
$path = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file));

if ($basePath === false || $path === false || !str_starts_with($path, $basePath . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = @mime_content_type($path) ?: '';
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(404);
    exit('File not found.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . addslashes(basename($path)) . '"');

readfile($path);
exit;
