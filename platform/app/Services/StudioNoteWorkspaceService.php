<?php
declare(strict_types=1);

final class StudioNoteWorkspaceService
{
    private const BOARD_TYPES = ['version', 'proposal', 'idea'];
    private const LOCALES = ['es', 'en'];

    public function __construct(private readonly PDO $pdo) {}

    public function capture(
        int $userId,
        int $noteId,
        string $boardType,
        string $locale,
        array $content,
        string $label,
        int $sourceJobId = 0
    ): ?int {
        $this->assertIdentity($userId, $noteId, $boardType, $locale);
        $content = $this->normalizeContent($content);
        if (!$this->hasContent($content)) return null;
        $encoded = json_encode(
            $content,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $hash = hash('sha256', $encoded);
        $now = date(DATE_ATOM);
        $positionStmt = $this->pdo->prepare('SELECT COALESCE(MAX(position),0)+1
            FROM studio_note_workspace_items
            WHERE user_id=? AND note_id=? AND board_type=?');
        $positionStmt->execute([$userId, $noteId, $boardType]);
        $position = max(1, (int)$positionStmt->fetchColumn());

        if ($this->isMysql()) {
            $stmt = $this->pdo->prepare("INSERT INTO studio_note_workspace_items
                (user_id,note_id,board_type,locale,label,content_json,content_hash,source_job_id,position,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE label=VALUES(label),updated_at=VALUES(updated_at)");
        } else {
            $stmt = $this->pdo->prepare("INSERT INTO studio_note_workspace_items
                (user_id,note_id,board_type,locale,label,content_json,content_hash,source_job_id,position,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(user_id,note_id,board_type,locale,content_hash,source_job_id)
                DO UPDATE SET label=excluded.label,updated_at=excluded.updated_at");
        }
        $stmt->execute([
            $userId,
            $noteId,
            $boardType,
            $locale,
            mb_substr(trim($label) ?: ucfirst($boardType), 0, 255),
            $encoded,
            $hash,
            max(0, $sourceJobId),
            $position,
            $now,
            $now,
        ]);
        $existing = $this->pdo->prepare('SELECT id FROM studio_note_workspace_items
            WHERE user_id=? AND note_id=? AND board_type=? AND locale=? AND content_hash=? AND source_job_id=?
            LIMIT 1');
        $existing->execute([$userId, $noteId, $boardType, $locale, $hash, max(0, $sourceJobId)]);
        $id = (int)$existing->fetchColumn();
        return $id > 0 ? $id : null;
    }

    public function addIdea(int $userId, int $noteId, string $title, string $text): ?int
    {
        $title = trim($title);
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Escribí una idea antes de guardarla en la pizarra.');
        if ($title === '') {
            $title = mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 70);
        }
        return $this->capture($userId, $noteId, 'idea', 'es', [
            'title' => $title,
            'body_html' => '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) . '</p>',
        ], $title);
    }

    /** @return array<string,list<array<string,mixed>>> */
    public function boards(int $userId, int $noteId): array
    {
        $this->assertNote($userId, $noteId);
        $stmt = $this->pdo->prepare('SELECT *
            FROM studio_note_workspace_items
            WHERE user_id=? AND note_id=?
            ORDER BY board_type,position DESC,id DESC');
        $stmt->execute([$userId, $noteId]);
        $boards = ['version' => [], 'proposal' => [], 'idea' => []];
        foreach ($stmt as $row) {
            $content = json_decode((string)$row['content_json'], true);
            if (!is_array($content) || !isset($boards[(string)$row['board_type']])) continue;
            $row['content'] = $this->normalizeContent($content);
            $boards[(string)$row['board_type']][] = $row;
        }
        return $boards;
    }

    public function item(int $userId, int $noteId, int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM studio_note_workspace_items
            WHERE id=? AND user_id=? AND note_id=? LIMIT 1');
        $stmt->execute([$itemId, $userId, $noteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('El elemento de la mesa editorial ya no está disponible.');
        $content = json_decode((string)$row['content_json'], true);
        $row['content'] = is_array($content) ? $this->normalizeContent($content) : [];
        return $row;
    }

    public function remove(int $userId, int $noteId, int $itemId): void
    {
        $this->item($userId, $noteId, $itemId);
        $this->pdo->prepare('DELETE FROM studio_note_workspace_items
            WHERE id=? AND user_id=? AND note_id=?')->execute([$itemId, $userId, $noteId]);
    }

    public function syncHistoricalJobs(int $userId, int $noteId): void
    {
        $this->assertNote($userId, $noteId);
        $stmt = $this->pdo->prepare("SELECT id,payload_json,result_json,status,created_at
            FROM bilingual_editorial_jobs
            WHERE user_id=? AND entity_type='studio_note' AND entity_id=?
            ORDER BY id");
        $stmt->execute([$userId, $noteId]);
        foreach ($stmt as $job) {
            $jobId = (int)$job['id'];
            $payload = json_decode((string)$job['payload_json'], true);
            $result = json_decode((string)$job['result_json'], true);
            $payload = is_array($payload) ? $payload : [];
            $result = is_array($result) ? $result : [];
            if (is_array($payload['current_spanish'] ?? null)) {
                $this->capture(
                    $userId,
                    $noteId,
                    'version',
                    'es',
                    (array)$payload['current_spanish'],
                    'Texto antes de la propuesta · ' . $this->dateLabel((string)$job['created_at']),
                    $jobId
                );
            }
            if ((string)$job['status'] !== 'completed') continue;
            foreach (['es' => 'spanish_content', 'en' => 'english_content'] as $locale => $key) {
                if (!is_array($result[$key] ?? null)) continue;
                $this->capture(
                    $userId,
                    $noteId,
                    'proposal',
                    $locale,
                    (array)$result[$key],
                    ($locale === 'es' ? 'Propuesta española' : 'English proposal')
                        . ' · ' . $this->dateLabel((string)$job['created_at']),
                    $jobId
                );
            }
        }
    }

    private function assertIdentity(int $userId, int $noteId, string $boardType, string $locale): void
    {
        if (!in_array($boardType, self::BOARD_TYPES, true)
            || !in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException('Invalid Studio Note workspace item.');
        }
        $this->assertNote($userId, $noteId);
    }

    private function assertNote(int $userId, int $noteId): void
    {
        if ($userId <= 0 || $noteId <= 0) throw new InvalidArgumentException('Invalid Studio Note.');
        $stmt = $this->pdo->prepare("SELECT 1 FROM social_campaigns
            WHERE id=? AND user_id=? AND campaign_type='website_blog' LIMIT 1");
        $stmt->execute([$noteId, $userId]);
        if (!$stmt->fetchColumn()) throw new RuntimeException('Nota de estudio no encontrada.');
    }

    private function normalizeContent(array $content): array
    {
        $normalized = [];
        foreach ($content as $key => $value) {
            if (is_scalar($value) || $value === null) $normalized[(string)$key] = trim((string)$value);
        }
        return $normalized;
    }

    private function hasContent(array $content): bool
    {
        foreach ($content as $value) {
            if (trim(strip_tags((string)$value)) !== '') return true;
        }
        return false;
    }

    private function dateLabel(string $value): string
    {
        $time = strtotime($value);
        return $time === false ? 'sin fecha' : date('d/m/Y H:i', $time);
    }

    private function isMysql(): bool
    {
        return strtolower((string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
    }
}
