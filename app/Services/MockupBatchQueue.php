<?php
declare(strict_types=1);

class MockupBatchQueue
{
    public const INITIAL_BATCH_LIMIT = 8;

    public static function enqueueInitialBatch(int $artworkId, int $userId, string $rootFile, int $limit = self::INITIAL_BATCH_LIMIT): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT id, prompt
            FROM mockup_contexts
            WHERE artwork_id = :artwork_id
            ORDER BY id ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':artwork_id', $artworkId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $contexts = $stmt->fetchAll();

        $created = 0;
        foreach ($contexts as $context) {
            $contextId = (string)$context['id'];

            $existingMockup = $pdo->prepare('
                SELECT id FROM mockups
                WHERE user_id = :user_id AND artwork_file = :artwork_file AND context_id = :context_id
                LIMIT 1
            ');
            $existingMockup->execute([
                'user_id' => $userId,
                'artwork_file' => basename($rootFile),
                'context_id' => $contextId,
            ]);
            if ($existingMockup->fetch()) {
                continue;
            }

            $existingJob = $pdo->prepare("
                SELECT id FROM mockup_generation_jobs
                WHERE artwork_id = :artwork_id AND context_id = :context_id AND status IN ('queued', 'processing', 'done')
                LIMIT 1
            ");
            $existingJob->execute([
                'artwork_id' => $artworkId,
                'context_id' => $contextId,
            ]);
            if ($existingJob->fetch()) {
                continue;
            }

            Database::withBusyRetry(function () use ($userId, $artworkId, $rootFile, $context, $contextId): void {
                $pdo = Database::connection();
                $now = date('c');
                $insert = $pdo->prepare('
                    INSERT INTO mockup_generation_jobs
                        (user_id, artwork_id, artwork_file, context_id, prompt, status, attempts, created_at, updated_at)
                    VALUES
                        (:user_id, :artwork_id, :artwork_file, :context_id, :prompt, "queued", 0, :created_at, :updated_at)
                ');
                $insert->execute([
                    'user_id' => $userId,
                    'artwork_id' => $artworkId,
                    'artwork_file' => basename($rootFile),
                    'context_id' => $contextId,
                    'prompt' => (string)$context['prompt'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }, 12);
            $created++;
        }

        return $created;
    }

    public static function claimNext(?int $artworkId = null): ?array
    {
        return Database::withBusyRetry(function () use ($artworkId): ?array {
            $pdo = Database::connection();
            self::requeueStaleProcessing($artworkId);
            Database::beginWriteTransaction($pdo);

            try {
                if ($artworkId) {
                    $lockClause = Database::isMysql() ? ' FOR UPDATE SKIP LOCKED' : '';
                    $stmt = $pdo->prepare('
                        SELECT * FROM mockup_generation_jobs
                        WHERE artwork_id = :artwork_id AND status = "queued"
                        ORDER BY id ASC
                        LIMIT 1
                    ' . $lockClause);
                    $stmt->execute(['artwork_id' => $artworkId]);
                } else {
                    $lockClause = Database::isMysql() ? ' FOR UPDATE SKIP LOCKED' : '';
                    $stmt = $pdo->query('
                        SELECT * FROM mockup_generation_jobs
                        WHERE status = "queued"
                        ORDER BY id ASC
                        LIMIT 1
                    ' . $lockClause);
                }

                $job = $stmt->fetch();
                if (!$job) {
                    $pdo->exec('COMMIT');
                    return null;
                }

                $update = $pdo->prepare('
                    UPDATE mockup_generation_jobs
                    SET status = :status, attempts = attempts + 1, error = NULL, updated_at = :updated_at
                    WHERE id = :id
                ');
                $update->execute([
                    'status' => 'processing',
                    'updated_at' => date('c'),
                    'id' => (int)$job['id'],
                ]);
                $pdo->exec('COMMIT');

                $job['status'] = 'processing';
                $job['attempts'] = (int)$job['attempts'] + 1;
                return $job;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }, 18);
    }

    public static function requeueStaleProcessing(?int $artworkId = null, int $staleAfterMinutes = 20): int
    {
        $cutoff = date('c', time() - max(5, $staleAfterMinutes) * 60);
        $params = ['cutoff' => $cutoff];
        $whereArtwork = '';

        if ($artworkId) {
            $whereArtwork = ' AND artwork_id = :artwork_id';
            $params['artwork_id'] = $artworkId;
        }

        $stmt = Database::connection()->prepare("
            UPDATE mockup_generation_jobs
            SET status = 'queued', error = NULL, updated_at = :updated_at
            WHERE status = 'processing'
            AND updated_at < :cutoff
            AND attempts < 3
            {$whereArtwork}
        ");
        $stmt->execute($params + ['updated_at' => date('c')]);

        return $stmt->rowCount();
    }

    public static function markDone(int $jobId, int $mockupId, string $mockupFile, string $promptFile): void
    {
        Database::withBusyRetry(function () use ($jobId, $mockupId, $mockupFile, $promptFile): void {
            $stmt = Database::connection()->prepare('
                UPDATE mockup_generation_jobs
                SET status = :status, mockup_id = :mockup_id, mockup_file = :mockup_file,
                    prompt_file = :prompt_file, error = NULL, updated_at = :updated_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => 'done',
                'mockup_id' => $mockupId,
                'mockup_file' => basename($mockupFile),
                'prompt_file' => basename($promptFile),
                'updated_at' => date('c'),
                'id' => $jobId,
            ]);
        }, 18);
    }

    public static function markError(int $jobId, string $error): void
    {
        Database::withBusyRetry(function () use ($jobId, $error): void {
            $stmt = Database::connection()->prepare('
                UPDATE mockup_generation_jobs
                SET status = :status, error = :error, updated_at = :updated_at
                WHERE id = :id
            ');
            $stmt->execute([
                'status' => 'error',
                'error' => substr($error, 0, 1000),
                'updated_at' => date('c'),
                'id' => $jobId,
            ]);
        }, 18);
    }

    public static function rowsForArtwork(int $artworkId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT * FROM mockup_generation_jobs
            WHERE artwork_id = :artwork_id
            ORDER BY id ASC
        ');
        $stmt->execute(['artwork_id' => $artworkId]);

        return $stmt->fetchAll();
    }
}
