<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/Video/VideoHttp.php';
require_once __DIR__ . '/app/Video/VideoMediaStorage.php';

if (app_env('TIKTOK_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
    http_response_code(404);
    exit;
}
$token = trim((string)($_GET['token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(404);
    exit;
}

$pdo = Database::connection();
$stmt = $pdo->prepare(
    "SELECT ve.output_path,d.media_expires_at
     FROM video_tiktok_publications d
     INNER JOIN video_exports ve ON ve.id=d.video_export_id
     WHERE d.media_token=? LIMIT 1"
);
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$expiresAt = is_array($row) ? strtotime((string)($row['media_expires_at'] ?? '')) : false;
if (!is_array($row) || $expiresAt === false || $expiresAt <= time()) {
    http_response_code(404);
    exit;
}

$key = trim((string)$row['output_path']);
if ($key === '') {
    http_response_code(404);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$storage = new VideoMediaStorage();
$path = $storage->localObjectPath($key);
if (!is_file($path)) {
    StorageService::downloadFile($key, $path);
}
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$size = filesize($path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit;
}
$mime = @mime_content_type($path) ?: 'video/mp4';
$range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
$resolvedRange = VideoHttp::mediaByteRange($size, $range);
if ($resolvedRange === null) {
    header('Content-Range: bytes */' . $size);
    http_response_code(416);
    exit;
}
$start = $resolvedRange['start'];
$end = $resolvedRange['end'];
$status = $resolvedRange['status'];
$maxAge = max(0, min(3600, $expiresAt - time()));

http_response_code($status);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($end - $start + 1));
header('Cache-Control: public, max-age=' . $maxAge);
header('X-Content-Type-Options: nosniff');
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
