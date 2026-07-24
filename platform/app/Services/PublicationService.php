<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/PublicSlug.php';

final class PublicationService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS publications (
            id {$id}, user_id {$integer} NOT NULL, artwork_sheet_id {$integer} NOT NULL,
            slug VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL DEFAULT '', description {$text} NOT NULL,
            short_description {$text} NOT NULL, language VARCHAR(20) NOT NULL DEFAULT 'en',
            objective VARCHAR(40) NOT NULL DEFAULT 'portfolio', cta_label VARCHAR(120) NOT NULL DEFAULT '',
            cta_url {$text} NOT NULL, visibility VARCHAR(20) NOT NULL DEFAULT 'private', status VARCHAR(30) NOT NULL DEFAULT 'draft',
            content_source VARCHAR(20) NOT NULL DEFAULT 'inherit',
            profile_snapshot_json {$text} NOT NULL, metadata_snapshot_json {$text} NOT NULL,
            published_at VARCHAR(40) NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        try {
            $this->pdo->exec("ALTER TABLE publications ADD COLUMN header_file VARCHAR(255) NOT NULL DEFAULT ''");
        } catch (Throwable $e) {
            // Columna ya existe o error en alter table
        }
        try {
            $this->pdo->exec('ALTER TABLE publications ADD COLUMN display_order INTEGER NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
            // Column already exists.
        }
        try {
            $this->pdo->exec("ALTER TABLE publications ADD COLUMN content_source VARCHAR(20) NOT NULL DEFAULT 'inherit'");
        } catch (Throwable $e) {
            // Column already exists.
        }
        $this->backfillDisplayOrder();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS publication_items (
            id {$id}, publication_id {$integer} NOT NULL, mockup_sheet_id {$integer} NOT NULL,
            position INTEGER NOT NULL DEFAULT 0, role VARCHAR(30) NOT NULL DEFAULT 'context',
            title VARCHAR(255) NOT NULL DEFAULT '', alt_text {$text} NOT NULL, caption {$text} NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS channel_variants (
            id {$id}, publication_id {$integer} NOT NULL, channel VARCHAR(40) NOT NULL,
            format VARCHAR(50) NOT NULL DEFAULT 'image', title VARCHAR(255) NOT NULL DEFAULT '',
            description {$text} NOT NULL, hashtags {$text} NOT NULL, keywords {$text} NOT NULL,
            destination_url {$text} NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'draft', updated_at VARCHAR(40) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS distribution_jobs (
            id {$id}, publication_id {$integer} NOT NULL, channel VARCHAR(40) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'not_connected', external_id VARCHAR(255) NOT NULL DEFAULT '',
            external_url {$text} NOT NULL, idempotency_key VARCHAR(255) NOT NULL,
            payload_json {$text} NOT NULL, error {$text} NOT NULL, created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS channel_accounts (
            id {$id}, user_id {$integer} NOT NULL, provider VARCHAR(40) NOT NULL,
            external_account_id VARCHAR(255) NOT NULL DEFAULT '', display_name VARCHAR(255) NOT NULL DEFAULT '',
            scopes {$text} NOT NULL, credentials_json {$text} NOT NULL, expires_at VARCHAR(40) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'disconnected', created_at VARCHAR(40) NOT NULL, updated_at VARCHAR(40) NOT NULL
        )");
    }

    public function createForSheet(int $sheetId, int $userId): int
    {
        $existing = $this->pdo->prepare('SELECT id FROM publications WHERE artwork_sheet_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
        $existing->execute([$sheetId, $userId]);
        if ($existingId = $existing->fetchColumn()) {
            return (int)$existingId;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM artwork_sheets WHERE id = ? AND user_id = ?');
        $stmt->execute([$sheetId, $userId]);
        $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sheet) {
            throw new RuntimeException('Ficha no encontrada.');
        }
        $profile = ArtistProfile::findForUser($userId);
        $slug = $this->uniqueSlug(PublicSlug::universal((string)$sheet['title'], 'obra-' . $sheetId), $userId);
        $now = date('c');
        $this->pdo->prepare('INSERT INTO publications
            (user_id, artwork_sheet_id, slug, title, description, short_description, language, objective, cta_label, cta_url, visibility, status, content_source, profile_snapshot_json, metadata_snapshot_json, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $userId, $sheetId, $slug, (string)$sheet['title'], (string)$sheet['description'],
                (string)$sheet['short_description'], 'en', 'portfolio', 'Inquire about this artwork', '',
                'private', 'draft', 'inherit', json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                json_encode($sheet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now,
            ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->seedVariants($id, $sheet);
        return $id;
    }

    public function get(int $id, int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT p.*,s.title inherited_title,s.description inherited_description,s.short_description inherited_short_description
            FROM publications p
            JOIN artwork_sheets s ON s.id=p.artwork_sheet_id AND s.user_id=p.user_id
            WHERE p.id = ? AND p.user_id = ?');
        $stmt->execute([$id, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Publicación no encontrada.');
        $row = $this->withEffectiveContent($row);
        $row['items'] = $this->items($id);
        $row['variants'] = $this->variants($id);
        return $row;
    }

    public function findForSheet(int $sheetId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM publications WHERE artwork_sheet_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$sheetId, $userId]);
        $id = (int)($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $this->get($id, $userId) : null;
    }

    /** @param array<string,mixed> $input */
    public function saveWebsiteSettings(int $sheetId, int $userId, array $input, string $intent = 'save'): array
    {
        $publicationId = $this->createForSheet($sheetId, $userId);

        $sheetStmt = $this->pdo->prepare('SELECT title,description,short_description FROM artwork_sheets WHERE id=? AND user_id=?');
        $sheetStmt->execute([$sheetId, $userId]);
        $sheet = $sheetStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sheet) throw new RuntimeException('Artwork Metadata was not found.');

        $content = [
            'title' => trim((string)$sheet['title']),
            'short_description' => trim((string)$sheet['short_description']),
            'description' => trim((string)$sheet['description']),
        ];

        $this->pdo->prepare("UPDATE publications SET content_source='inherit' WHERE id=? AND user_id=?")
            ->execute([$publicationId, $userId]);
        $this->save($publicationId, $userId, $content + [
            'visibility' => (string)($input['visibility'] ?? 'public'),
            'publish' => $intent === 'publish',
            'unpublish' => $intent === 'unpublish',
        ], $this->favoriteMockupSheetIds($sheetId, $userId));
        return $this->get($publicationId, $userId);
    }

    public function syncInheritedFromSheet(int $sheetId, int $userId): void
    {
        $sheet = $this->pdo->prepare('SELECT title,description,short_description FROM artwork_sheets WHERE id=? AND user_id=?');
        $sheet->execute([$sheetId, $userId]);
        $content = $sheet->fetch(PDO::FETCH_ASSOC);
        if (!$content) return;
        $publicationIds = $this->pdo->prepare('SELECT id FROM publications WHERE artwork_sheet_id=? AND user_id=? ORDER BY id');
        $publicationIds->execute([$sheetId, $userId]);
        foreach ($publicationIds->fetchAll(PDO::FETCH_COLUMN) as $publicationId) {
            $slug = $this->uniqueSlug(
                PublicSlug::universal((string)$content['title'], 'obra-' . $sheetId),
                $userId,
                (int)$publicationId
            );
            $this->pdo->prepare("UPDATE publications
            SET slug=?,title=?,description=?,short_description=?,updated_at=?
            WHERE id=? AND user_id=?")
            ->execute([
                $slug,
                trim((string)$content['title']),
                trim((string)$content['description']),
                trim((string)$content['short_description']),
                date('c'), (int)$publicationId, $userId,
            ]);
        }
    }

    public function listForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT p.*, a.source_image_file, a.subtitle,
            a.title inherited_title,a.description inherited_description,a.short_description inherited_short_description,
            (SELECT COUNT(*) FROM publication_items i WHERE i.publication_id = p.id) AS item_count
            FROM publications p JOIN artwork_sheets a ON a.id = p.artwork_sheet_id
            WHERE p.user_id = ? ORDER BY p.updated_at DESC, p.id DESC');
        $stmt->execute([$userId]);
        return array_map(fn(array $row): array => $this->withEffectiveContent($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function savePinterestDraft(int $publicationId, int $userId, int $mockupSheetId, string $board, string $destinationUrl): int
    {
        $publication = $this->get($publicationId, $userId);
        $stmt = $this->pdo->prepare('SELECT * FROM mockup_sheets WHERE id=? AND user_id=? AND artwork_sheet_id=?');
        $stmt->execute([$mockupSheetId, $userId, (int)$publication['artwork_sheet_id']]);
        $mockup = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mockup) throw new RuntimeException('Mockup not found.');
        $key = hash('sha256', 'pinterest|' . $publicationId . '|' . $mockupSheetId);
        $payload = json_encode(['mockup_sheet_id'=>$mockupSheetId,'board'=>$board,'destination_url'=>$destinationUrl,
            'title'=>$mockup['title'],'description'=>$mockup['description'],'alt_text'=>$mockup['alt_text'],
            'keywords'=>$mockup['keywords'],'tags'=>$mockup['tags']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = $this->pdo->prepare('SELECT id FROM distribution_jobs WHERE publication_id=? AND channel=? AND idempotency_key=? ORDER BY id DESC LIMIT 1');
        $existing->execute([$publicationId, 'pinterest', $key]);
        $id = (int)($existing->fetchColumn() ?: 0); $now = date('c');
        if ($id > 0) {
            $this->pdo->prepare("UPDATE distribution_jobs SET status='draft', payload_json=?, error='', updated_at=? WHERE id=?")->execute([$payload,$now,$id]);
            return $id;
        }
        $this->pdo->prepare("INSERT INTO distribution_jobs (publication_id,channel,status,external_id,external_url,idempotency_key,payload_json,error,created_at,updated_at) VALUES (?,'pinterest','draft','','',?,?,'',?,?)")->execute([$publicationId,$key,$payload,$now,$now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function publicBySlug(string $slug): array
    {
        $stmt = $this->pdo->prepare("SELECT p.*, a.source_image_file, a.subtitle, a.caption, a.alt_text, a.keywords, a.tags,
                a.title inherited_title,a.description inherited_description,a.short_description inherited_short_description
            FROM publications p JOIN artwork_sheets a ON a.id = p.artwork_sheet_id
            WHERE p.slug = ? AND p.status = 'published' AND p.visibility IN ('public','unlisted') LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Publicación no encontrada.');
        $row = $this->withEffectiveContent($row);
        $row['items'] = $this->items((int)$row['id']);
        return $row;
    }

    public function save(int $id, int $userId, array $input, ?array $mockupIds = null): void
    {
        $publication = $this->get($id, $userId);
        $allowedVisibility = ['private', 'unlisted', 'public'];
        $visibility = in_array($input['visibility'] ?? '', $allowedVisibility, true) ? $input['visibility'] : $publication['visibility'];
        $status = ($input['publish'] ?? false) && $visibility !== 'private' ? 'published' : (($input['unpublish'] ?? false) ? 'draft' : $publication['status']);
        if (($input['publish'] ?? false) && $status === 'published') {
            $this->assertAssociatedSeriesIsPublished((int)$publication['artwork_sheet_id'], $userId);
        }
        $publishedAt = $status === 'published' ? ($publication['published_at'] ?: date('c')) : null;
        $this->pdo->prepare('UPDATE publications SET title=?, description=?, short_description=?, language=?, objective=?, cta_label=?, cta_url=?, visibility=?, status=?, published_at=?, updated_at=? WHERE id=? AND user_id=?')->execute([
            trim((string)($input['title'] ?? $publication['title'])), trim((string)($input['description'] ?? $publication['description'])), trim((string)($input['short_description'] ?? $publication['short_description'])),
            'en', trim((string)($input['objective'] ?? $publication['objective'])),
            trim((string)($input['cta_label'] ?? $publication['cta_label'])), trim((string)($input['cta_url'] ?? $publication['cta_url'])), $visibility, $status, $publishedAt, date('c'), $id, $userId,
        ]);
        if ($status === 'published' && (int)($publication['display_order'] ?? 0) <= 0) {
            $next = $this->pdo->prepare('SELECT COALESCE(MAX(display_order),0)+10 FROM publications WHERE user_id=? AND status=\'published\'');
            $next->execute([$userId]);
            $this->pdo->prepare('UPDATE publications SET display_order=? WHERE id=? AND user_id=?')
                ->execute([(int)$next->fetchColumn(), $id, $userId]);
        }
        // A null $mockupIds means "leave the current mockup selection untouched" (status/visibility/text-only changes).
        if ($mockupIds !== null) {
            $this->pdo->prepare('DELETE FROM publication_items WHERE publication_id = ?')->execute([$id]);
            $valid = $this->pdo->prepare('SELECT * FROM mockup_sheets WHERE id = ? AND user_id = ? AND artwork_sheet_id = ?');
            $insert = $this->pdo->prepare('INSERT INTO publication_items (publication_id,mockup_sheet_id,position,role,title,alt_text,caption) VALUES (?,?,?,?,?,?,?)');
            foreach (array_values(array_unique(array_map('intval', $mockupIds))) as $position => $mockupId) {
                $valid->execute([$mockupId, $userId, (int)$publication['artwork_sheet_id']]);
                if ($mockup = $valid->fetch(PDO::FETCH_ASSOC)) {
                    $insert->execute([$id, $mockupId, $position, $position === 0 ? 'cover' : 'context', $mockup['title'], $mockup['alt_text'], $mockup['caption']]);
                }
            }
        }
        $this->refreshVariantUrls($id);
    }

    private function backfillDisplayOrder(): void
    {
        $rows = $this->pdo->query("SELECT id,user_id,display_order
            FROM publications
            WHERE status='published'
            ORDER BY user_id,COALESCE(published_at,updated_at) DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return;

        $maximums = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $maximums[$userId] = max((int)($maximums[$userId] ?? 0), (int)$row['display_order']);
        }
        $next = $maximums;
        $update = $this->pdo->prepare('UPDATE publications SET display_order=? WHERE id=? AND display_order=0');
        foreach ($rows as $row) {
            if ((int)$row['display_order'] > 0) continue;
            $userId = (int)$row['user_id'];
            $next[$userId] = (int)($next[$userId] ?? 0) + 10;
            $update->execute([$next[$userId], (int)$row['id']]);
        }
    }

    public function assertAssociatedSeriesIsPublished(int $artworkSheetId, int $userId): void
    {
        $stmt = $this->pdo->prepare("SELECT a.series_id, s.title, s.published, s.status
            FROM artwork_sheets sh
            INNER JOIN artworks a ON a.id = sh.canonical_artwork_id AND a.user_id = sh.user_id
            LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
            WHERE sh.id = ? AND sh.user_id = ?
            LIMIT 1");
        $stmt->execute([$artworkSheetId, $userId]);
        $series = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$series || (int)($series['series_id'] ?? 0) <= 0) {
            return;
        }
        if ((int)($series['published'] ?? 0) === 1 && (string)($series['status'] ?? '') === 'active') {
            return;
        }

        $title = trim((string)($series['title'] ?? '')) ?: 'sin nombre';
        throw new RuntimeException('No se puede publicar la obra. Publica primero la serie asociada «' . $title . '».');
    }

    public function remove(int $id, int $userId): void
    {
        $this->get($id, $userId); // asserts ownership, throws if not found
        $this->pdo->prepare('DELETE FROM publication_items WHERE publication_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM channel_variants WHERE publication_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM distribution_jobs WHERE publication_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM publications WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
    }

    private function seedVariants(int $publicationId, array $sheet): void
    {
        $keywords = (string)($sheet['keywords'] ?? '');
        $tags = array_filter(array_map('trim', preg_split('/[,;]+/', (string)($sheet['tags'] ?? '')) ?: []));
        $hashtags = implode(' ', array_map(fn($tag) => '#' . preg_replace('/[^\pL\pN]+/u', '', $tag), array_slice($tags, 0, 12)));
        $insert = $this->pdo->prepare('INSERT INTO channel_variants (publication_id,channel,format,title,description,hashtags,keywords,destination_url,status,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ([['pinterest','vertical_pin'],['website','landing'],['instagram_facebook','carousel'],['tiktok','photo_story']] as [$channel,$format]) {
            $insert->execute([$publicationId, $channel, $format, (string)$sheet['title'], (string)($sheet['short_description'] ?: $sheet['description']), $hashtags, $keywords, '', 'draft', date('c')]);
        }
    }

    private function refreshVariantUrls(int $id): void
    {
        $slug = (string)$this->pdo->query('SELECT slug FROM publications WHERE id=' . (int)$id)->fetchColumn();
        $this->pdo->prepare('UPDATE channel_variants SET destination_url=?, updated_at=? WHERE publication_id=?')->execute(['public_artwork.php?slug=' . rawurlencode($slug), date('c'), $id]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function withEffectiveContent(array $row): array
    {
        $row['title'] = (string)($row['inherited_title'] ?? $row['title'] ?? '');
        $row['description'] = (string)($row['inherited_description'] ?? $row['description'] ?? '');
        $row['short_description'] = (string)($row['inherited_short_description'] ?? $row['short_description'] ?? '');
        unset($row['inherited_title'], $row['inherited_description'], $row['inherited_short_description']);
        return $row;
    }

    private function items(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT i.*, m.mockup_file, m.description, m.keywords, m.tags FROM publication_items i JOIN mockup_sheets m ON m.id=i.mockup_sheet_id WHERE i.publication_id=? ORDER BY i.position,i.id');
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,int> */
    private function favoriteMockupSheetIds(int $sheetId, int $userId): array
    {
        if (!class_exists('MockupFavorites')) return [];
        $favoriteIds = MockupFavorites::idsForUser($userId);
        if (!$favoriteIds) return [];

        $marks = implode(',', array_fill(0, count($favoriteIds), '?'));
        $stmt = $this->pdo->prepare("SELECT m.id mockup_id,MAX(ms.id) mockup_sheet_id
            FROM mockups m
            INNER JOIN mockup_sheets ms ON ms.user_id=m.user_id
                AND (ms.mockup_id=m.id OR ms.mockup_file=m.mockup_file)
            WHERE m.user_id=? AND ms.artwork_sheet_id=? AND m.id IN ($marks)
            GROUP BY m.id");
        $stmt->execute(array_merge([$userId, $sheetId], $favoriteIds));

        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mockupId = (int)$row['mockup_id'];
            $mockupSheetId = (int)$row['mockup_sheet_id'];
            if ($mockupId <= 0 || $mockupSheetId <= 0) continue;
            $found[$mockupId] = $mockupSheetId;
            $this->pdo->prepare('UPDATE mockup_sheets SET mockup_id=? WHERE id=? AND user_id=? AND (mockup_id IS NULL OR mockup_id=0)')
                ->execute([$mockupId, $mockupSheetId, $userId]);
        }

        $ordered = [];
        foreach ($favoriteIds as $favoriteId) {
            if (isset($found[$favoriteId])) $ordered[] = $found[$favoriteId];
        }
        return $ordered;
    }

    private function variants(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM channel_variants WHERE publication_id=? ORDER BY id');
        $stmt->execute([$id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function uniqueSlug(string $base, int $userId, ?int $ignoreId = null): string
    {
        $base = $base ?: 'obra'; $slug = $base; $n = 2;
        $sql = 'SELECT 1 FROM publications WHERE user_id=? AND slug=?';
        $params = [$userId, $slug];
        if ($ignoreId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $ignoreId;
        }
        $stmt = $this->pdo->prepare($sql);
        while (true) {
            $params[1] = $slug;
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) return $slug;
            $slug = $base . '-' . $n++;
        }
    }

    private function slug(string $value): string
    {
        return PublicSlug::normalize($value);
    }
}
