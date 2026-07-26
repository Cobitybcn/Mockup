<?php
declare(strict_types=1);

final class StudioNoteChangeClassifier
{
    private const SEO_FIELDS = [
        'excerpt',
        'slug',
        'seo_title',
        'seo_description',
        'alt_text',
        'caption',
        'tags',
        'search_terms',
    ];

    /**
     * @return array{
     *   state:string,primary_action:string,status_message:string,status_detail:string,
     *   english_status:string,has_unpublished_changes:bool,format_changed:bool,
     *   spanish_content_changed:bool,english_content_changed:bool,seo_changed:bool,
     *   media_changed:bool,media_requires_analysis:bool,structure_changed:bool,needs_english_update:bool
     * }
     */
    public static function classify(
        array $spanish,
        array $publishedSpanish,
        array $english,
        array $publishedEnglish,
        bool $spanishPublished,
        bool $englishPublished
    ): array {
        $hasPublishedPair = $spanishPublished
            && $englishPublished
            && $publishedSpanish !== []
            && $publishedEnglish !== [];
        $spanishContentChanged = self::contentSignature($spanish)
            !== self::contentSignature($publishedSpanish);
        $englishContentChanged = self::contentSignature($english)
            !== self::contentSignature($publishedEnglish);
        $structureChanged = self::structureSignature((string)($spanish['body_html'] ?? ''))
            !== self::structureSignature((string)($publishedSpanish['body_html'] ?? ''));
        $seoChanged = self::seoSignature($spanish) !== self::seoSignature($publishedSpanish)
            || self::seoSignature($english) !== self::seoSignature($publishedEnglish);
        $mediaChanged = self::mediaSignature($spanish) !== self::mediaSignature($publishedSpanish)
            || self::mediaMetadataSignature($spanish) !== self::mediaMetadataSignature($publishedSpanish)
            || self::mediaMetadataSignature($english) !== self::mediaMetadataSignature($publishedEnglish);
        $mediaRequiresAnalysis = self::mediaSignature($spanish) !== self::mediaSignature($publishedSpanish)
            && !self::mediaMetadataComplete($spanish, $english);
        $bodyChanged = trim((string)($spanish['body_html'] ?? ''))
            !== trim((string)($publishedSpanish['body_html'] ?? ''));
        $formatChanged = $bodyChanged
            && !$spanishContentChanged
            && !$structureChanged
            && !$mediaChanged;
        $hasUnpublishedChanges = !$hasPublishedPair
            || $spanish !== $publishedSpanish
            || $english !== $publishedEnglish;
        $initialPreparationNeeded = !$hasPublishedPair
            && !self::preparedForPublication($spanish, $english);
        $needsEnglishUpdate = $initialPreparationNeeded
            || ($spanishContentChanged && !$englishContentChanged)
            || $mediaRequiresAnalysis;

        if (!$hasUnpublishedChanges) {
            $state = 'published';
            $primaryAction = '';
            $message = 'Publicado · Sin cambios pendientes';
            $detail = '';
        } elseif ($needsEnglishUpdate) {
            $state = 'english_pending';
            $primaryAction = 'update_english';
            $message = $hasPublishedPair
                ? 'El contenido en español fue modificado'
                : 'La versión inglesa todavía no está preparada';
            $detail = 'La adaptación inglesa y el SEO pendiente se actualizarán antes de publicar.';
        } else {
            $state = $formatChanged && !$seoChanged
                ? 'design_changes'
                : 'ready_to_publish';
            $primaryAction = 'publish_changes';
            $message = $state === 'design_changes'
                ? 'Cambios de diseño sin publicar'
                : 'Cambios listos para publicar';
            $detail = $state === 'design_changes'
                ? 'No es necesario actualizar la traducción ni el SEO.'
                : 'La publicación utilizará las versiones guardadas sin ejecutar IA.';
        }

        $englishStatus = $needsEnglishUpdate
            ? 'Pendiente de actualización'
            : ($englishContentChanged ? 'Editado manualmente' : 'Sincronizado');

        return [
            'state' => $state,
            'primary_action' => $primaryAction,
            'status_message' => $message,
            'status_detail' => $detail,
            'english_status' => $englishStatus,
            'has_unpublished_changes' => $hasUnpublishedChanges,
            'format_changed' => $formatChanged,
            'spanish_content_changed' => $spanishContentChanged,
            'english_content_changed' => $englishContentChanged,
            'seo_changed' => $seoChanged,
            'media_changed' => $mediaChanged,
            'media_requires_analysis' => $mediaRequiresAnalysis,
            'structure_changed' => $structureChanged,
            'needs_english_update' => $needsEnglishUpdate,
        ];
    }

