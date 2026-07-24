<?php
declare(strict_types=1);

require_once __DIR__ . '/AppPublishedLocalization.php';

final class AppPublishedCatalog
{
    private AppPublishedLocalization $localization;

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
        $statement = $this->pdo->prepare("SELECT p.*, a.source_image_file, a.canonical_artwork_id, a.subtitle,
                a.title artwork_title,a.description artwork_description,a.short_description artwork_short_description,
                a.caption artwork_caption,a.generated_json artwork_generated_json,aw.medium,
                aw.artwork_year, aw.series, aw.width, aw.height, aw.depth, aw.unit, a.alt_text artwork_alt,
                a.keywords artwork_keywords, a.tags artwork_tags
            FROM publications p
            JOIN users u ON u.id=p.user_id
            JOIN artwork_sheets a ON a.id=p.artwork_sheet_id AND a.user_id=p.user_id
            LEFT JOIN artworks aw ON aw.id=a.canonical_artwork_id AND aw.user_id=p.user_id
            WHERE LOWER(u.email)=? AND p.status='published' AND p.visibility IN ('public','unlisted')
            ORDER BY CASE WHEN p.display_order>0 THEN 0 ELSE 1 END,
                p.display_order ASC,COALESCE(p.published_at,p.updated_at) DESC,p.id DESC");
        $statement->execute([$this->artistEmail]);
        $publications = [];
        foreach ($statement as $row) {
            $language = function_exists('artist_site_language') ? artist_site_language() : 'es';
            $spanish = $this->localization->content('artwork', (int)$row['canonical_artwork_id'], 'es');
            $localized = $this->localization->content('artwork', (int)$row['canonical_artwork_id'], $language);
            $row['spanish_available'] = $spanish !== [];
            if ($localized !== []) {
                $row['subtitle'] = (string)($localized['subtitle'] ?? $row['subtitle']);
                $row['artwork_description'] = (string)($localized['description'] ?? $row['artwork_description']);
                $row['artwork_short_description'] = (string)($localized['short_description'] ?? $row['artwork_short_description']);
                $row['artwork_caption'] = (string)($localized['caption'] ?? $row['artwork_caption']);
                $row['artwork_alt'] = (string)($localized['alt_text'] ?? $row['artwork_alt']);
                $row['artwork_keywords'] = (string)($localized['search_terms'] ?? $localized['keywords'] ?? $row['artwork_keywords']);
                $row['artwork_tags'] = (string)($localized['tags'] ?? $row['artwork_tags']);
                $row['seo_title'] = (string)($localized['seo_title'] ?? '');
                $row['seo_description'] = (string)($localized['seo_description'] ?? '');
            } elseif ($language === 'es') {
                $row['subtitle'] = '';
                $row['artwork_description'] = '';
                $row['artwork_short_description'] = '';
                $row['artwork_caption'] = '';
                $row['artwork_alt'] = '';
                $row['artwork_keywords'] = '';
                $row['artwork_tags'] = '';
                $row['seo_title'] = '';
                $row['seo_description'] = '';
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
            $row['items'] = $this->items((int)$row['id']);
            $row['artwork_views'] = $this->artworkViews((int)$row['canonical_artwork_id']);
            $row['header_file'] = $this->headerFileForArtwork((int)$row['user_id'], $row);
            $publications[(string)$row['slug']] = $row;
        }
        return $publications;
    }

    public function one(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
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
        foreach ($artwork['items'] as $item) {
            if ($item['public_slug'] === $mockupSlug) return ['artwork' => $artwork, 'mockup' => $item];
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
                WHERE sh.id=? AND sh.user_id=? AND m.mockup_file=?
                LIMIT 1');
            $statement->execute([$artworkSheetId, $userId, $file]);
            return (bool)$statement->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    private function items(int $publicationId): array
    {
        $statement = $this->pdo->prepare('SELECT i.*,
                COALESCE(NULLIF(m.mockup_id,0),(
                    SELECT source.id FROM mockups source
                    WHERE source.user_id=m.user_id AND source.mockup_file=m.mockup_file
                    ORDER BY source.id DESC LIMIT 1
                )) mockup_id,
                m.mockup_file,m.description,m.keywords,m.tags,m.alt_text mockup_alt_text,m.caption mockup_caption
            FROM publication_items i JOIN mockup_sheets m ON m.id=i.mockup_sheet_id
            WHERE i.publication_id=? ORDER BY i.position,i.id');
        $statement->execute([$publicationId]);
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
            $language = function_exists('artist_site_language') ? artist_site_language() : 'es';
            $spanish = $this->localization->content('mockup', (int)($item['mockup_id'] ?? 0), 'es');
            $localized = $this->localization->content('mockup', (int)($item['mockup_id'] ?? 0), $language);
            $item['spanish_available'] = $spanish !== [];
            if ($localized !== []) {
                $item['description'] = (string)($localized['description'] ?? $item['description']);
                $item['keywords'] = (string)($localized['search_terms'] ?? $localized['keywords'] ?? $item['keywords']);
                $item['tags'] = (string)($localized['tags'] ?? $item['tags']);
                $item['alt_text'] = (string)($localized['alt_text'] ?? $item['alt_text']);
                $item['caption'] = (string)($localized['caption'] ?? $item['caption']);
                $item['seo_title'] = (string)($localized['seo_title'] ?? '');
                $item['seo_description'] = (string)($localized['seo_description'] ?? '');
            } elseif ($language === 'es') {
                $item['description'] = '';
                $item['keywords'] = '';
                $item['tags'] = '';
                $item['alt_text'] = '';
                $item['caption'] = '';
                $item['seo_title'] = '';
                $item['seo_description'] = '';
            }
            $base = self::slug((string)($item['title'] ?: 'mockup')) ?: 'mockup';
            $item['public_slug'] = $base . '-' . (int)$item['mockup_sheet_id'];
        }
        unset($item);
        return $items;
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

    private static function slug(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        return trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii)), '-');
    }
}
