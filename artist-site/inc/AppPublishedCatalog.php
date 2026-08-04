<?php
declare(strict_types=1);

require_once __DIR__ . '/AppPublishedLocalization.php';
require_once __DIR__ . '/PublicSlug.php';

final class AppPublishedCatalog
{
    private AppPublishedLocalization $localization;
    /** @var array<string,bool> */
    private array $mockupColumns = [];
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $catalogCache = [];

    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail)
    {
        $this->localization = new AppPublishedLocalization($pdo, $artistEmail);
    }

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(): array
    {
        // A single mockup page resolves the catalog through all(), one() and mockup().
        // Rebuilding it each time multiplied an already heavy read by three.
        $language = function_exists('artist_site_language') ? artist_site_language() : 'es';
        if (isset($this->catalogCache[$language])) return $this->catalogCache[$language];

        $statement = $this->pdo->prepare("SELECT p.*, a.source_image_file, a.canonical_artwork_id, a.subtitle,
                a.title artwork_title,a.description artwork_description,a.short_description artwork_short_description,
                a.caption artwork_caption,a.generated_json artwork_generated_json,aw.medium,
                aw.artwork_year, aw.series, aw.series_id, aw.width, aw.height, aw.depth, aw.unit, a.alt_text artwork_alt,
                a.keywords artwork_keywords, a.tags artwork_tags
            FROM publications p
            JOIN users u ON u.id=p.user_id
            JOIN artwork_sheets a ON a.id=p.artwork_sheet_id AND a.user_id=p.user_id
            INNER JOIN artworks aw ON aw.id=a.canonical_artwork_id AND aw.user_id=p.user_id
            INNER JOIN artwork_groups g ON g.id=aw.artwork_group_id AND g.user_id=aw.user_id
                AND g.status='active' AND g.canonical_artwork_id=aw.id
            LEFT JOIN artwork_series s ON s.id=aw.series_id AND s.user_id=aw.user_id
            WHERE LOWER(u.email)=? AND p.status='published' AND p.visibility IN ('public','unlisted')
                AND NOT EXISTS (
                    SELECT 1
                    FROM publications newer
                    INNER JOIN artwork_sheets newer_sheet ON newer_sheet.id=newer.artwork_sheet_id
                        AND newer_sheet.user_id=newer.user_id
                    WHERE newer.user_id=p.user_id
                        AND newer.status='published'
                        AND newer.visibility IN ('public','unlisted')
                        AND newer_sheet.canonical_artwork_id=a.canonical_artwork_id
                        AND newer.id>p.id
                )
            ORDER BY
                CASE WHEN aw.series_id IS NULL THEN 1 ELSE 0 END ASC,
                CASE WHEN s.display_order > 0 THEN 0 ELSE 1 END ASC,
                s.display_order ASC,
                CASE WHEN s.year_start IS NULL AND s.year_end IS NULL THEN 1 ELSE 0 END ASC,
                COALESCE(s.year_start,s.year_end) DESC,
                COALESCE(s.year_end,s.year_start) DESC,
                s.created_at DESC,
                s.id DESC,
                CASE WHEN aw.series_creation_number IS NULL THEN 1 ELSE 0 END ASC,
                aw.series_creation_number DESC,
                g.created_at DESC,
                g.id DESC");
        $statement->execute([$this->artistEmail]);
        $publications = [];
        foreach ($statement as $row) {
            $spanish = $this->localization->content('artwork', (int)$row['canonical_artwork_id'], 'es');
            $localized = $this->localization->content('artwork', (int)$row['canonical_artwork_id'], $language);
            $row['spanish_available'] = $spanish !== [];
            if ($localized !== []) {
                $fallback = $language === 'es' ? '' : null;
                $row['subtitle'] = (string)($localized['subtitle'] ?? $fallback ?? $row['subtitle']);
                $row['artwork_description'] = (string)($localized['description'] ?? $fallback ?? $row['artwork_description']);
                $row['artwork_short_description'] = (string)($localized['short_description'] ?? $fallback ?? $row['artwork_short_description']);
                $row['artwork_caption'] = (string)($localized['caption'] ?? $fallback ?? $row['artwork_caption']);
                $row['artwork_alt'] = (string)($localized['alt_text'] ?? $fallback ?? $row['artwork_alt']);
                $row['artwork_keywords'] = (string)($localized['search_terms'] ?? $localized['keywords'] ?? $fallback ?? $row['artwork_keywords']);
                $row['artwork_tags'] = (string)($localized['tags'] ?? $fallback ?? $row['artwork_tags']);
                // Vocabulario de descubrimiento generado para el listing: es el
                // unico lugar del sistema donde hay terminos emocionales
                // —"Quiet Solemnity", "Sober Contemplation"— y los nombres de
                // los artistas afines a ESTA obra. Descrilo es descripcion de la
                // obra, no de un canal, asi que enriquece tambien al sitio.
                // Si la obra todavia no lo tiene, queda vacio y no pasa nada.
                // discovery_keywords se deriva por idioma desde la lectura de ESE
                // idioma; saatchi_keywords es el mismo vocabulario en ingles, que
                // ya existe por la pasada del listing. Traducir una keyword la
                // mata, asi que cada idioma nace de su propio texto.
                $row['artwork_discovery_keywords'] = (string)($localized['discovery_keywords'] ?? $localized['saatchi_keywords'] ?? '');
                $row['seo_title'] = (string)($localized['seo_title'] ?? '');
                $row['seo_description'] = (string)($localized['seo_description'] ?? '');
                $row['translation_missing_fields'] = array_values(array_diff([
                    'subtitle',
                    'description',
                    'short_description',
                    'caption',
                    'alt_text',
                    'tags',
                    'seo_title',
                    'seo_description',
                ], array_keys($localized)));
                if ($language === 'es' && $row['translation_missing_fields'] !== []) {
                    error_log('Incomplete Spanish artwork translation for artwork '
                        . (int)$row['canonical_artwork_id'] . ': ' . implode(',', $row['translation_missing_fields']));
                }
            } elseif ($language === 'es') {
                $row['subtitle'] = '';
                $row['artwork_description'] = '';
                $row['artwork_short_description'] = '';
                $row['artwork_caption'] = '';
                $row['artwork_alt'] = '';
                $row['artwork_keywords'] = '';
                $row['artwork_tags'] = '';
                $row['artwork_discovery_keywords'] = '';
                $row['seo_title'] = '';
                $row['seo_description'] = '';
                $row['translation_missing_fields'] = ['translation'];
                error_log('Missing published Spanish artwork translation for artwork '
                    . (int)$row['canonical_artwork_id']);
            }
            $row['title'] = (string)$row['artwork_title'];
            $row['description'] = (string)$row['artwork_description'];
            $row['short_description'] = (string)$row['artwork_short_description'];
            $row['artwork_metadata'] = [
                'title' => (string)$row['artwork_title'],
                'subtitle' => (string)$row['subtitle'],
                'description' => (string)$row['artwork_description'],
                'short_description' => (string)$row['artwork_short_description'],
                'caption' => (string)$row['artwork_caption'],
                'alt_text' => (string)$row['artwork_alt'],
                'keywords' => (string)$row['artwork_keywords'],
                'tags' => (string)$row['artwork_tags'],
                'seo_title' => (string)($row['seo_title'] ?? ''),
                'seo_description' => (string)($row['seo_description'] ?? ''),
                'generated_json' => (string)$row['artwork_generated_json'],
            ];
            $analysis = json_decode((string)$row['artwork_generated_json'], true);
            $row['artwork_analysis'] = $language === 'es'
                ? []
                : (is_array($analysis) ? $analysis : []);
            $row['items'] = $this->items((int)$row['id'], (string)$row['slug'], (string)$row['artwork_title']);
            $row['artwork_views'] = $this->artworkViews((int)$row['canonical_artwork_id']);
            $row['header_file'] = $this->headerFileForArtwork((int)$row['user_id'], $row);
            $row['video'] = $this->publishedVideo((int)$row['user_id'], (int)$row['canonical_artwork_id']);
            $publications[(string)$row['slug']] = $row;
        }
        return $this->catalogCache[$language] = $publications;
    }

    public function one(string $slug): ?array
    {
        $catalog = $this->all();
        if (isset($catalog[$slug])) return $catalog[$slug];
        try {
            $statement = $this->pdo->prepare("SELECT p.slug
                FROM publication_slug_aliases aliases
                INNER JOIN publications p ON p.id=aliases.publication_id AND p.user_id=aliases.user_id
                INNER JOIN users u ON u.id=p.user_id
                WHERE LOWER(u.email)=? AND aliases.slug=?
                    AND p.status='published' AND p.visibility IN ('public','unlisted')
                ORDER BY p.id DESC LIMIT 1");
            $statement->execute([strtolower(trim($this->artistEmail)), $slug]);
            $canonicalSlug = (string)($statement->fetchColumn() ?: '');
            return $canonicalSlug !== '' ? ($catalog[$canonicalSlug] ?? null) : null;
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function constellations(): array
    {
        try {
            $statement = $this->pdo->prepare("SELECT c.*,p.slug artwork_slug
                FROM artist_site_constellations c
                INNER JOIN users u ON u.id=c.user_id
                INNER JOIN artwork_sheets sh ON sh.user_id=c.user_id AND sh.canonical_artwork_id=c.artwork_id
                INNER JOIN publications p ON p.user_id=c.user_id AND p.artwork_sheet_id=sh.id
                    AND p.status='published' AND p.visibility='public'
                WHERE LOWER(u.email)=? AND c.enabled=1
                ORDER BY c.updated_at DESC,p.id DESC");
            $statement->execute([strtolower(trim($this->artistEmail))]);
            $locations = [];
            foreach ($statement as $row) {
                $artworkId = (int)$row['artwork_id'];
                if (!isset($locations[$artworkId])) $locations[$artworkId] = $row;
            }
            return $locations;
        } catch (PDOException) {
            // Legacy installations keep their existing Constellations data until migration.
            return [];
        }
    }

    public function mockup(string $artworkSlug, string $mockupSlug): ?array
    {
        $artwork = $this->one($artworkSlug);
        if (!$artwork) return null;
        $canonicalArtworkSlug = (string)$artwork['slug'];
        $canonicalizedMockupSlug = $mockupSlug;
        if ($artworkSlug !== $canonicalArtworkSlug && str_starts_with($mockupSlug, $artworkSlug . '-')) {
            $canonicalizedMockupSlug = $canonicalArtworkSlug . substr($mockupSlug, strlen($artworkSlug));
        }
        foreach ($artwork['items'] as $item) {
            if (in_array($canonicalizedMockupSlug, [
                (string)$item['public_slug'],
                (string)$item['public_slug_en'],
                (string)$item['public_slug_es'],
            ], true)) {
                return ['artwork' => $artwork, 'mockup' => $item];
            }
            $legacy = self::slug((string)($item['title'] ?: 'mockup')) . '-' . (int)$item['mockup_sheet_id'];
            if ($legacy === $mockupSlug) return ['artwork' => $artwork, 'mockup' => $item];
        }
        return null;
    }

    private function headerFileForArtwork(int $userId, array $artwork): string
    {
        $file = basename((string)($artwork['header_file'] ?? ''));
        if ($file === '') return '';
        if (basename((string)$artwork['source_image_file']) === $file) return $file;
        foreach ($artwork['artwork_views'] as $view) {
            if (basename((string)$view['file_name']) === $file) return $file;
        }
        foreach ($artwork['items'] as $item) {
            if (basename((string)$item['mockup_file']) === $file) return $file;
        }
        if ($this->isRelatedMockup((int)$artwork['artwork_sheet_id'], $userId, $file)) return $file;
        return '';
    }

    private function isRelatedMockup(int $artworkSheetId, int $userId, string $file): bool
    {
        if ($artworkSheetId <= 0 || $file === '') return false;
        try {
            $statement = $this->pdo->prepare('SELECT 1
                FROM artwork_sheets sh
                INNER JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=sh.user_id
                INNER JOIN mockup_sheets m ON m.user_id=sh.user_id AND (
                    m.artwork_id=a.id OR m.artwork_sheet_id=sh.id OR
                    (COALESCE(a.artwork_group_id,0)>0 AND m.artwork_group_id=a.artwork_group_id)
                )
                INNER JOIN mockups live ON live.user_id=m.user_id AND (
                    live.id=m.mockup_id OR live.mockup_file=m.mockup_file
                )
                WHERE sh.id=? AND sh.user_id=? AND m.mockup_file=?
                LIMIT 1');
            $statement->execute([$artworkSheetId, $userId, $file]);
            return (bool)$statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    private function items(int $publicationId, string $artworkSlug, string $artworkTitle): array
    {
        $contextSelect = $this->mockupColumnExists('context_id')
            ? "COALESCE((SELECT source.context_id FROM mockups source WHERE source.user_id=m.user_id AND (source.id=m.mockup_id OR source.mockup_file=m.mockup_file) ORDER BY source.id DESC LIMIT 1),'')"
            : "''";
        $selectorSelect = $this->mockupColumnExists('selector_state_json')
            ? "COALESCE((SELECT source.selector_state_json FROM mockups source WHERE source.user_id=m.user_id AND (source.id=m.mockup_id OR source.mockup_file=m.mockup_file) ORDER BY source.id DESC LIMIT 1),'')"
            : "''";
        $statement = $this->pdo->prepare("SELECT i.*,
                COALESCE(NULLIF(i.title,''),NULLIF(m.title,''),'') resolved_title,
                COALESCE(NULLIF(m.mockup_id,0),(
                    SELECT source.id FROM mockups source
                    WHERE source.user_id=m.user_id AND source.mockup_file=m.mockup_file
                    ORDER BY source.id DESC LIMIT 1
                )) mockup_id,
                {$contextSelect} mockup_context_id,
                {$selectorSelect} mockup_selector_state_json,
                m.mockup_file,m.description,m.keywords,m.tags,m.alt_text mockup_alt_text,m.caption mockup_caption
            FROM publication_items i JOIN mockup_sheets m ON m.id=i.mockup_sheet_id
            WHERE i.publication_id=? AND EXISTS (
                SELECT 1 FROM mockups live
                WHERE live.user_id=m.user_id AND (
                    live.id=m.mockup_id OR live.mockup_file=m.mockup_file
                )
            )
            ORDER BY i.position,i.id");
        $statement->execute([$publicationId]);
        $items = $statement->fetchAll();
        $explicitFavoriteCount = count($items);
        $seenMockupIds = [];
        $seenFiles = [];
        foreach ($items as $item) {
            $mockupId = (int)($item['mockup_id'] ?? 0);
            $file = basename((string)($item['mockup_file'] ?? ''));
            if ($mockupId > 0) $seenMockupIds[$mockupId] = true;
            if ($file !== '') $seenFiles[$file] = true;
        }

        // An explicit selection saved from the platform's Publication section is
        // authoritative: the page shows ONLY those items, in their saved order.
        // Publications without any explicit selection keep the legacy fallback
        // of appending every canonical mockup, so older pages lose nothing.
        if ($explicitFavoriteCount === 0) {
            foreach ($this->relatedItems($publicationId) as $relatedItem) {
                $mockupId = (int)($relatedItem['mockup_id'] ?? 0);
                $file = basename((string)($relatedItem['mockup_file'] ?? ''));
                if (($mockupId > 0 && isset($seenMockupIds[$mockupId])) || ($file !== '' && isset($seenFiles[$file]))) {
                    continue;
                }
                $items[] = $relatedItem;
                if ($mockupId > 0) $seenMockupIds[$mockupId] = true;
                if ($file !== '') $seenFiles[$file] = true;
            }
        }

        $usedEnglish = [];
        $usedSpanish = [];
        foreach ($items as $itemIndex => &$item) {
            $item['is_publication_favorite'] = $itemIndex < $explicitFavoriteCount;
            $item['title'] = (string)($item['resolved_title'] ?? $item['title'] ?? '');
            unset($item['resolved_title']);
            $language = function_exists('artist_site_language') ? artist_site_language() : 'es';
            $spanish = $this->localization->content('mockup', (int)($item['mockup_id'] ?? 0), 'es');
            $english = $this->localization->content('mockup', (int)($item['mockup_id'] ?? 0), 'en');
            $localized = $this->localization->content('mockup', (int)($item['mockup_id'] ?? 0), $language);
            $item['spanish_available'] = $spanish !== [];
            if ($localized !== []) {
                $fallback = $language === 'es' ? '' : null;
                $item['description'] = (string)($localized['description'] ?? $fallback ?? $item['description']);
                $item['keywords'] = (string)($localized['search_terms'] ?? $localized['keywords'] ?? $fallback ?? $item['keywords']);
                $item['tags'] = (string)($localized['tags'] ?? $fallback ?? $item['tags']);
                $item['alt_text'] = (string)($localized['alt_text'] ?? $fallback ?? $item['alt_text']);
                $item['caption'] = (string)($localized['caption'] ?? $fallback ?? $item['caption']);
                $item['seo_title'] = (string)($localized['seo_title'] ?? '');
                $item['seo_description'] = (string)($localized['seo_description'] ?? '');
                $item['translation_missing_fields'] = array_values(array_diff([
                    'description',
                    'tags',
                    'alt_text',
                    'caption',
                    'seo_title',
                    'seo_description',
                ], array_keys($localized)));
                if ($language === 'es' && $item['translation_missing_fields'] !== []) {
                    error_log('Incomplete Spanish mockup translation for mockup '
                        . (int)($item['mockup_id'] ?? 0) . ': ' . implode(',', $item['translation_missing_fields']));
                }
            } elseif ($language === 'es') {
                $item['description'] = '';
                $item['keywords'] = '';
                $item['tags'] = '';
                $item['alt_text'] = '';
                $item['caption'] = '';
                $item['seo_title'] = '';
                $item['seo_description'] = '';
                $item['translation_missing_fields'] = ['translation'];
                error_log('Missing published Spanish mockup translation for mockup '
                    . (int)($item['mockup_id'] ?? 0));
            }
            $technicalContexts = PublicSlug::technicalMockupContexts(
                (string)($item['mockup_selector_state_json'] ?? ''),
                (string)($item['mockup_context_id'] ?? ''),
                (string)($item['mockup_file'] ?? '')
            );
            $fallbackTitle = trim((string)($item['title'] ?? ''));
            $englishContext = PublicSlug::mockupContext(
                $artworkTitle,
                $english,
                $fallbackTitle !== '' ? $fallbackTitle : $technicalContexts['en']
            );
            $spanishContext = PublicSlug::mockupContext($artworkTitle, $spanish, $technicalContexts['es']);
            $item['public_slug_en'] = PublicSlug::uniqueMockup(
                PublicSlug::mockup($artworkSlug, $englishContext),
                $usedEnglish
            );
            $item['public_slug_es'] = PublicSlug::uniqueMockup(
                PublicSlug::mockup($artworkSlug, $spanishContext),
                $usedSpanish
            );
            $item['public_slug'] = $language === 'es' ? $item['public_slug_es'] : $item['public_slug_en'];
        }
        unset($item);
        return $items;
    }

    /**
     * Publication items are the artist's explicit favorites and keep their saved
     * order. Append every other canonical mockup afterwards so older or incomplete
     * publications do not lose the artwork's visual context.
     *
     * @return array<int,array<string,mixed>>
     */
    private function relatedItems(int $publicationId): array
    {
        $contextSelect = $this->mockupColumnExists('context_id')
            ? "COALESCE((SELECT source.context_id FROM mockups source WHERE source.user_id=m.user_id AND (source.id=m.mockup_id OR source.mockup_file=m.mockup_file) ORDER BY source.id DESC LIMIT 1),'')"
            : "''";
        $selectorSelect = $this->mockupColumnExists('selector_state_json')
            ? "COALESCE((SELECT source.selector_state_json FROM mockups source WHERE source.user_id=m.user_id AND (source.id=m.mockup_id OR source.mockup_file=m.mockup_file) ORDER BY source.id DESC LIMIT 1),'')"
            : "''";
        $statement = $this->pdo->prepare("SELECT
                0 id,p.id publication_id,m.id mockup_sheet_id,m.id position,
                'context' role,m.title title,m.alt_text,m.caption,
                COALESCE(NULLIF(m.mockup_id,0),(
                    SELECT source.id FROM mockups source
                    WHERE source.user_id=m.user_id AND source.mockup_file=m.mockup_file
                    ORDER BY source.id DESC LIMIT 1
                )) mockup_id,
                {$contextSelect} mockup_context_id,
                {$selectorSelect} mockup_selector_state_json,
                m.mockup_file,m.description,m.keywords,m.tags,
                m.alt_text mockup_alt_text,m.caption mockup_caption
            FROM publications p
            INNER JOIN artwork_sheets sh ON sh.id=p.artwork_sheet_id AND sh.user_id=p.user_id
            INNER JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=sh.user_id
            INNER JOIN mockup_sheets m ON m.user_id=p.user_id AND (
                m.artwork_id=a.id OR m.artwork_sheet_id=sh.id OR
                (COALESCE(a.artwork_group_id,0)>0 AND m.artwork_group_id=a.artwork_group_id)
            )
            WHERE p.id=? AND EXISTS (
                SELECT 1 FROM mockups live
                WHERE live.user_id=m.user_id AND (
                    live.id=m.mockup_id OR live.mockup_file=m.mockup_file
                )
            ) AND NOT EXISTS (
                SELECT 1 FROM mockup_sheets newer
                WHERE newer.user_id=m.user_id AND newer.id>m.id AND (
                    (COALESCE(m.mockup_id,0)>0 AND newer.mockup_id=m.mockup_id) OR
                    (COALESCE(m.mockup_id,0)=0 AND newer.mockup_file=m.mockup_file)
                )
            )
            ORDER BY m.id DESC");
        $statement->execute([$publicationId]);
        return $statement->fetchAll();
    }

    private function mockupColumnExists(string $column): bool
    {
        if (array_key_exists($column, $this->mockupColumns)) return $this->mockupColumns[$column];
        try {
            if ((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $statement = $this->pdo->prepare('SHOW COLUMNS FROM mockups LIKE ?');
                $statement->execute([$column]);
                return $this->mockupColumns[$column] = (bool)$statement->fetchColumn();
            }
            foreach ($this->pdo->query('PRAGMA table_info(mockups)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string)($row['name'] ?? '') === $column) return $this->mockupColumns[$column] = true;
            }
        } catch (PDOException) {
        }
        return $this->mockupColumns[$column] = false;
    }

    private function artworkViews(int $artworkId): array
    {
        if ($artworkId <= 0) return [];
        $statement = $this->pdo->prepare('SELECT file_name,view_type FROM root_artwork_candidates
            WHERE artwork_id=?
            ORDER BY id');
        $statement->execute([$artworkId]);
        return $statement->fetchAll();
    }

    private function publishedVideo(int $userId, int $artworkId): ?array
    {
        try {
            $statement = $this->pdo->prepare("SELECT e.id,e.duration_seconds,e.aspect_ratio,e.completed_at,e.updated_at,
                    e.timeline_snapshot_json,avp.published_at
                FROM artwork_video_publications avp
                INNER JOIN video_exports e ON e.id=avp.video_export_id AND e.user_id=avp.user_id
                WHERE avp.user_id=? AND avp.artwork_id=?
                    AND e.status='succeeded' AND e.output_path<>''
                ORDER BY avp.id DESC LIMIT 1");
            $statement->execute([$userId, $artworkId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return null;
            $snapshot = json_decode((string)($row['timeline_snapshot_json'] ?? ''), true);
            if (!is_array($snapshot)) $snapshot = [];
            return [
                'id' => (int)$row['id'],
                'duration_seconds' => (float)$row['duration_seconds'],
                'aspect_ratio' => (string)$row['aspect_ratio'],
                'has_poster' => trim((string)($snapshot['thumbnailPath'] ?? '')) !== '',
                'published_at' => (string)$row['published_at'],
                'updated_at' => (string)($row['completed_at'] ?: $row['updated_at']),
            ];
        } catch (PDOException) {
            return null;
        }
    }

    private static function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii)), '-');
    }
}
