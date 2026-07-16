<?php
declare(strict_types=1);

final class SocialPublishJobService
{
    private const CHANNELS = ['pinterest', 'instagram', 'facebook'];

    public function __construct(private readonly PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $mysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($mysql) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS social_publish_jobs (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    channel VARCHAR(30) NOT NULL,
                    purpose VARCHAR(30) NOT NULL DEFAULT 'artist',
                    status VARCHAR(40) NOT NULL DEFAULT 'queued',
                    scheduled_at VARCHAR(40) NOT NULL,
                    payload_json LONGTEXT NOT NULL,
                    idempotency_key VARCHAR(64) NOT NULL,
                    task_name VARCHAR(512) NOT NULL DEFAULT '',
                    attempts INT NOT NULL DEFAULT 0,
                    publish_attempt_id VARCHAR(64) NOT NULL DEFAULT '',
                    external_id VARCHAR(255) NOT NULL DEFAULT '',
                    external_url LONGTEXT NOT NULL,
                    error LONGTEXT NOT NULL,
                    created_at VARCHAR(40) NOT NULL,
                    updated_at VARCHAR(40) NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY social_publish_jobs_user_key (user_id, idempotency_key),
                    KEY social_publish_jobs_due (status, scheduled_at),
                    KEY social_publish_jobs_user (user_id, created_at),
                    CONSTRAINT social_publish_jobs_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return;
        }

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS social_publish_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                channel TEXT NOT NULL,
                purpose TEXT NOT NULL DEFAULT 'artist',
                status TEXT NOT NULL DEFAULT 'queued',
                scheduled_at TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                idempotency_key TEXT NOT NULL,
                task_name TEXT NOT NULL DEFAULT '',
                attempts INTEGER NOT NULL DEFAULT 0,
                publish_attempt_id TEXT NOT NULL DEFAULT '',
                external_id TEXT NOT NULL DEFAULT '',
                external_url TEXT NOT NULL DEFAULT '',
                error TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE(user_id, idempotency_key),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS social_publish_jobs_due ON social_publish_jobs(status, scheduled_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS social_publish_jobs_user ON social_publish_jobs(user_id, created_at)');
    }

    public function findByKey(int $userId, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM social_publish_jobs WHERE user_id=? AND idempotency_key=? LIMIT 1');
        $stmt->execute([$userId, $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(
        int $userId,
        string $channel,
        string $purpose,
        DateTimeImmutable $scheduledAt,
        array $payload,
        string $idempotencyKey
    ): array {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Unsupported social publication channel.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('Invalid social publication key.');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid social publication owner.');
        }
        $existing = $this->findByKey($userId, $idempotencyKey);
        if ($existing) return $existing;

        $now = date('c');
        try {
            $this->pdo->prepare(
                'INSERT INTO social_publish_jobs
                 (user_id,channel,purpose,status,scheduled_at,payload_json,idempotency_key,task_name,attempts,publish_attempt_id,external_id,external_url,error,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $userId,
                $channel,
                $purpose,
                'queued',
                $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $idempotencyKey,
                '',
                0,
                '',
                '',
                '',
                '',
                $now,
                $now,
            ]);
        } catch (PDOException $e) {
            $existing = $this->findByKey($userId, $idempotencyKey);
            if (!$existing) throw $e;
            return $existing;
        }
        return $this->job((int)$this->pdo->lastInsertId(), $userId);
    }

    public function job(int $jobId, ?int $userId = null): array
    {
        $sql = 'SELECT * FROM social_publish_jobs WHERE id=?';
        $params = [$jobId];
        if ($userId !== null) {
            $sql .= ' AND user_id=?';
            $params[] = $userId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Social publication job not found.');
        return $row;
    }

    /** @return array<int,array> */
    public function manageableForUser(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM social_publish_jobs
             WHERE user_id=? AND status IN ('queued','enqueue_failed','rescheduling','cancelling','retrying')
             ORDER BY scheduled_at ASC,id ASC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> */
    public function recentForUser(int $userId, int $limit = 60): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT * FROM social_publish_jobs
             WHERE user_id=?
             ORDER BY updated_at DESC,id DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function beginManagement(int $jobId, int $userId, string $transientStatus): array
    {
        if (!in_array($transientStatus, ['rescheduling', 'cancelling'], true)) {
            throw new InvalidArgumentException('Invalid publication management state.');
        }
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs SET status=?,error='',updated_at=?
             WHERE id=? AND user_id=? AND status IN ('queued','enqueue_failed')"
        );
        $stmt->execute([$transientStatus, date('c'), $jobId, $userId]);
        if ($stmt->rowCount() !== 1) {
            $job = $this->job($jobId, $userId);
            throw new RuntimeException('This publication can no longer be changed because its status is ' . (string)$job['status'] . '.');
        }
        return $this->job($jobId, $userId);
    }

    public function restoreManagement(int $jobId, int $userId, string $status, string $error): void
    {
        if (!in_array($status, ['queued', 'enqueue_failed'], true)) {
            $status = 'queued';
        }
        $this->pdo->prepare(
            "UPDATE social_publish_jobs SET status=?,error=?,updated_at=?
             WHERE id=? AND user_id=? AND status IN ('rescheduling','cancelling')"
        )->execute([$status, mb_substr(trim($error), 0, 1500), date('c'), $jobId, $userId]);
    }

    public function finishReschedule(int $jobId, int $userId, DateTimeImmutable $scheduledAt, string $taskName): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='queued',scheduled_at=?,task_name=?,error='',publish_attempt_id='',updated_at=?
             WHERE id=? AND user_id=? AND status='rescheduling'"
        );
        $stmt->execute([
            $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            mb_substr(trim($taskName), 0, 512),
            date('c'),
            $jobId,
            $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The publication reschedule no longer owns this job.');
        }
    }

    public function failReschedule(int $jobId, int $userId, DateTimeImmutable $scheduledAt, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='enqueue_failed',scheduled_at=?,task_name='',error=?,publish_attempt_id='',updated_at=?
             WHERE id=? AND user_id=? AND status='rescheduling'"
        )->execute([
            $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            mb_substr(trim($error), 0, 1500),
            date('c'),
            $jobId,
            $userId,
        ]);
    }

    public function finishCancellation(int $jobId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='cancelled',task_name='',error='',publish_attempt_id='',updated_at=?
             WHERE id=? AND user_id=? AND status='cancelling'"
        );
        $stmt->execute([date('c'), $jobId, $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The publication cancellation no longer owns this job.');
        }
    }

    public function beginRetry(int $jobId, int $userId): array
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='retrying',task_name='',publish_attempt_id='',error='',updated_at=?
             WHERE id=? AND user_id=? AND status IN ('failed','enqueue_failed')"
        );
        $stmt->execute([date('c'), $jobId, $userId]);
        if ($stmt->rowCount() !== 1) {
            $job = $this->job($jobId, $userId);
            throw new RuntimeException('This publication cannot be retried because its status is ' . (string)$job['status'] . '.');
        }
        return $this->job($jobId, $userId);
    }

