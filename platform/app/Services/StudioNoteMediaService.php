<?php
declare(strict_types=1);

final class StudioNoteMediaService
{
    private const MAX_IMAGE_BYTES = 12 * 1024 * 1024;

    /**
     * Converts editor-only image sources into persistent note media and rewrites
     * the HTML to a stable platform media URL.
     *
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $availableSources
     * @return array{html:string,payload:array<string,mixed>}
     */
    public static function normalize(
        int $userId,
        int $noteId,
        string $html,
        array $payload,
        array $availableSources
    ): array {
        $sourcesByFile = [];
        foreach ($availableSources as $source) {
            $file = basename((string)($source['file'] ?? ''));
            if ($file !== '') $sourcesByFile[$file] = $source;
        }

        $previousInlineFiles = [];
        foreach ((array)($payload['inline_media_files'] ?? []) as $file) {
            $file = basename((string)$file);
            if ($file !== '') $previousInlineFiles[$file] = true;
        }
        $media = [];
        $mediaFiles = [];
        $existingInlineMedia = [];
        foreach ((array)($payload['media'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $file = basename((string)($item['file'] ?? ''));
            if ($file === '' || isset($mediaFiles[$file])) continue;
            if (isset($previousInlineFiles[$file]) || (string)($item['type'] ?? '') === 'studio_note') {
                $existingInlineMedia[$file] = $item;
                continue;
            }
            $mediaFiles[$file] = true;
            $media[] = $item;
        }
        $referencedInlineFiles = [];

        $rewritten = '';
        $copyFrom = 0;
        $searchFrom = 0;
        $htmlLength = strlen($html);
        while ($searchFrom < $htmlLength && ($imageStart = stripos($html, '<img', $searchFrom)) !== false) {
            $tagEnd = strpos($html, '>', $imageStart + 4);
            if ($tagEnd === false) break;
            $range = self::attributeValueRange($html, $imageStart, $tagEnd, 'src');
            if ($range === null) {
                $searchFrom = $tagEnd + 1;
                continue;
            }

            [$valueStart, $valueEnd] = $range;
            $source = html_entity_decode(substr($html, $valueStart, $valueEnd - $valueStart), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $mediaItem = null;
            if (preg_match('~^data:image/(?:jpeg|png|webp);base64,~i', $source) === 1) {
                $mediaItem = self::persistDataImage($userId, $noteId, $source);
            } else {
                $file = self::fileFromUrl($source);
                if ($file !== '') {
                    if (isset($sourcesByFile[$file])) {
                        $mediaItem = $sourcesByFile[$file];
                    } elseif (isset($existingInlineMedia[$file])) {
                        $mediaItem = $existingInlineMedia[$file];
                    } else {
                        foreach ($media as $existing) {
                            if (basename((string)($existing['file'] ?? '')) === $file) {
                                $mediaItem = $existing;
                                break;
                            }
                        }
                    }
                }
            }

            if (is_array($mediaItem)) {
                $file = basename((string)$mediaItem['file']);
                $referencedInlineFiles[$file] = true;
                if (!isset($mediaFiles[$file])) {
                    $mediaFiles[$file] = true;
                    $media[] = $mediaItem;
                }
                $stableSource = 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=1200';
                $rewritten .= substr($html, $copyFrom, $valueStart - $copyFrom)
                    . htmlspecialchars($stableSource, ENT_QUOTES, 'UTF-8');
                $copyFrom = $valueEnd;
            }
            $searchFrom = $tagEnd + 1;
        }
        $rewritten .= substr($html, $copyFrom);

        $payload['media'] = array_values($media);
        $payload['inline_media_files'] = array_keys($referencedInlineFiles);
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : null;
        if (is_array($source)) {
            $sourceFile = basename((string)($source['file'] ?? ''));
            $sourceWasInline = isset($previousInlineFiles[$sourceFile]) || (string)($source['type'] ?? '') === 'studio_note';
            if ($sourceWasInline && !isset($referencedInlineFiles[$sourceFile])) $source = null;
        }
        $availableFiles = [];
        foreach ($media as $item) $availableFiles[basename((string)($item['file'] ?? ''))] = true;
        if (is_array($source) && !isset($availableFiles[basename((string)($source['file'] ?? ''))])) $source = null;
        if (!is_array($source) && $media) $source = $media[0];
        if (is_array($source)) $payload['source'] = $source;
        else unset($payload['source']);
        $payload['mockup_ids'] = array_values(array_map(
            static fn(array $item): int => (int)($item['id'] ?? 0),
            array_filter($media, static fn(array $item): bool => (string)($item['type'] ?? '') === 'mockup')
        ));

        return ['html' => $rewritten, 'payload' => $payload];
    }

    /** @return array<string,mixed> */
    private static function persistDataImage(int $userId, int $noteId, string $uri): array
    {
        $comma = strpos($uri, ',');
        if ($comma === false) throw new RuntimeException('La imagen insertada no tiene un formato válido.');
        $prefix = strtolower(substr($uri, 0, $comma));
        if (preg_match('~^data:image/(jpeg|png|webp);base64$~', $prefix, $match) !== 1) {
            throw new RuntimeException('Studio Notes solo admite imágenes JPEG, PNG o WebP.');
        }
        $encoded = preg_replace('/\s+/', '', substr($uri, $comma + 1)) ?? '';
        $bytes = base64_decode($encoded, true);
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('La imagen insertada está dañada o supera el límite de 12 MB.');
        }
        $mime = 'image/' . strtolower((string)$match[1]);
        $info = @getimagesizefromstring($bytes);
        if (!is_array($info) || (string)($info['mime'] ?? '') !== $mime) {
            throw new RuntimeException('No se pudo validar la imagen insertada.');
        }

        $extension = $mime === 'image/jpeg' ? 'jpg' : substr($mime, 6);
        $hash = substr(hash('sha256', $bytes), 0, 20);
        $file = 'studio-note-' . $userId . '-' . $noteId . '-' . $hash . '.' . $extension;
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            if (!is_dir(RESULTS_DIR) && !mkdir(RESULTS_DIR, 0775, true) && !is_dir(RESULTS_DIR)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento de Studio Notes.');
            }
            $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
            if (file_put_contents($temporary, $bytes, LOCK_EX) === false || !rename($temporary, $path)) {
                if (is_file($temporary)) @unlink($temporary);
                throw new RuntimeException('No se pudo guardar la imagen de Studio Notes.');
            }
        }
        if (StorageService::isGcsActive() && !StorageService::uploadFile('results/' . $file, $path)) {
            throw new RuntimeException('No se pudo guardar la imagen de Studio Notes en el almacenamiento persistente.');
        }

        return [
            'key' => 'studio_note:' . $noteId . ':' . $hash,
            'type' => 'studio_note',
            'id' => $hash,
            'file' => $file,
            'label' => 'Studio Note image',
        ];
    }

    private static function fileFromUrl(string $source): string
    {
        $query = parse_url($source, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $parameters);
            $file = basename((string)($parameters['file'] ?? ''));
            if ($file !== '') return $file;
        }
        return '';
    }

    /** @return array{0:int,1:int}|null */
    private static function attributeValueRange(string $html, int $tagStart, int $tagEnd, string $attribute): ?array
    {
        $cursor = $tagStart + 4;
        $length = strlen($html);
        while ($cursor < $tagEnd && ($found = stripos($html, $attribute, $cursor)) !== false && $found < $tagEnd) {
            $before = $found > $tagStart ? $html[$found - 1] : ' ';
            $afterIndex = $found + strlen($attribute);
            $after = $afterIndex < $length ? $html[$afterIndex] : ' ';
            if ((ctype_space($before) || $before === '<') && (ctype_space($after) || $after === '=')) {
                $value = $afterIndex;
                while ($value < $tagEnd && ctype_space($html[$value])) $value++;
                if ($value < $tagEnd && $html[$value] === '=') $value++;
                while ($value < $tagEnd && ctype_space($html[$value])) $value++;
                $quote = $value < $tagEnd ? $html[$value] : '';
                if ($quote === '"' || $quote === "'") {
                    $value++;
                    $end = strpos($html, $quote, $value);
                    if ($end !== false && $end <= $tagEnd) return [$value, $end];
                }
            }
            $cursor = $found + strlen($attribute);
        }
        return null;
    }
}
