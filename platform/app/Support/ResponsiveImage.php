<?php
declare(strict_types=1);

final class ResponsiveImage
{
    private const WIDTHS = [480, 768, 1200];

    public static function requestedWidth(): int
    {
        $requested = max(0, (int)($_GET['w'] ?? 0));
        if ($requested <= 0) return 0;
        foreach (self::WIDTHS as $width) {
            if ($requested <= $width) return $width;
        }
        return self::WIDTHS[array_key_last(self::WIDTHS)];
    }

    public static function prepare(string $sourcePath, string $file, int $width): string
    {
        if ($width <= 0 || !is_file($sourcePath) || !preg_match('/\.(?:jpe?g|png|webp|gif)$/i', $file)) {
            return $sourcePath;
        }

        $thumbnailPath = self::path($file, $width);
        $thumbnailKey = self::key($file, $width);
        if (!is_file($thumbnailPath) && StorageService::isGcsActive()) {
            StorageService::downloadFile($thumbnailKey, $thumbnailPath);
        }
        if (!is_file($thumbnailPath) && self::create($sourcePath, $thumbnailPath, $width)
            && StorageService::isGcsActive()) {
            StorageService::uploadFile($thumbnailKey, $thumbnailPath);
        }
        return is_file($thumbnailPath) ? $thumbnailPath : $sourcePath;
    }

    private static function path(string $file, int $width): string
    {
        $base = pathinfo(basename($file), PATHINFO_FILENAME);
        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base) ?: 'image';
        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        return RESULTS_DIR . DIRECTORY_SEPARATOR . 'thumbnails' . DIRECTORY_SEPARATOR . $width
            . DIRECTORY_SEPARATOR . $safeBase . '.' . $extension;
    }

    private static function key(string $file, int $width): string
    {
        return 'thumbnails/' . $width . '/' . basename(self::path($file, $width));
    }

    private static function create(string $sourcePath, string $thumbnailPath, int $targetWidth): bool
    {
        $info = @getimagesize($sourcePath);
        if (!$info || empty($info[0]) || empty($info[1])) return false;

        $sourceWidth = (int)$info[0];
        $sourceHeight = (int)$info[1];
        $targetWidth = min($targetWidth, $sourceWidth);
        $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
        $source = match ((string)($info['mime'] ?? '')) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($sourcePath),
            'image/png' => @imagecreatefrompng($sourcePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            'image/gif' => @imagecreatefromgif($sourcePath),
            default => @imagecreatefromstring((string)@file_get_contents($sourcePath)),
        };
        if (!$source) return false;

        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        if (($info['mime'] ?? '') === 'image/png') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }
        imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );
        $directory = dirname($thumbnailPath);
        if (!is_dir($directory)) @mkdir($directory, 0775, true);
        $saved = function_exists('imagewebp')
            ? @imagewebp($thumbnail, $thumbnailPath, 80)
            : @imagejpeg($thumbnail, $thumbnailPath, 84);
        imagedestroy($source);
        imagedestroy($thumbnail);
        return (bool)$saved;
    }
}
