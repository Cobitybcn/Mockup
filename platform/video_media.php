<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::VIDEO_MANAGE, 'Videos');
$userId = (int)$user['id'];
$pdo = Database::connection();
$key = '';
$downloadName = 'video.mp4';

function video_download_slug(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) $value = (string)(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
    $value = strtolower((string)(preg_replace('/[^a-zA-Z0-9]+/', '-', $value) ?: ''));
    return trim($value, '-') ?: 'video';
}

if ((int)($_GET['generation_id'] ?? 0) > 0) {
    $jobId = (int)$_GET['generation_id'];
    $column = isset($_GET['thumbnail']) ? 'thumbnail_path' : 'output_path';
    $stmt = $pdo->prepare("SELECT j.{$column} AS object_path,j.video_scene_id,p.title AS project_title,s.title AS scene_title,
            (SELECT COUNT(*) FROM video_generation_jobs jv WHERE jv.video_scene_id=j.video_scene_id AND jv.status='succeeded' AND jv.id<=j.id) AS generation_version
        FROM video_generation_jobs j
        INNER JOIN video_projects p ON p.id=j.video_project_id AND p.user_id=j.user_id
        LEFT JOIN video_scenes s ON s.id=j.video_scene_id
        WHERE j.id=? AND j.user_id=? AND j.status='succeeded' LIMIT 1");
    $stmt->execute([$jobId,$userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $key = (string)$row['object_path'];
        $baseName = video_download_slug((string)$row['project_title'] . '-' . (string)($row['scene_title'] ?? 'secuencia'))
            . '-v' . max(1, (int)($row['generation_version'] ?? 1));
        $downloadName = $baseName . (isset($_GET['thumbnail']) ? '.jpg' : '.mp4');
    }
} elseif ((int)($_GET['export_id'] ?? 0) > 0) {
    $exportId = (int)$_GET['export_id'];
    $stmt = $pdo->prepare("SELECT e.output_path,e.timeline_snapshot_json,e.video_project_id,p.title AS project_title FROM video_exports e
        INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
        WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1");
    $stmt->execute([$exportId,$userId]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $snapshot = json_decode((string)$row['timeline_snapshot_json'], true);
        if (!is_array($snapshot)) $snapshot = [];
        $key = isset($_GET['thumbnail']) ? (string)($snapshot['thumbnailPath'] ?? '') : (string)$row['output_path'];
        $extension = isset($_GET['thumbnail']) ? 'jpg' : (strtolower(pathinfo($key, PATHINFO_EXTENSION)) ?: 'mp4');
        $downloadBase = video_download_slug((string)$row['project_title']);
        foreach ((new VideoStudioRepository($pdo))->finalVideos($userId) as $final) {
            if ((int)$final['id'] !== $exportId) continue;
            $downloadBase = (string)($final['seoFileBase'] ?? $downloadBase);
            break;
        }
        $downloadName = $downloadBase . '.' . $extension;
    }
}

if ($key === '') {
    http_response_code(404);
    exit('Video media not found.');
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

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
    exit('Video media file is unavailable.');
}

$size = filesize($path);
if ($size === false || $size <= 0) {
    http_response_code(404);
    exit('Video media file is empty.');
}
$mime = @mime_content_type($path) ?: (str_ends_with(strtolower($path), '.jpg') ? 'image/jpeg' : 'video/mp4');
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
