<?php
declare(strict_types=1);

final class BilingualEditorialJobService
{
    private const ENTITY_TYPES = ['series', 'artwork', 'mockup'];
    private const ACTIONS = ['prepare', 'adapt'];
    private const ACTIVE_STATUSES = ['queued', 'processing'];

    public function __construct(private readonly PDO $pdo) {}

    public function createOrReuse(
        int $userId,
        string $entityType,
        int $entityId,
        string $action,
        array $payload = []
    ): array {
        $this->assertIdentity($userId, $entityType, $entityId, $action);
        $active = $this->activeForEntity($userId, $entityType, $entityId);
        if ($active) return $active;

        $now = date(DATE_ATOM);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->pdo->prepare(
            "INSERT INTO bilingual_editorial_jobs
             (user_id,entity_type,entity_id,action,status,source_locale,target_locale,payload_json,result_json,error,task_name,attempts,started_at,completed_at,created_at,updated_at)
             VALUES (?,?,?,?,?,'es','en',?,'{}','','',0,NULL,NULL,?,?)"
        )->execute([$userId, $entityType, $entityId, $action, 'queued', $encoded, $now, $now]);
        return $this->job((int)$this->pdo->lastInsertId(), $userId);
    }

    public function job(int $jobId, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM bilingual_editorial_jobs WHERE id=?';
        $params = [$jobId];
        if ($userId !== null) {
            $sql .= ' AND user_id=?';
            $params[] = $userId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Editorial generation job not found.');
        return $row;
    }

    public function activeForEntity(int $userId, string $entityType, int $entityId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bilingual_editorial_jobs
             WHERE user_id=? AND entity_type=? AND entity_id=? AND status IN ('queued','processing')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$userId, $entityType, $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function attachTask(int $jobId, int $userId, string $taskName): void
    {
        $this->pdo->prepare(
            "UPDATE bilingual_editorial_jobs SET task_name=?,error='',updated_at=?
             WHERE id=? AND user_id=? AND status='queued'"
        )->execute([mb_substr(trim($taskName), 0, 512), date(DATE_ATOM), $jobId, $userId]);
    }

    public function markEnqueueFailed(int $jobId, int $userId, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE bilingual_editorial_jobs SET status='enqueue_failed',error=?,updated_at=?
             WHERE id=? AND user_id=? AND status='queued'"
        )->execute([mb_substr(trim($error), 0, 2000), date(DATE_ATOM), $jobId, $userId]);
    }

    public function claim(int $jobId): ?array
    {
        $job = $this->job($jobId);
        if ((string)$job['status'] === 'completed') return $job;
        $now = date(DATE_ATOM);
        $stale = date(DATE_ATOM, time() - 1200);
        $stmt = $this->pdo->prepare(
            "UPDATE bilingual_editorial_jobs
             SET status='processing',attempts=attempts+1,error='',started_at=COALESCE(started_at,?),updated_at=?
             WHERE id=? AND (status='queued' OR (status='processing' AND updated_at<?))"
        );
        $stmt->execute([$now, $now, $jobId, $stale]);
        return $stmt->rowCount() === 1 ? $this->job($jobId) : null;
    }

    public function complete(int $jobId, array $result): void
    {
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $now = date(DATE_ATOM);
        $this->pdo->prepare(
            "UPDATE bilingual_editorial_jobs
             SET status='completed',result_json=?,error='',completed_at=?,updated_at=?
             WHERE id=? AND status='processing'"
        )->execute([$encoded, $now, $now, $jobId]);
    }

    public function fail(int $jobId, string $error): void
    {
        $now = date(DATE_ATOM);
        $this->pdo->prepare(
            "UPDATE bilingual_editorial_jobs
             SET status='failed',error=?,completed_at=?,updated_at=?
             WHERE id=? AND status='processing'"
        )->execute([mb_substr(trim($error), 0, 4000), $now, $now, $jobId]);
    }

    public function publicState(array $job): array
    {
        $result = json_decode((string)($job['result_json'] ?? '{}'), true);
        return [
            'id' => (int)$job['id'],
            'entity_type' => (string)$job['entity_type'],
            'entity_id' => (int)$job['entity_id'],
            'action' => (string)$job['action'],
            'status' => (string)$job['status'],
            'attempts' => (int)$job['attempts'],
            'error' => (string)$job['error'],
            'result' => is_array($result) ? $result : [],
            'created_at' => (string)$job['created_at'],
            'updated_at' => (string)$job['updated_at'],
        ];
    }

    private function assertIdentity(int $userId, string $entityType, int $entityId, string $action): void
    {
        if ($userId <= 0
            || $entityId <= 0
            || !in_array($entityType, self::ENTITY_TYPES, true)
            || !in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid editorial generation job.');
        }
    }
}
