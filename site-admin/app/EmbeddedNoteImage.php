<?php
declare(strict_types=1);

final class EmbeddedNoteImage
{
    public static function has(string $html): bool
    {
        return self::dataUri($html) !== null;
    }

    /** @return array{mime:string,bytes:string}|null */
    public static function decode(string $html): ?array
    {
        $uri = self::dataUri($html);
        if ($uri === null) return null;
        $comma = strpos($uri, ',');
        if ($comma === false) return null;
        $prefix = strtolower(substr($uri, 0, $comma));
        if (!preg_match('~^data:image/(jpeg|png|webp);base64$~', $prefix, $match)) return null;
        $encoded = preg_replace('/\\s+/', '', substr($uri, $comma + 1)) ?? '';
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 12 * 1024 * 1024) return null;
        $mime = 'image/' . strtolower((string)$match[1]);
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || (string)($info['mime'] ?? '') !== $mime) return null;
        return ['mime' => $mime, 'bytes' => $bytes];
    }

    private static function dataUri(string $html): ?string
    {
        $offset = 0;
        $length = strlen($html);
        while ($offset < $length && ($imageStart = stripos($html, '<img', $offset)) !== false) {
            $tagEnd = strpos($html, '>', $imageStart + 4);
            if ($tagEnd === false) return null;
            $cursor = $imageStart + 4;
            while ($cursor < $tagEnd && ($src = stripos($html, 'src', $cursor)) !== false && $src < $tagEnd) {
                $before = $src > $imageStart ? $html[$src - 1] : ' ';
                $after = $src + 3 < $length ? $html[$src + 3] : ' ';
                if ((ctype_space($before) || $before === '<') && (ctype_space($after) || $after === '=')) {
                    $value = $src + 3;
                    while ($value < $tagEnd && ctype_space($html[$value])) $value++;
                    if ($value < $tagEnd && $html[$value] === '=') $value++;
                    while ($value < $tagEnd && ctype_space($html[$value])) $value++;
                    $quote = $value < $tagEnd ? $html[$value] : '';
                    if ($quote === '"' || $quote === "'") {
                        $value++;
                        $end = strpos($html, $quote, $value);
                        if ($end !== false && $end <= $tagEnd) {
                            $uri = substr($html, $value, $end - $value);
                            if (preg_match('~^data:image/(?:jpeg|png|webp);base64,~i', $uri) === 1) return $uri;
                            $cursor = $end + 1;
                            continue;
                        }
                    }
                }
                $cursor = $src + 3;
            }
            $offset = $tagEnd + 1;
        }
        return null;
    }
}
