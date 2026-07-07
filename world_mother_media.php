<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireUser();

$file = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));
$file = ltrim($file, '/');
$prefix = 'storage/world_mothers/';
$prefixPos = strpos($file, $prefix);
if ($prefixPos !== false) {
    $file = substr($file, $prefixPos);
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (
    !str_starts_with($file, $prefix)
    || preg_match('/[\x00-\x1F\x7F]/', $file) === 1
    || str_contains($file, '/../')
    || str_contains($file, '/./')
    || str_ends_with($file, '/..')
    || str_ends_with($file, '/.')
    || !in_array($extension, WorldMotherLibrary::allowedExtensions(), true)
) {
    http_response_code(404);
    exit('File not found.');
}

$targetPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
if (!is_file($targetPath)) {
    if (StorageService::isGcsActive()) {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        StorageService::downloadFile($file, $targetPath);
    }
}

$basePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'world_mothers');
$path = realpath($targetPath);

$basePathNormalized = $basePath !== false ? rtrim(str_replace('\\', '/', $basePath), '/') . '/' : '';
$pathNormalized = $path !== false ? str_replace('\\', '/', $path) : '';

if ($basePath === false || $path === false || !str_starts_with($pathNormalized, $basePathNormalized) || !is_file($path)) {
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
