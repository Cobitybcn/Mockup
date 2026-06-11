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
}
