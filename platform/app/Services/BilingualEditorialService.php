<?php
declare(strict_types=1);

final class BilingualEditorialService
{
    private const ENTITY_TYPES = ['series', 'artwork', 'mockup'];
    private const LOCALES = ['es', 'en'];

    public function __construct(private PDO $pdo)
    {
    }

    public function isEnabled(int $userId): bool
    {
        if ($userId <= 0) return false;
        $stmt = $this->pdo->prepare('SELECT enabled FROM bilingual_editorial_settings WHERE user_id=? LIMIT 1');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn() === 1;
    }

    public function setEnabled(int $userId, bool $enabled): void
    {
        $now = date(DATE_ATOM);
        if ($this->isMysql()) {
            $stmt = $this->pdo->prepare("INSERT INTO bilingual_editorial_settings
                (user_id,enabled,source_locale,publication_locale,created_at,updated_at)
                VALUES (?,?, 'es','en',?,?)
                ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),source_locale='es',publication_locale='en',updated_at=VALUES(updated_at)");
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO bilingual_editorial_settings
                (user_id,enabled,source_locale,publication_locale,created_at,updated_at)
                VALUES (?,?, 'es','en',?,?)
                ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled,source_locale='es',publication_locale='en',updated_at=excluded.updated_at");
        }
        $stmt->execute([$userId, $enabled ? 1 : 0, $now, $now]);
    }

    public function sourceLocale(int $userId): string
    {
        return 'es';
    }

    /**
     * Resolves the initial arrow direction on the server so the control is not
     * dependent on JavaScript merely to become visible.
     *
     * @return array{source:string,target:string,direction:string,label:string}
     */
    public function adaptationDirection(array $spanish, array $english): array
    {
        if ($this->hasMeaningfulContent($spanish) && $this->hasMissingContent($spanish, $english)) {
            return ['source' => 'es', 'target' => 'en', 'direction' => 'es-en', 'label' => 'Completar inglés internacional'];
        }
        return ['source' => '', 'target' => '', 'direction' => '', 'label' => 'Inglés internacional completo'];
    }

