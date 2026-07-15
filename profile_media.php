<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$file = basename((string)($_GET['file'] ?? ''));
$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT photo_file, user_id FROM artist_profiles WHERE photo_file = ? LIMIT 1');
$stmt->execute([$file]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'artist_profiles' . DIRECTORY_SEPARATOR . $file;
if (!is_file($path) && StorageService::isGcsActive()) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    StorageService::downloadFile('uploads/artist_profiles/' . $file, $path);
}
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$mime = @mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
