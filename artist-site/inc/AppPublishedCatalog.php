<?php
declare(strict_types=1);

final class AppPublishedCatalog
{
    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare("SELECT p.*, a.source_image_file, a.canonical_artwork_id, a.subtitle, aw.medium,
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
            $snapshot = json_decode((string)($row['metadata_snapshot_json'] ?? ''), true);
            $row['artwork_metadata'] = is_array($snapshot) ? $snapshot : [];
            $analysis = json_decode((string)($row['artwork_metadata']['generated_json'] ?? ''), true);
            $row['artwork_analysis'] = is_array($analysis) ? $analysis : [];
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
        $statement = $this->pdo->prepare('SELECT i.*,m.mockup_file,m.description,m.keywords,m.tags
            FROM publication_items i JOIN mockup_sheets m ON m.id=i.mockup_sheet_id
            WHERE i.publication_id=? ORDER BY i.position,i.id');
        $statement->execute([$publicationId]);
        $items = $statement->fetchAll();
        foreach ($items as &$item) {
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
