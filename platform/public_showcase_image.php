<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

$mockupId = max(0, (int)($_GET['mockup_id'] ?? 0));
$path = PublicArtistShowcase::publicFile(Database::connection(), $mockupId);
if ($path === '') {
    http_response_code(404);
    header('Cache-Control: no-store');
    exit;
}

$resultsRoot = realpath(RESULTS_DIR);
$realPath = realpath($path);
if ($resultsRoot === false || $realPath === false || !str_starts_with($realPath, $resultsRoot . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit;
}

$mime = (string)(mime_content_type($realPath) ?: '');
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit;
}

$size = (int)filesize($realPath);
$modified = (int)filemtime($realPath);
$etag = '"' . hash('sha256', basename($realPath) . '|' . $size . '|' . $modified) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
header('Content-Disposition: inline; filename="showcase-' . $mockupId . '.' . pathinfo($realPath, PATHINFO_EXTENSION) . '"');
readfile($realPath);
