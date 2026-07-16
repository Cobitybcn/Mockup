<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$assetId = (int)($_GET['asset_id'] ?? 0);
$repository = new VideoStudioRepository(Database::connection());
$asset = $repository->findReferenceAsset($userId, $assetId);
if (!is_array($asset)) {
    http_response_code(404);
    exit('Reference media not found.');
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$key = (string)$asset['file_path'];
if (StorageService::isGcsActive()) {
    $signed = StorageService::getSignedUrl($key, 10);
    if ($signed && preg_match('#^https://#i', $signed)) {
        header('Location: ' . $signed, true, 302);
        exit;
    }
}

$storage = new VideoMediaStorage();
$path = $storage->localObjectPath($key);
if (!is_file($path)) {
    VideoFfmpeg::ensureDirectory(dirname($path));
    StorageService::downloadFile($key, $path);
}
$size = is_file($path) ? filesize($path) : false;
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit('Reference media is unavailable.');
}

$start = 0;
$end = $size - 1;
$status = 200;
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches)) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    if ($matches[1] === '' && $matches[2] !== '') {
        $suffix = min($size, (int)$matches[2]);
        $start = $size - $suffix;
    } else {
        $start = (int)$matches[1];
        if ($matches[2] !== '') $end = min($end, (int)$matches[2]);
    }
    if ($start < 0 || $start > $end || $start >= $size) {
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }
    $status = 206;
}

$mime = (string)$asset['mime_type'];
$downloadName = basename(str_replace('\\', '/', (string)$asset['original_name'])) ?: 'referencia';
http_response_code($status);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . addslashes($downloadName) . '"');
if ($status === 206) header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);

$handle = fopen($path, 'rb');
if ($handle === false) exit;
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
}
fclose($handle);