    /** @return array{content:array,private_memo:string,status:string,source_hash:string,is_published:bool,published_content:array,published_at:string,has_unpublished_changes:bool} */
    public function get(int $userId, string $entityType, int $entityId, string $locale, array $fallback = []): array
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $stmt = $this->pdo->prepare('SELECT content_json,private_memo,status,source_hash,is_published,published_content_json,published_at FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale=? LIMIT 1');
        $stmt->execute([$userId, $entityType, $entityId, $locale]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['content' => $this->normalizeContent($fallback), 'private_memo' => '', 'status' => $locale === 'en' && $fallback !== [] ? 'current' : 'unprepared', 'source_hash' => '', 'is_published' => false, 'published_content' => [], 'published_at' => '', 'has_unpublished_changes' => false];
        }
        $content = json_decode((string)$row['content_json'], true);
        $publishedContent = json_decode((string)($row['published_content_json'] ?? ''), true);
        $normalizedContent = is_array($content) ? $this->normalizeContent($content) : [];
        $normalizedPublishedContent = is_array($publishedContent) ? $this->normalizeContent($publishedContent) : [];
        $isPublished = (int)($row['is_published'] ?? 0) === 1;
        $status = trim((string)$row['status']);
        if ($locale === 'en' && $status === 'current') {
            $sourceStmt = $this->pdo->prepare("SELECT content_json FROM bilingual_editorial_content WHERE user_id=? AND entity_type=? AND entity_id=? AND locale='es' LIMIT 1");
            $sourceStmt->execute([$userId, $entityType, $entityId]);
            $sourceContent = json_decode((string)$sourceStmt->fetchColumn(), true);
            $normalizedSource = is_array($sourceContent) ? $this->normalizeContent($sourceContent) : [];
            if ($this->hasMissingContent($normalizedSource, $normalizedContent)) {
                $status = 'stale';
                $this->pdo->prepare("UPDATE bilingual_editorial_content SET status='stale',updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale='en'")
                    ->execute([date(DATE_ATOM), $userId, $entityType, $entityId]);
            }
        }
        return [
            'content' => $normalizedContent,
            'private_memo' => trim((string)$row['private_memo']),
            'status' => $status,
            'source_hash' => trim((string)$row['source_hash']),
            'is_published' => $isPublished,
            'published_content' => $normalizedPublishedContent,
            'published_at' => trim((string)($row['published_at'] ?? '')),
            'has_unpublished_changes' => $isPublished && $normalizedContent !== $normalizedPublishedContent,
        ];
    }

    public function setSpanishPublished(int $userId, string $entityType, int $entityId, bool $published): array
    {
        $this->assertIdentity($entityType, $entityId, 'es');
        $this->assertOwned($userId, $entityType, $entityId);
        $spanish = $this->get($userId, $entityType, $entityId, 'es');
        if ($published && !$this->hasMeaningfulContent($spanish['content'])) {
            throw new RuntimeException('Add Spanish content before publishing it.');
        }
        if ($published) {
            $stmt = $this->pdo->prepare("UPDATE bilingual_editorial_content SET is_published=1,published_content_json=content_json,published_at=?,updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale='es'");
            $now = date(DATE_ATOM);
            $stmt->execute([$now, $now, $userId, $entityType, $entityId]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE bilingual_editorial_content SET is_published=0,updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale='es'");
            $stmt->execute([date(DATE_ATOM), $userId, $entityType, $entityId]);
        }
        $state = $this->get($userId, $entityType, $entityId, 'es');
        return [
            'is_published' => $state['is_published'],
            'published_at' => $state['published_at'],
            'has_unpublished_changes' => $state['has_unpublished_changes'],
        ];
    }

    /**
     * Stores an explicit AI-assisted adaptation. Existing target fields win, so
     * the arrow can safely complete a partial language without replacing edits.
     *
     * @return array{content:array,status:string,english_status:string}
     */
    public function saveAdaptation(
        int $userId,
        string $entityType,
        int $entityId,
        string $sourceLocale,
        string $targetLocale,
        array $generatedContent
    ): array {
        $this->assertIdentity($entityType, $entityId, $sourceLocale);
        $this->assertIdentity($entityType, $entityId, $targetLocale);
        if ($sourceLocale !== 'es' || $targetLocale !== 'en') {
            throw new InvalidArgumentException('La adaptación editorial permitida es español a inglés internacional.');
        }
        $this->assertOwned($userId, $entityType, $entityId);
        $source = $this->get($userId, $entityType, $entityId, 'es');
        $target = $this->get($userId, $entityType, $entityId, 'en');
        $merged = $this->mergeMissingContent((array)$target['content'], $this->normalizeContent($generatedContent));
        $sourceHash = hash('sha256', json_encode($source['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = $this->hasMissingContent((array)$source['content'], $merged) ? 'stale' : 'current';
        $this->upsertRow($userId, $entityType, $entityId, 'en', $merged, (string)$target['private_memo'], $status, $sourceHash);
        $this->syncEnglishToLegacy($userId, $entityType, $entityId, $merged);
        return ['content' => $merged, 'status' => $status, 'english_status' => $status];
    }

    /**
     * Seeds future Spanish-first artwork analysis without replacing text the
     * artist has already written.
     */
    public function fillSourceFromAnalysis(int $userId, string $entityType, int $entityId, array $analysisContent): array
    {
        $locale = $this->sourceLocale($userId);
        if ($locale !== 'es') return [];
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        $current = $this->get($userId, $entityType, $entityId, $locale);
        $merged = $this->mergeMissingContent($current['content'], $this->normalizeContent($analysisContent));
        if ($merged === $current['content']) return $merged;
        $hash = hash('sha256', json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->upsertRow($userId, $entityType, $entityId, $locale, $merged, (string)$current['private_memo'], 'source', $hash);
        $this->markEnglishStale($userId, $entityType, $entityId);
        return $merged;
    }

    public function seedEnglishFromLegacy(int $userId, string $entityType, int $entityId): array
    {
        return [];
    }

    public function save(int $userId, string $entityType, int $entityId, string $locale, array $content, string $privateMemo = ''): array
    {
        $this->assertIdentity($entityType, $entityId, $locale);
        $this->assertOwned($userId, $entityType, $entityId);
        $content = $this->normalizeContent($content);
        $privateMemo = trim($privateMemo);

        $newHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($locale === 'es') {
            $this->upsertRow($userId, $entityType, $entityId, 'es', $content, $privateMemo, 'source', $newHash);
            $this->markEnglishStale($userId, $entityType, $entityId);
            $english = $this->get($userId, $entityType, $entityId, 'en');
            return ['status' => 'source', 'english_status' => (string)$english['status']];
        }
        $spanish = $this->get($userId, $entityType, $entityId, 'es');
        $sourceHash = hash('sha256', json_encode($spanish['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $status = $this->hasMissingContent((array)$spanish['content'], $content) ? 'stale' : 'current';
        $this->upsertRow($userId, $entityType, $entityId, 'en', $content, $privateMemo, $status, $sourceHash);
        $this->syncEnglishToLegacy($userId, $entityType, $entityId, $content);
        return ['status' => $status, 'english_status' => $status];
    }

    public function saveUniversalTitle(int $userId, string $entityType, int $entityId, string $title): string
    {
        $this->assertIdentity($entityType, $entityId, 'es');
        $this->assertOwned($userId, $entityType, $entityId);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if ($title === '') throw new RuntimeException('The universal title cannot be empty.');
        $now = date(DATE_ATOM);

        if ($entityType === 'series') {
            $stmt = $this->pdo->prepare('UPDATE artwork_series SET title=?,updated_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$title, $now, $entityId, $userId]);
            $this->pdo->prepare('UPDATE artworks SET series=?,updated_at=? WHERE series_id=? AND user_id=?')->execute([$title, $now, $entityId, $userId]);
        } elseif ($entityType === 'artwork') {
            (new ArtworkSheetService($this->pdo))->saveArtworkTitle($entityId, $userId, $title);
        } else {
            $mockup = $this->mockupRow($userId, $entityId);
            $this->ensureMockupSheet($userId, $mockup);
            $this->pdo->prepare('UPDATE mockup_sheets SET title=?,updated_at=? WHERE user_id=? AND (mockup_id=? OR mockup_file=?)')->execute([$title, $now, $userId, $entityId, (string)$mockup['mockup_file']]);
        }
        return $title;
    }

    public function backfillEnglish(int $userId): array
    {
        return ['series' => 0, 'artwork' => 0, 'mockup' => 0];
    }

    private function upsertRow(int $userId, string $entityType, int $entityId, string $locale, array $content, string $memo, string $status, string $sourceHash): void
    {
        $now = date(DATE_ATOM);
        $json = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($this->isMysql()) {
            $sql = "INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE content_json=VALUES(content_json),private_memo=VALUES(private_memo),status=VALUES(status),source_hash=VALUES(source_hash),updated_at=VALUES(updated_at)";
        } else {
            $sql = "INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,content_json,private_memo,status,source_hash,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id,entity_type,entity_id,locale) DO UPDATE SET content_json=excluded.content_json,private_memo=excluded.private_memo,status=excluded.status,source_hash=excluded.source_hash,updated_at=excluded.updated_at";
        }
        $this->pdo->prepare($sql)->execute([$userId, $entityType, $entityId, $locale, $json ?: '{}', $memo, $status, $sourceHash, $now, $now]);
    }

    private function assertOwned(int $userId, string $entityType, int $entityId): void
    {
        $table = $entityType === 'series' ? 'artwork_series' : ($entityType === 'artwork' ? 'artworks' : 'mockups');
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE id=? AND user_id=? LIMIT 1");
        $stmt->execute([$entityId, $userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Editorial item not found.');
    }

    private function mockupRow(int $userId, int $mockupId): array
    {
        $stmt = $this->pdo->prepare('SELECT id,mockup_file,source_artwork_id FROM mockups WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$mockupId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Mockup not found.');
        return $row;
    }

    private function ensureMockupSheet(int $userId, array $mockup): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM mockup_sheets WHERE user_id=? AND (mockup_id=? OR mockup_file=?) ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId, (int)$mockup['id'], (string)$mockup['mockup_file']]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            $this->pdo->prepare('UPDATE mockup_sheets SET mockup_id=? WHERE id=? AND user_id=? AND (mockup_id IS NULL OR mockup_id=0)')
                ->execute([(int)$mockup['id'], $existingId, $userId]);
            return;
        }

        $artworkId = max(0, (int)($mockup['source_artwork_id'] ?? 0));
        if ($artworkId <= 0) throw new RuntimeException('This mockup is not linked to an artwork.');
        $artworkSheet = $this->pdo->prepare('SELECT id FROM artwork_sheets WHERE user_id=? AND canonical_artwork_id=? ORDER BY id DESC LIMIT 1');
        $artworkSheet->execute([$userId, $artworkId]);
        $artworkSheetId = (int)$artworkSheet->fetchColumn();
        $now = date(DATE_ATOM);
        $insert = $this->pdo->prepare("INSERT INTO mockup_sheets
            (user_id,artwork_sheet_id,artwork_id,mockup_id,mockup_file,user_notes,title,description,keywords,tags,alt_text,caption,status,generated_json,created_at,updated_at)
            VALUES (?,?,?,?,?,'','','','','','','','draft','{}',?,?)");
        $insert->execute([$userId, $artworkSheetId > 0 ? $artworkSheetId : null, $artworkId, (int)$mockup['id'], (string)$mockup['mockup_file'], $now, $now]);
    }

    private function assertIdentity(string $entityType, int $entityId, string $locale): void
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true) || $entityId <= 0 || !in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException('Invalid bilingual editorial identity.');
        }
    }

    private function normalizeContent(array $content): array
    {
        $normalized = [];
        foreach ($content as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]/i', '', (string)$key) ?: '';
            if ($key === '') continue;
            if (is_array($value)) $normalized[$key] = $this->normalizeContent($value);
            elseif (is_scalar($value) || $value === null) $normalized[$key] = $this->plainEditorialText((string)$value);
        }
        ksort($normalized);
        return $normalized;
    }

    private function plainEditorialText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $value) ?? $value;
        $value = preg_replace('/\*\*(.*?)\*\*/us', '$1', $value) ?? $value;
        $value = preg_replace('/__(.*?)__/us', '$1', $value) ?? $value;
        $value = preg_replace('/(?<!\*)\*([^*\r\n]+)\*(?!\*)/u', '$1', $value) ?? $value;
        $value = preg_replace('/`([^`\r\n]+)`/u', '$1', $value) ?? $value;
        $value = preg_replace('/^\s*#{1,6}\s+/mu', '', $value) ?? $value;
        return trim(str_replace(['**', '__'], '', $value));
    }

    private function mergeMissingContent(array $existing, array $generated): array
    {
        $merged = $existing;
        foreach ($generated as $key => $value) {
            if (is_array($value)) {
                $current = is_array($merged[$key] ?? null) ? $merged[$key] : [];
                $merged[$key] = $this->mergeMissingContent($current, $value);
                continue;
            }
            if (trim((string)($merged[$key] ?? '')) === '' && trim((string)$value) !== '') {
                $merged[$key] = trim((string)$value);
            }
        }
        ksort($merged);
        return $merged;
    }

    private function hasMeaningfulContent(array $content): bool
    {
        foreach ($content as $value) {
            if (is_array($value) && $this->hasMeaningfulContent($value)) return true;
            if (!is_array($value) && trim((string)$value) !== '') return true;
        }
        return false;
    }

    private function hasMissingContent(array $source, array $target): bool
    {
        foreach ($source as $key => $value) {
            if (is_array($value)) {
                if ($this->hasMissingContent($value, is_array($target[$key] ?? null) ? $target[$key] : [])) return true;
                continue;
            }
            if (trim((string)$value) !== '' && trim((string)($target[$key] ?? '')) === '') return true;
        }
        return false;
    }

    private function markEnglishStale(int $userId, string $entityType, int $entityId): void
    {
        $this->pdo->prepare("UPDATE bilingual_editorial_content SET status='stale',updated_at=? WHERE user_id=? AND entity_type=? AND entity_id=? AND locale='en'")
            ->execute([date(DATE_ATOM), $userId, $entityType, $entityId]);
    }

    private function syncEnglishToLegacy(int $userId, string $entityType, int $entityId, array $content): void
    {
        $now = date(DATE_ATOM);
        if ($entityType === 'series') {
            $this->pdo->prepare('UPDATE artwork_series SET subtitle=?,description=?,long_description=?,tags=?,keywords=?,seo_description=?,updated_at=? WHERE id=? AND user_id=?')
                ->execute([
                    (string)($content['subtitle'] ?? ''),
                    (string)($content['short_description'] ?? ''),
                    (string)($content['description'] ?? ''),
                    (string)($content['tags'] ?? ''),
                    (string)($content['search_terms'] ?? ''),
                    (string)($content['seo_description'] ?? ''),
                    $now, $entityId, $userId,
                ]);
            return;
        }
        if ($entityType === 'artwork') {
            $this->pdo->prepare("UPDATE artwork_sheets SET subtitle=?,description=?,short_description=?,keywords=?,tags=?,alt_text=?,caption=?,updated_at=? WHERE user_id=? AND canonical_artwork_id=? AND COALESCE(status,'')<>'merged'")
                ->execute([
                    (string)($content['subtitle'] ?? ''),
                    (string)($content['description'] ?? ''),
                    (string)($content['short_description'] ?? ''),
                    (string)($content['keywords'] ?? $content['search_terms'] ?? ''),
                    (string)($content['tags'] ?? ''),
                    (string)($content['alt_text'] ?? ''),
                    (string)($content['caption'] ?? ''),
                    $now, $userId, $entityId,
                ]);
            return;
        }
        $mockup = $this->mockupRow($userId, $entityId);
        $this->ensureMockupSheet($userId, $mockup);
        $this->pdo->prepare('UPDATE mockup_sheets SET description=?,keywords=?,tags=?,alt_text=?,caption=?,updated_at=? WHERE user_id=? AND (mockup_id=? OR mockup_file=?)')
            ->execute([
                (string)($content['description'] ?? ''),
                (string)($content['search_terms'] ?? $content['keywords'] ?? ''),
                (string)($content['tags'] ?? ''),
                (string)($content['alt_text'] ?? ''),
                (string)($content['caption'] ?? ''),
                $now, $userId, $entityId, (string)$mockup['mockup_file'],
            ]);
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }
}
