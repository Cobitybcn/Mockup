<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));
$pdo = Database::connection();

$stmt = $pdo->prepare('SELECT * FROM artwork_series WHERE slug = ? AND published = 1 LIMIT 1');
$stmt->execute([$slug]);
$series = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$series) {
    http_response_code(404);
    exit;
}

if ($file === '' || $file !== basename((string)$series['header_file'])) {
    http_response_code(403);
    exit;
}

$path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
if (!is_file($path)) {
    $upload = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $file;
    if (is_file($upload)) $path = $upload;
}
if (!is_file($path)) {
    $seriesUpload = ArtworkSeries::headerUploadDir((int)$series['user_id']) . DIRECTORY_SEPARATOR . $file;
    if (is_file($seriesUpload)) $path = $seriesUpload;
}
if (!is_file($path)) {
    $seriesHeadersRoot = dirname(ArtworkSeries::headerUploadDir((int)$series['user_id']));
    $legacyHeaderPaths = glob($seriesHeadersRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . $file) ?: [];
    foreach ($legacyHeaderPaths as $legacyHeaderPath) {
        if (is_file($legacyHeaderPath)) {
            $path = $legacyHeaderPath;
            break;
        }
    }
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
