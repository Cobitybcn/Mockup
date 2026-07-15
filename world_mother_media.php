<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireUser();

$file = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));
$file = ltrim($file, '/');
$thumbWidth = isset($_GET['thumb']) ? max(240, min(1200, (int)($_GET['w'] ?? 640))) : 0;
$allowedPrefixes = ['storage/world_mothers/', 'storage/world_mother_uploads/'];
$prefix = '';
foreach ($allowedPrefixes as $allowedPrefix) {
    $prefixPos = strpos($file, $allowedPrefix);
    if ($prefixPos !== false) {
        $prefix = $allowedPrefix;
        $file = substr($file, $prefixPos);
        break;
    }
}

$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

if (
    $prefix === ''
    || !str_starts_with($file, $prefix)
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

$baseDirectory = $prefix === 'storage/world_mother_uploads/' ? 'world_mother_uploads' : 'world_mothers';
$basePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $baseDirectory);
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

if ($thumbWidth > 0) {
    $thumbPath = world_mother_thumbnail_path($file, $thumbWidth);
    $thumbKey = world_mother_thumbnail_key($file, $thumbWidth);

    if (!is_file($thumbPath) && StorageService::isGcsActive()) {
        StorageService::downloadFile($thumbKey, $thumbPath);
    }

    if (!is_file($thumbPath)) {
        world_mother_create_thumbnail($path, $thumbPath, $thumbWidth);
        if (is_file($thumbPath) && StorageService::isGcsActive()) {
            StorageService::uploadFile($thumbKey, $thumbPath);
        }
    }

    if (is_file($thumbPath)) {
        $path = $thumbPath;
        $mime = @mime_content_type($path) ?: $mime;
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=' . ($thumbWidth > 0 ? '86400' : '3600'));
header('Content-Disposition: inline; filename="' . addslashes(basename($path)) . '"');

readfile($path);
exit;

function world_mother_thumbnail_path(string $file, int $width): string
{
    $baseName = pathinfo(basename($file), PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $baseName) ?: 'image';
    $pathHash = substr(hash('sha256', str_replace('\\', '/', $file)), 0, 12);
    $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
    return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'world_mothers' . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . $width . DIRECTORY_SEPARATOR . $safeBase . '-' . $pathHash . '.' . $extension;
}

function world_mother_thumbnail_key(string $file, int $width): string
{
    return 'storage/world_mothers/thumbnails/' . $width . '/' . basename(world_mother_thumbnail_path($file, $width));
}

function world_mother_create_thumbnail(string $sourcePath, string $thumbPath, int $targetWidth): bool
{
    if (!is_file($sourcePath)) {
        return false;
    }

    $info = @getimagesize($sourcePath);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }

    $sourceWidth = (int)$info[0];
    $sourceHeight = (int)$info[1];
    $mime = (string)($info['mime'] ?? '');
    $targetWidth = min($targetWidth, $sourceWidth);
    $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));

    $source = match ($mime) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
        'image/png' => @imagecreatefrompng($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
        default => @imagecreatefromstring((string)@file_get_contents($sourcePath)),
    };
    if (!$source) {
        return false;
    }

    $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

    $dir = dirname($thumbPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $saved = function_exists('imagewebp')
        ? @imagewebp($thumb, $thumbPath, 78)
        : @imagejpeg($thumb, $thumbPath, 82);

    imagedestroy($source);
    imagedestroy($thumb);

    return (bool)$saved;
}