    public function finishRetry(int $jobId, int $userId, DateTimeImmutable $scheduledAt, string $taskName): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='queued',scheduled_at=?,task_name=?,publish_attempt_id='',error='',updated_at=?
             WHERE id=? AND user_id=? AND status='retrying'"
        );
        $stmt->execute([
            $scheduledAt->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
            mb_substr(trim($taskName), 0, 512),
            date('c'),
            $jobId,
            $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('The publication retry no longer owns this job.');
        }
    }

    public function failRetry(int $jobId, int $userId, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='failed',task_name='',publish_attempt_id='',error=?,updated_at=?
             WHERE id=? AND user_id=? AND status='retrying'"
        )->execute([mb_substr(trim($error), 0, 1500), date('c'), $jobId, $userId]);
    }

    public function attachTask(int $jobId, int $userId, string $taskName): void
    {
        $this->pdo->prepare("UPDATE social_publish_jobs SET task_name=?,status='queued',error='',updated_at=? WHERE id=? AND user_id=? AND status<>'published'")
            ->execute([mb_substr(trim($taskName), 0, 512), date('c'), $jobId, $userId]);
    }

    public function markEnqueueFailed(int $jobId, int $userId, string $error): void
    {
        $this->pdo->prepare("UPDATE social_publish_jobs SET status='enqueue_failed',error=?,updated_at=? WHERE id=? AND user_id=? AND status='queued'")
            ->execute([mb_substr(trim($error), 0, 1500), date('c'), $jobId, $userId]);
    }

    public function claim(int $jobId): ?array
    {
        $job = $this->job($jobId);
        if ((string)$job['status'] === 'published') return $job;
        if (in_array((string)$job['status'], ['failed', 'needs_verification', 'enqueue_failed'], true)) return null;
        $attempt = bin2hex(random_bytes(24));
        $now = date('c');
        $stale = date('c', time() - 900);
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs
             SET status='publishing',publish_attempt_id=?,attempts=attempts+1,error='',updated_at=?
             WHERE id=? AND (status='queued' OR (status='publishing' AND updated_at<?))"
        );
        $stmt->execute([$attempt, $now, $jobId, $stale]);
        return $stmt->rowCount() === 1 ? $this->job($jobId) : null;
    }

    public function markPublished(int $jobId, string $attemptId, string $externalId, string $externalUrl): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE social_publish_jobs SET status='published',external_id=?,external_url=?,error='',updated_at=?
             WHERE id=? AND status='publishing' AND publish_attempt_id=?"
        );
        $stmt->execute([mb_substr(trim($externalId), 0, 255), trim($externalUrl), date('c'), $jobId, $attemptId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('The social publication attempt no longer owns this job.');
    }

    public function markFailed(int $jobId, string $attemptId, string $error): void
    {
        $this->markOutcome($jobId, $attemptId, 'failed', $error);
    }

    public function markNeedsVerification(int $jobId, string $attemptId, string $error): void
    {
        $this->markOutcome($jobId, $attemptId, 'needs_verification', $error);
    }

    private function markOutcome(int $jobId, string $attemptId, string $status, string $error): void
    {
        $this->pdo->prepare(
            "UPDATE social_publish_jobs SET status=?,error=?,updated_at=?
             WHERE id=? AND status='publishing' AND publish_attempt_id=?"
        )->execute([$status, mb_substr(trim($error), 0, 1500), date('c'), $jobId, $attemptId]);
    }
}
