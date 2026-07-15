<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$noteId = max(0, (int)($_GET['note'] ?? 0));
$file = basename((string)($_GET['file'] ?? ''));
if ($noteId <= 0 || $file === '') {
    http_response_code(400);
    exit;
}

$pdo = Database::connection();
$stmt = $pdo->prepare("SELECT user_id,payload_json FROM social_campaigns WHERE id=? AND status='published' LIMIT 1");
$stmt->execute([$noteId]);
$note = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$note) {
    http_response_code(404);
    exit;
}
$payload = json_decode((string)$note['payload_json'], true);
if (!is_array($payload) || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) {
    http_response_code(404);
    exit;
}

$allowed = [];
foreach ((array)($payload['media'] ?? []) as $media) {
    if (is_array($media)) $allowed[] = basename((string)($media['file'] ?? ''));
}
if (!$allowed) {
    $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
    if ($mockupIds) {
        $marks = implode(',', array_fill(0, count($mockupIds), '?'));
        $mockups = $pdo->prepare("SELECT mockup_file FROM mockups WHERE user_id=? AND id IN ($marks)");
        $mockups->execute(array_merge([(int)$note['user_id']], $mockupIds));
        $allowed = array_map('basename', $mockups->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
}
if (!in_array($file, array_values(array_filter($allowed)), true)) {
    http_response_code(403);
    exit;
}

$path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    $seriesPath = ArtworkSeries::headerUploadDir((int)$note['user_id']) . DIRECTORY_SEPARATOR . $file;
    if (is_file($seriesPath)) $path = $seriesPath;
}
if (!is_file($path) && StorageService::isGcsActive()) {
    StorageService::downloadFile('results/' . $file, $path);
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
