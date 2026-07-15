<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (app_env('META_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true'
    && app_env('INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true') {
    http_response_code(404);
    exit;
}
$token = trim((string)($_GET['token'] ?? ''));
if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
    http_response_code(404);
    exit;
}
$stmt = Database::connection()->prepare(
    "SELECT variant_file,media_expires_at FROM social_channel_drafts
     WHERE media_token=? AND status IN ('draft','failed','publishing','published') LIMIT 1"
);
$stmt->execute([$token]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$expiresAt = is_array($row) ? strtotime((string)($row['media_expires_at'] ?? '')) : false;
if (!is_array($row) || $expiresAt === false || $expiresAt <= time()) {
    http_response_code(404);
    exit;
}
$file = basename((string)($row['variant_file'] ?? ''));
if ($file === '') {
    http_response_code(404);
    exit;
}
$path = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'meta_drafts' . DIRECTORY_SEPARATOR . $file;
if (!is_file($path) && StorageService::isGcsActive()) {
    StorageService::downloadFile('meta-drafts/' . $file, $path);
}
if (!is_file($path) || (string)mime_content_type($path) !== 'image/jpeg') {
    http_response_code(404);
    exit;
}
$maxAge = max(0, min(3600, $expiresAt - time()));
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=' . $maxAge);
header('X-Content-Type-Options: nosniff');
readfile($path);
