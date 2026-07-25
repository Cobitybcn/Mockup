<?php
declare(strict_types=1);

final class AppPublishedStudioNotes
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(string $locale = 'en'): array
    {
        $locale = in_array($locale, ['es', 'en'], true) ? $locale : 'en';
        $statement = $this->pdo->prepare("
            SELECT sc.*
            FROM social_campaigns sc
            JOIN users u ON u.id = sc.user_id
            WHERE LOWER(u.email) = ? AND sc.status = 'published'
            ORDER BY sc.updated_at DESC, sc.id DESC
        ");
        $statement->execute([$this->artistEmail]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return [];

        $userId = (int)$rows[0]['user_id'];
        $localized = $this->publishedLocalizations($userId);
        $notes = [];
        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)
                || !in_array('website_blog', array_map('strval', (array)($payload['channels'] ?? [])), true)) {
                continue;
            }

            $noteId = (int)$row['id'];
            $englishLegacy = [
                'title' => (string)$row['title'],
                'excerpt' => '',
                'body_html' => (string)$row['objective'],
                'slug' => $this->slug((string)$row['title']),
                'seo_title' => '',
                'seo_description' => '',
                'alt_text' => '',
                'search_terms' => '',
            ];
            $spanish = (array)($localized[$noteId]['es'] ?? []);
            $english = (array)($localized[$noteId]['en'] ?? []);
            if ($locale === 'es' && $spanish === []) continue;
            $content = $locale === 'es' ? $spanish : ($english ?: $englishLegacy);
            if (trim((string)($content['title'] ?? '')) === ''
                || trim(strip_tags((string)($content['body_html'] ?? ''))) === '') {
                continue;
            }

            $localizedBodies = [
                (string)($spanish['body_html'] ?? ''),
                (string)($english['body_html'] ?? ''),
                (string)$row['objective'],
            ];
            $bodyMediaFiles = $this->bodyMediaFiles($row, $localizedBodies);
            $mediaFiles = $this->mediaFiles($row, $payload, $bodyMediaFiles);
            $row['source'] = is_array($payload['source'] ?? null) ? $payload['source'] : null;
            $row['media_files'] = $mediaFiles;
            $row['mockup_files'] = $mediaFiles;
            $row['body_media_files'] = $this->bodyMediaFiles($row, [(string)$content['body_html']]);
            $row['title'] = trim((string)$content['title']);
            $row['objective'] = (string)$content['body_html'];
            $row['excerpt'] = trim((string)($content['excerpt'] ?? ''));
            $row['seo_title'] = trim((string)($content['seo_title'] ?? ''));
            $row['seo_description'] = trim((string)($content['seo_description'] ?? ''));
            $row['alt_text'] = trim((string)($content['alt_text'] ?? ''));
            $row['caption'] = trim((string)($content['caption'] ?? ''));
            $row['search_terms'] = trim((string)($content['search_terms'] ?? ''));
            $row['image_metadata'] = $this->imageMetadata((array)($content['image_metadata'] ?? []));
            $row['locale'] = $locale;
            $row['has_embedded_image'] = $this->hasEmbeddedImage((string)$row['objective']);

            $englishSlug = $this->contentSlug($english ?: $englishLegacy, $noteId);
            $spanishSlug = $spanish !== [] ? $this->contentSlug($spanish, $noteId) : '';
            $slug = $locale === 'es' ? $spanishSlug : $englishSlug;
            $row['language_slugs'] = ['es' => $spanishSlug, 'en' => $englishSlug];
            $row['legacy_slug'] = $this->slug((string)$englishLegacy['title']) . '-' . $noteId;
            $notes[$slug] = $row;
        }
        return $notes;
    }

    /** @return array<string,array{alt_text:string,caption:string}> */
    private function imageMetadata(array $items): array
    {
        $metadata = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $file = basename(trim((string)($item['file'] ?? '')));
            if ($file === '') continue;
            $metadata[$file] = [
                'alt_text' => trim((string)($item['alt_text'] ?? '')),
                'caption' => trim((string)($item['caption'] ?? '')),
            ];
        }
        return $metadata;
    }

    /** @return array<int,array<string,array<string,mixed>>> */
    private function publishedLocalizations(int $userId): array
    {
        $entries = [];
        try {
            $stmt = $this->pdo->prepare("SELECT entity_id,locale,published_content_json
                FROM bilingual_editorial_content
                WHERE user_id=? AND entity_type='studio_note' AND locale IN ('es','en') AND is_published=1");
            $stmt->execute([$userId]);
            foreach ($stmt as $row) {
                $content = json_decode((string)$row['published_content_json'], true);
                if (!is_array($content)) continue;
                $entries[(int)$row['entity_id']][(string)$row['locale']] = $content;
            }
        } catch (PDOException) {
            return [];
        }
        return $entries;
    }

    /** @return list<string> */
    private function mediaFiles(array $row, array $payload, array $bodyMediaFiles = []): array
    {
        $mediaFiles = [];
        foreach ((array)($payload['media'] ?? []) as $media) {
            if (!is_array($media)) continue;
            $file = basename((string)($media['file'] ?? ''));
            if ($file !== '' && !in_array($file, $mediaFiles, true)) $mediaFiles[] = $file;
        }
        if ($mediaFiles) return $mediaFiles;
        if ($bodyMediaFiles) return array_values(array_unique(array_map('basename', $bodyMediaFiles)));

        $mockupIds = array_values(array_filter(array_map('intval', (array)($payload['mockup_ids'] ?? []))));
        if (!$mockupIds) return [];
        $marks = implode(',', array_fill(0, count($mockupIds), '?'));
        $stmt = $this->pdo->prepare("SELECT mockup_file FROM mockups WHERE user_id = ? AND id IN ($marks)");
        $stmt->execute(array_merge([(int)$row['user_id']], $mockupIds));
        return array_values(array_filter(array_map('basename', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
    }

    /** @return list<string> */
    private function bodyMediaFiles(array $row, array $localizedBodies): array
    {
        $mediaFiles = [];
        $notePrefix = 'studio-note-' . (int)$row['user_id'] . '-' . (int)$row['id'] . '-';
        foreach ($localizedBodies as $bodyHtml) {
            $imageCount = preg_match_all(
                '/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/iu',
                (string)$bodyHtml,
                $matches
            );
            if (!$imageCount) continue;
            foreach ((array)($matches[1] ?? []) as $source) {
                $source = html_entity_decode((string)$source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $query = parse_url($source, PHP_URL_QUERY);
                if (!is_string($query)) continue;
                parse_str($query, $parameters);
                $file = basename((string)($parameters['file'] ?? ''));
                if ($file !== '' && str_starts_with($file, $notePrefix)
                    && !in_array($file, $mediaFiles, true)) {
                    $mediaFiles[] = $file;
                }
            }
        }
        return $mediaFiles;
    }

    private function contentSlug(array $content, int $noteId): string
    {
        $slug = $this->slug((string)($content['slug'] ?? ''));
        if ($slug === '') $slug = $this->slug((string)($content['title'] ?? 'studio-note'));
        return $slug . '-' . $noteId;
    }

    private function hasEmbeddedImage(string $html): bool
    {
        return stripos($html, 'data:image/jpeg;base64,') !== false
            || stripos($html, 'data:image/png;base64,') !== false
            || stripos($html, 'data:image/webp;base64,') !== false;
    }

    private function slug(string $title): string
    {
        $slug = strtolower($title);
        $slug = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug) ?: $slug;
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}
