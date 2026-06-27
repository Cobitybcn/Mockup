<?php
declare(strict_types=1);

class Display
{
    public static function artworkTitle(?string $file, ?string $fallback = null): string
    {
        $base = pathinfo(basename((string)$file), PATHINFO_FILENAME);

        if ($base === '') {
            return $fallback ?: 'Root Art';
        }

        $base = preg_replace('/^base_artwork_(?:gemini|ai|safe|mock)_?/i', 'root_art_', $base);
        $base = preg_replace('/^base_artwork_?/i', 'root_art_', (string)$base);
        $base = preg_replace('/_(?:gemini|openai|ai|mock|safe)(?=_[0-9]|$)/i', '', (string)$base);
        $base = preg_replace('/_+/', '_', (string)$base);
        $base = trim((string)$base, '_');

        if (!str_starts_with(strtolower($base), 'root_art')) {
            $base = 'root_art_' . $base;
        }

        return $base !== '' ? $base : ($fallback ?: 'Root Art');
    }

    public static function contextTitle(?string $contextId): string
    {
        $text = trim((string)$contextId);

        if ($text === '') {
            return 'Mockup';
        }

        $text = str_replace(['-', '_'], ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return mb_convert_case((string)$text, MB_CASE_TITLE, 'UTF-8');
    }

    public static function slugify(string $value): string
    {
        $value = str_replace(
            ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value
        );
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9\s\-]/', '', $value);
        $value = preg_replace('/[\s_]+/', '-', $value);
        $value = preg_replace('/\-+/', '-', $value);
        return trim($value, '-');
    }

    public static function generateSeoImageFilename(array $params, ?string $directory = null): string
    {
        $artistName = trim((string)($params['artistName'] ?? ''));
        $artworkTitle = trim((string)($params['artworkTitle'] ?? ''));
        $mockupContext = trim((string)($params['mockupContext'] ?? ''));
        $cameraAngle = trim((string)($params['cameraAngle'] ?? ''));
        $imageType = trim((string)($params['imageType'] ?? 'mockup'));
        $extension = trim((string)($params['extension'] ?? 'jpg'), '.');

        $parts = [];
        if ($artistName !== '') {
            $parts[] = self::slugify($artistName);
        }
        if ($artworkTitle !== '') {
            $parts[] = self::slugify($artworkTitle);
        }
        if ($mockupContext !== '') {
            $parts[] = self::slugify($mockupContext);
        }

        // Normalize camera angle/group for SEO filenames.
        $angle = strtolower(trim($cameraAngle));
        if (stripos($angle, 'front') !== false) {
            $angle = 'frontal';
        } elseif (stripos($angle, 'left') !== false) {
            $angle = '3-4-left';
        } elseif (stripos($angle, 'right') !== false) {
            $angle = '3-4-right';
        } elseif (stripos($angle, 'high') !== false || stripos($angle, 'elevated') !== false || stripos($angle, 'aerial') !== false) {
            $angle = 'high-angle-aerial';
        } elseif (stripos($angle, 'low floor') !== false || stripos($angle, 'floor level') !== false || stripos($angle, '7/8') !== false) {
            $angle = 'low-floor-wide-low-angle';
        } elseif (stripos($angle, 'low-angle') !== false || stripos($angle, 'contrapicado') !== false || stripos($angle, 'low angle') !== false) {
            $angle = 'low-angle-contrapicado';
        } else {
            $angle = '';
        }

        if ($angle !== '') {
            $parts[] = $angle;
        }

        if ($imageType !== '') {
            $parts[] = self::slugify($imageType);
        }

        $filename = implode('-', $parts);

        // Limit to 110 characters before suffix and extension
        if (strlen($filename) > 110) {
            $filename = substr($filename, 0, 110);
            $filename = rtrim($filename, '-');
        }

        if ($filename === '') {
            $filename = 'image';
        }

        $dir = $directory ?: (defined('RESULTS_DIR') ? RESULTS_DIR : __DIR__ . '/../../results');

        // Check for duplicates
        $baseFilename = $filename;
        $checkPath = $dir . DIRECTORY_SEPARATOR . $baseFilename . '.' . $extension;
        if (file_exists($checkPath)) {
            $counter = 2;
            while (file_exists($dir . DIRECTORY_SEPARATOR . $baseFilename . '-' . sprintf('%02d', $counter) . '.' . $extension)) {
                $counter++;
            }
            $filename = $baseFilename . '-' . sprintf('%02d', $counter);
        }

        return $filename . '.' . $extension;
    }

    public static function convertPngToJpg(string $pngData): string
    {
        if (!function_exists('imagecreatefromstring')) {
            return $pngData; // GD not loaded, fallback
        }
        $image = @imagecreatefromstring($pngData);
        if ($image !== false) {
            ob_start();
            imagejpeg($image, null, 92); // 92% quality
            $jpgData = ob_get_clean();
            imagedestroy($image);
            if ($jpgData !== false && $jpgData !== '') {
                return $jpgData;
            }
        }
        return $pngData;
    }
}

if (!function_exists('generateSeoImageFilename')) {
    function generateSeoImageFilename(array $params, ?string $directory = null): string
    {
        return Display::generateSeoImageFilename($params, $directory);
    }
}
