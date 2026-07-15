<?php
declare(strict_types=1);

final class ManualArtworkFrameCropper
{
    public static function cropIfAvailable(string $sourcePath, array $status, string $jobDir): string
    {
        $frame = (array)($status['measurements']['frame'] ?? []);
        $x = self::percent($frame['x'] ?? null);
        $y = self::percent($frame['y'] ?? null);
        $w = self::percent($frame['w'] ?? null);
        $h = self::percent($frame['h'] ?? null);

        if ($x === null || $y === null || $w === null || $h === null || $w < 10.0 || $h < 10.0) {
            return $sourcePath;
        }
        if (!extension_loaded('gd')) {
            return $sourcePath;
        }

        $image = self::load($sourcePath);
        if (!$image) {
            return $sourcePath;
        }

        imagepalettetotruecolor($image);
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        if ($sourceWidth < 20 || $sourceHeight < 20) {
            imagedestroy($image);
            return $sourcePath;
        }

        $left = max(0, min($sourceWidth - 1, (int)round($sourceWidth * $x / 100)));
        $top = max(0, min($sourceHeight - 1, (int)round($sourceHeight * $y / 100)));
        $cropWidth = max(1, min($sourceWidth - $left, (int)round($sourceWidth * $w / 100)));
        $cropHeight = max(1, min($sourceHeight - $top, (int)round($sourceHeight * $h / 100)));

        if ($cropWidth < 20 || $cropHeight < 20) {
            imagedestroy($image);
            return $sourcePath;
        }

        $cropped = imagecrop($image, [
            'x' => $left,
            'y' => $top,
            'width' => $cropWidth,
            'height' => $cropHeight,
        ]);
        imagedestroy($image);

        if (!$cropped) {
            return $sourcePath;
        }

        $targetDir = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . 'prepared_inputs';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . 'manual_artwork_frame.jpg';
        imagejpeg($cropped, $targetPath, 92);
        imagedestroy($cropped);

        return is_file($targetPath) ? $targetPath : $sourcePath;
    }

    private static function percent(mixed $value): ?float
    {
        $normalized = trim(str_replace(',', '.', (string)$value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return max(0.0, min(100.0, (float)$normalized));
    }

    private static function load(string $path): mixed
    {
        $mime = @mime_content_type($path);
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }
}
