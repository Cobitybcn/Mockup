<?php
declare(strict_types=1);

final class AppPublishedLocalization
{
    private ?int $userId = null;
    /** @var array<string,array<string,array<string,mixed>>> */
    private array $contentCache = [];

    public function __construct(private readonly PDO $pdo, private readonly string $artistEmail) {}

    public static function fromApp(string $appRoot, string $artistEmail): self
    {
        return new self(artist_site_database_connection($appRoot), $artistEmail);
    }

    public function hasPublishedSpanish(): bool
    {
        try {
            $contentCondition = $this->allowsLocalDraftMaster()
                ? "TRIM(COALESCE(b.content_json,'')) NOT IN ('','{}','[]','null')"
                : "b.is_published=1";
            $stmt = $this->pdo->prepare("SELECT 1
                FROM bilingual_editorial_content b
                WHERE b.user_id=? AND b.locale='es' AND {$contentCondition} AND (
                    (b.entity_type='series' AND EXISTS (
                        SELECT 1 FROM artwork_series s WHERE s.id=b.entity_id AND s.user_id=b.user_id AND s.published=1
                    )) OR
                    (b.entity_type='artwork' AND EXISTS (
                        SELECT 1 FROM artwork_sheets sh
                        INNER JOIN publications p ON p.artwork_sheet_id=sh.id AND p.user_id=sh.user_id
                        WHERE sh.canonical_artwork_id=b.entity_id AND sh.user_id=b.user_id AND p.status='published' AND p.visibility IN ('public','unlisted')
                    )) OR
                    (b.entity_type='mockup' AND EXISTS (
                        SELECT 1 FROM mockup_sheets ms
                        INNER JOIN publication_items pi ON pi.mockup_sheet_id=ms.id
                        INNER JOIN publications p ON p.id=pi.publication_id
                        WHERE ms.mockup_id=b.entity_id AND ms.user_id=b.user_id AND p.user_id=b.user_id AND p.status='published' AND p.visibility IN ('public','unlisted')
                    ))
                ) LIMIT 1");
            $stmt->execute([$this->userId()]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }

    public function content(string $entityType, int $entityId, string $locale = 'es'): array
    {
        if ($entityId <= 0 || !in_array($entityType, ['series', 'artwork', 'mockup'], true)) return [];
        $locale = strtolower(trim($locale));
        if (!in_array($locale, ['es', 'en'], true)) return [];
        return $this->cachedContent()[$entityType . '|' . $entityId . '|' . $locale] ?? [];
    }

    /**
     * The public site resolves editorial text for every artwork and mockup it renders,
     * so a per-entity query turns one page into thousands of round trips. Read the
     * artist's editorial rows once and serve every lookup from memory instead.
     *
     * @return array<string,array<string,mixed>>
     */
    private function cachedContent(): array
    {
        $mode = $this->allowsLocalDraftMaster() ? 'draft' : 'published';
        if (isset($this->contentCache[$mode])) return $this->contentCache[$mode];

        $entries = [];
        try {
            $stmt = $this->pdo->prepare('SELECT entity_type,entity_id,locale,content_json,published_content_json,is_published
                FROM bilingual_editorial_content
                WHERE user_id=? AND entity_type IN (\'series\',\'artwork\',\'mockup\') AND locale IN (\'es\',\'en\')');
            $stmt->execute([$this->userId()]);
            foreach ($stmt as $row) {
                $locale = strtolower(trim((string)$row['locale']));
                $useSpanishDraft = $locale === 'es' && $mode === 'draft';
                if ($locale === 'es' && !$useSpanishDraft) {
                    if (!(int)$row['is_published']) continue;
                    $payload = (string)$row['published_content_json'];
                } else {
                    $payload = (string)$row['content_json'];
                }
                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) continue;
                $entries[(string)$row['entity_type'] . '|' . (int)$row['entity_id'] . '|' . $locale] = $decoded;
            }
        } catch (PDOException) {
            return $this->contentCache[$mode] = [];
        }
        return $this->contentCache[$mode] = $entries;
    }

    private function allowsLocalDraftMaster(): bool
    {
        return getenv('K_SERVICE') === false
            && strtolower(trim((string)(getenv('APP_ENV') ?: ''))) === 'local';
    }

    private function userId(): int
    {
        if ($this->userId !== null) return $this->userId;
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE LOWER(email)=LOWER(?) LIMIT 1');
        $stmt->execute([$this->artistEmail]);
        $this->userId = (int)($stmt->fetchColumn() ?: 0);
        return $this->userId;
    }
}
