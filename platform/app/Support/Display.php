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
        $artworkTitle = trim((string)($params['artworkTitle'] ?? ''));
        $mockupContext = trim((string)($params['mockupContext'] ?? ''));
        $cameraAngle = trim((string)($params['cameraAngle'] ?? ''));
        $cameraSlotName = trim((string)($params['cameraSlotName'] ?? ''));
        $imageType = trim((string)($params['imageType'] ?? 'mockup'));
        $extension = trim((string)($params['extension'] ?? 'jpg'), '.');

        $parts = [];

        $cameraLabel = self::filenameCameraLabel($cameraSlotName, $cameraAngle);
        if ($cameraLabel !== '') {
            $parts[] = self::slugify($cameraLabel);
        }

        $mockupContext = self::mockupContextWithoutCamera($mockupContext, $cameraLabel);
        if ($mockupContext !== '') {
            $parts[] = self::slugify($mockupContext);
        }
        if ($artworkTitle !== '') {
            $parts[] = self::slugify($artworkTitle);
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

    private static function filenameCameraLabel(string $cameraSlotName, string $cameraAngle): string
    {
        $label = trim($cameraSlotName);
        if ($label === '') {
            $label = str_replace('_', ' ', trim($cameraAngle));
        }

        return trim(preg_replace('/\s+/', ' ', $label) ?: '');
    }

    private static function mockupContextWithoutCamera(string $mockupContext, string $cameraLabel): string
    {
        $mockupContext = trim($mockupContext);
        $cameraLabel = trim($cameraLabel);
        if ($mockupContext === '' || $cameraLabel === '') {
            return $mockupContext;
        }

        $cameraSlug = self::slugify($cameraLabel);
        $segments = preg_split('/\s*[\/|]\s*/', $mockupContext) ?: [$mockupContext];
        $kept = [];
        foreach ($segments as $segment) {
            $segment = trim((string)$segment);
            if ($segment === '' || self::slugify($segment) === $cameraSlug) {
                continue;
            }
            $kept[] = $segment;
        }

        return trim(implode(' ', $kept));
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
