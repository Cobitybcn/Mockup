<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$assetId = max(0, (int)($_GET['id'] ?? 0));
$asset = (new ReferenceAssetService(Database::connection()))->findForUser((int)$user['id'], $assetId);
if (!$asset) {
    http_response_code(404);
    exit('Reference not found.');
}

$service = new ReferenceAssetService(Database::connection());
$sourcePath = $service->ensureLocalPath($asset);
if ($sourcePath === '' || !is_file($sourcePath)) {
    http_response_code(404);
    exit('Reference image not found.');
}

$servePath = $sourcePath;
$serveMime = (string)$asset['mime_type'];
$makeThumbnail = !empty($_GET['thumb']) && function_exists('imagecreatetruecolor');
if ($makeThumbnail) {
    $width = max(160, min(1200, (int)($_GET['w'] ?? 640)));
    $cacheDir = __DIR__ . '/storage/visual_dna_thumbs/' . (int)$user['id'];
    $cachePath = $cacheDir . '/' . $assetId . '_' . $width . '.jpg';
    if (!is_file($cachePath) || filemtime($cachePath) < filemtime($sourcePath)) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $info = @getimagesize($sourcePath);
        $sourceWidth = (int)($info[0] ?? 0);
        $sourceHeight = (int)($info[1] ?? 0);
        $source = match ((string)($info['mime'] ?? '')) {
            'image/jpeg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };
        if ($source !== false && $sourceWidth > 0 && $sourceHeight > 0) {
            $targetWidth = min($width, $sourceWidth);
            $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $background = imagecolorallocate($target, 248, 246, 242);
            imagefill($target, 0, 0, $background);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            imagejpeg($target, $cachePath, 86);
            imagedestroy($target);
            imagedestroy($source);
        }
    }
    if (is_file($cachePath)) {
        $servePath = $cachePath;
        $serveMime = 'image/jpeg';
    }
}

header('Content-Type: ' . $serveMime);
header('Content-Length: ' . (string)filesize($servePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($servePath);
