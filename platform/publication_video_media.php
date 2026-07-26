<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
if ($slug === '') {
    http_response_code(404);
    exit;
}

$pdo = Database::connection();
try {
    $publication = (new PublicationService($pdo))->publicBySlug($slug);
    $stmt = $pdo->prepare("SELECT e.output_path,e.timeline_snapshot_json
        FROM artwork_video_publications avp
        INNER JOIN artwork_sheets sh ON sh.canonical_artwork_id=avp.artwork_id AND sh.user_id=avp.user_id
        INNER JOIN publications p ON p.artwork_sheet_id=sh.id AND p.user_id=avp.user_id
            AND p.slug=? AND p.status='published' AND p.visibility IN ('public','unlisted')
        INNER JOIN video_exports e ON e.id=avp.video_export_id AND e.user_id=avp.user_id
            AND e.status='succeeded' AND e.output_path<>''
        WHERE avp.user_id=? AND sh.id=?
        ORDER BY avp.id DESC LIMIT 1");
    $stmt->execute([$slug, (int)$publication['user_id'], (int)$publication['artwork_sheet_id']]);
    $video = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($video)) throw new RuntimeException('Video no encontrado.');
} catch (Throwable) {
    http_response_code(404);
    exit;
}

$snapshot = json_decode((string)$video['timeline_snapshot_json'], true);
if (!is_array($snapshot)) $snapshot = [];
$poster = isset($_GET['poster']);
$key = $poster ? trim((string)($snapshot['thumbnailPath'] ?? '')) : trim((string)$video['output_path']);
if ($key === '') {
    http_response_code(404);
    exit;
}

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
if (!is_file($path)) {
    http_response_code(404);
    exit;
}

$size = filesize($path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit;
}
$mime = @mime_content_type($path) ?: ($poster ? 'image/jpeg' : 'video/mp4');
$range = VideoHttp::mediaByteRange($size, trim((string)($_SERVER['HTTP_RANGE'] ?? '')));
if ($range === null) {
    header('Content-Range: bytes */' . $size);
    http_response_code(416);
    exit;
}

http_response_code($range['status']);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Length: ' . ($range['end'] - $range['start'] + 1));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
if ($range['status'] === 206) {
    header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size);
}

$handle = fopen($path, 'rb');
if ($handle === false) exit;
fseek($handle, $range['start']);
$remaining = $range['end'] - $range['start'] + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
}
fclose($handle);
