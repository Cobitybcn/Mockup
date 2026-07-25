<?php
declare(strict_types=1);

final class StudioNoteEmbeddedImage
{
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    /** @return array{mime:string,bytes:string}|null */
    public static function decodeFirst(string $html): ?array
    {
        foreach ([
            'data:image/jpeg;base64,',
            'data:image/png;base64,',
            'data:image/webp;base64,',
            'data:application/octet-stream;base64,',
        ] as $needle) {
            $start = stripos($html, $needle);
            if ($start === false) continue;
            $valueEnd = self::attributeEnd($html, $start);
            $encodedStart = $start + strlen($needle);
            if ($valueEnd <= $encodedStart) continue;
            $encoded = preg_replace('/\s+/', '', substr($html, $encodedStart, $valueEnd - $encodedStart)) ?? '';
            $bytes = base64_decode($encoded, true);
            if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) return null;
            $info = @getimagesizefromstring($bytes);
            $mime = is_array($info) ? strtolower((string)($info['mime'] ?? '')) : '';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
            return ['mime' => $mime, 'bytes' => $bytes];
        }
        return null;
    }

    public static function has(string $html): bool
    {
        return stripos($html, 'data:image/jpeg;base64,') !== false
            || stripos($html, 'data:image/png;base64,') !== false
            || stripos($html, 'data:image/webp;base64,') !== false
            || stripos($html, 'data:application/octet-stream;base64,') !== false;
    }

    private static function attributeEnd(string $html, int $dataStart): int
    {
        $double = strpos($html, '"', $dataStart);
        $single = strpos($html, "'", $dataStart);
        if ($double === false) return $single === false ? -1 : $single;
        if ($single === false) return $double;
        return min($double, $single);
    }
}
