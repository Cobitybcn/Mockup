<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$noteId = max(0, (int)($_GET['note'] ?? 0));
if ($noteId <= 0) {
    http_response_code(400);
    exit;
}

$stmt = Database::connection()->prepare("SELECT objective,payload_json,updated_at FROM social_campaigns WHERE id=? AND status='published' LIMIT 1");
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

$embedded = StudioNoteEmbeddedImage::decodeFirst((string)$note['objective']);
if ($embedded === null) {
    http_response_code(415);
    exit;
}

$output = $embedded['bytes'];
$outputMime = $embedded['mime'];
$requestedWidth = ResponsiveImage::requestedWidth();
$source = function_exists('imagecreatefromstring') ? @imagecreatefromstring($output) : false;
if ($source !== false && $requestedWidth > 0) {
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min(1, $requestedWidth / max(1, $sourceWidth));
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if ($target !== false) {
        if ($outputMime !== 'image/jpeg') {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        ob_start();
        if ($outputMime === 'image/png') imagepng($target, null, 7);
        elseif ($outputMime === 'image/webp' && function_exists('imagewebp')) imagewebp($target, null, 84);
        else { $outputMime = 'image/jpeg'; imagejpeg($target, null, 88); }
        $resized = ob_get_clean();
        if (is_string($resized) && $resized !== '') $output = $resized;
        imagedestroy($target);
    }
    imagedestroy($source);
}

$etag = '"' . sha1((string)$note['updated_at'] . ':' . $noteId . ':' . $requestedWidth . ':' . strlen($output)) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $outputMime);
header('Content-Length: ' . strlen($output));
header('Cache-Control: public, max-age=604800');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
echo $output;