    /** @return list<string> */
    public static function protectedSpanishSeoFields(array $current, array $published): array
    {
        $protected = [];
        foreach (self::SEO_FIELDS as $field) {
            $value = trim((string)($current[$field] ?? ''));
            if ($value === '') continue;
            $publishedValue = trim((string)($published[$field] ?? ''));
            if ($published === []
                || $value !== $publishedValue
                || trim((string)($current['meta_source_hash'] ?? '')) === '') {
                $protected[] = $field;
            }
        }
        if ((array)($current['image_metadata'] ?? []) !== []
            && ($published === []
                || self::mediaMetadataSignature($current) !== self::mediaMetadataSignature($published))) {
            $protected[] = 'image_metadata';
        }
        return $protected;
    }

    private static function contentSignature(array $content): string
    {
        return self::encode([
            'title' => self::normalize((string)($content['title'] ?? '')),
            'body_text' => self::bodyText((string)($content['body_html'] ?? '')),
        ]);
    }

    private static function preparedForPublication(array $spanish, array $english): bool
    {
        foreach ([$spanish, $english] as $content) {
            if (self::normalize((string)($content['title'] ?? '')) === ''
                || self::bodyText((string)($content['body_html'] ?? '')) === '') {
                return false;
            }
            foreach (self::SEO_FIELDS as $field) {
                if (self::normalize((string)($content[$field] ?? '')) === '') return false;
            }
        }
        return true;
    }

    private static function seoSignature(array $content): string
    {
        $seo = [];
        foreach (self::SEO_FIELDS as $field) {
            $seo[$field] = self::normalize((string)($content[$field] ?? ''));
        }
        return self::encode($seo);
    }

    private static function mediaSignature(array $content): string
    {
        $html = (string)($content['body_html'] ?? '');
        preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/iu', $html, $matches);
        $files = [];
        foreach ((array)($matches[1] ?? []) as $source) {
            $decoded = html_entity_decode((string)$source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $query = parse_url($decoded, PHP_URL_QUERY);
            $parameters = [];
            if (is_string($query)) parse_str($query, $parameters);
            $file = basename((string)($parameters['file'] ?? parse_url($decoded, PHP_URL_PATH) ?? ''));
            $files[] = $file !== '' ? $file : hash('sha256', $decoded);
        }
        return self::encode($files);
    }

    private static function mediaMetadataSignature(array $content): string
    {
        $metadata = [];
        foreach ((array)($content['image_metadata'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $file = basename((string)($row['file'] ?? ''));
            if ($file === '') continue;
            $metadata[$file] = [
                'alt_text' => self::normalize((string)($row['alt_text'] ?? '')),
                'caption' => self::normalize((string)($row['caption'] ?? '')),
            ];
        }
        ksort($metadata);
        return self::encode($metadata);
    }

    private static function mediaMetadataComplete(array $spanish, array $english): bool
    {
        $files = self::mediaFiles($spanish);
        if ($files === []) return true;
        foreach ([$spanish, $english] as $content) {
            $metadata = [];
            foreach ((array)($content['image_metadata'] ?? []) as $row) {
                if (!is_array($row)) continue;
                $file = basename((string)($row['file'] ?? ''));
                if ($file === '') continue;
                $metadata[$file] = trim((string)($row['alt_text'] ?? '')) !== ''
                    && trim((string)($row['caption'] ?? '')) !== '';
            }
            foreach ($files as $file) {
                if (empty($metadata[$file])) return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private static function mediaFiles(array $content): array
    {
        $html = (string)($content['body_html'] ?? '');
        preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/iu', $html, $matches);
        $files = [];
        foreach ((array)($matches[1] ?? []) as $source) {
            $decoded = html_entity_decode((string)$source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $query = parse_url($decoded, PHP_URL_QUERY);
            $parameters = [];
            if (is_string($query)) parse_str($query, $parameters);
            $file = basename((string)($parameters['file'] ?? parse_url($decoded, PHP_URL_PATH) ?? ''));
            if ($file !== '') $files[] = $file;
        }
        return array_values(array_unique($files));
    }

    private static function bodyText(string $html): string
    {
        $withoutImages = preg_replace('/<img\b[^>]*>/iu', '', $html) ?? $html;
        return self::normalize(html_entity_decode(
            strip_tags($withoutImages),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        ));
    }

    private static function structureSignature(string $html): string
    {
        $withoutImages = preg_replace('/<img\b[^>]*>/iu', '', $html) ?? $html;
        preg_match_all(
            '/<(h1|h2|h3|p|blockquote|ul|ol|li|a)\b([^>]*)>/iu',
            $withoutImages,
            $matches,
            PREG_SET_ORDER
        );
        $structure = [];
        foreach ($matches as $match) {
            $tag = strtolower((string)$match[1]);
            if ($tag === 'a') {
                preg_match('/\bhref=["\']([^"\']+)["\']/iu', (string)$match[2], $href);
                $structure[] = 'a:' . trim((string)($href[1] ?? ''));
            } else {
                $structure[] = $tag;
            }
        }
        return self::encode($structure);
    }

    private static function normalize(string $value): string
    {
        return trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private static function encode(array $value): string
    {
        return hash(
            'sha256',
            json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }
}
