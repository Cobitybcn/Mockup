<?php
declare(strict_types=1);

class MockupBatchQueue
{
    public const INITIAL_BATCH_LIMIT = 5; // Aumentado de 3 a 5 para mejor paralelismo

    public static function enqueueInitialBatch(int $artworkId, int $userId, string $rootFile, int $limit = self::INITIAL_BATCH_LIMIT): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT *
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
                    'prompt' => self::composeAdminPromptForContext($context),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }, 12);
            $created++;
        }

        return $created;
    }

    public static function enqueueAndClaimContexts(int $artworkId, int $userId, string $rootFile, array $contextIds): array
    {
        $contextIds = array_values(array_unique(array_filter(array_map(
            fn($id): string => trim((string)$id),
            $contextIds
        ))));

        if ($contextIds === []) {
            return [];
        }

        return Database::withBusyRetry(function () use ($artworkId, $userId, $rootFile, $contextIds): array {
            $pdo = Database::connection();
            Database::beginWriteTransaction($pdo);

            try {
                $claimedJobIds = [];
                $rootFile = basename($rootFile);

                foreach ($contextIds as $contextId) {
                    $contextStmt = $pdo->prepare('
                        SELECT *
                        FROM mockup_contexts
                        WHERE artwork_id = :artwork_id AND id = :id
                        LIMIT 1
                    ');
                    $contextStmt->execute([
                        'artwork_id' => $artworkId,
                        'id' => $contextId,
                    ]);
                    $context = $contextStmt->fetch();

                    if (!$context) {
                        continue;
                    }

                    $existingMockup = $pdo->prepare('
                        SELECT id FROM mockups
                        WHERE user_id = :user_id AND artwork_file = :artwork_file AND context_id = :context_id
                        LIMIT 1
                    ');
                    $existingMockup->execute([
                        'user_id' => $userId,
                        'artwork_file' => $rootFile,
                        'context_id' => $contextId,
                    ]);
                    if ($existingMockup->fetch()) {
                        continue;
                    }

                    $existingJob = $pdo->prepare('
                        SELECT id, status
                        FROM mockup_generation_jobs
                        WHERE artwork_id = :artwork_id AND context_id = :context_id
                        LIMIT 1
                    ');
                    $existingJob->execute([
                        'artwork_id' => $artworkId,
                        'context_id' => $contextId,
                    ]);
                    $job = $existingJob->fetch();
                    $now = date('c');

                    if ($job) {
                        if ((string)$job['status'] === 'error') {
                            $update = $pdo->prepare('
                                UPDATE mockup_generation_jobs
                                SET status = "processing", attempts = attempts + 1, error = NULL, updated_at = :updated_at
                                WHERE id = :id
                            ');
                            $update->execute([
                                'updated_at' => $now,
                                'id' => (int)$job['id'],
                            ]);
                            $claimedJobIds[] = (int)$job['id'];
                        }

                        continue;
                    }

                    $insert = $pdo->prepare('
                        INSERT INTO mockup_generation_jobs
                            (user_id, artwork_id, artwork_file, context_id, prompt, status, attempts, created_at, updated_at)
                        VALUES
                            (:user_id, :artwork_id, :artwork_file, :context_id, :prompt, "processing", 1, :created_at, :updated_at)
                    ');
                    $insert->execute([
                        'user_id' => $userId,
                        'artwork_id' => $artworkId,
                        'artwork_file' => $rootFile,
                        'context_id' => $contextId,
                        'prompt' => self::composeAdminPromptForContext($context),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $claimedJobIds[] = (int)$pdo->lastInsertId();
                }

                $pdo->exec('COMMIT');
                return $claimedJobIds;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }, 18);
    }

    /**
     * Pre-claim all queued jobs for an artwork in a single transaction.
     * Returns an array of claimed job IDs. Each ID is then passed to a
     * dedicated worker, eliminating competition between workers.
     */
    public static function claimBatch(int $artworkId, int $limit): array
    {
        return Database::withBusyRetry(function () use ($artworkId, $limit): array {
            $pdo = Database::connection();
            self::requeueStaleProcessing($artworkId);
            Database::beginWriteTransaction($pdo);

            try {
                $stmt = $pdo->prepare('
                    SELECT id FROM mockup_generation_jobs
                    WHERE artwork_id = :artwork_id AND status = "queued"
                    ORDER BY id ASC
                    LIMIT :limit
                ');
                $stmt->bindValue(':artwork_id', $artworkId, PDO::PARAM_INT);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();

                $now = date('c');
                $claimedIds = [];

                foreach ($rows as $row) {
                    $jobId = (int)$row['id'];
                    $update = $pdo->prepare('
                        UPDATE mockup_generation_jobs
                        SET status = "processing", attempts = attempts + 1, error = NULL, updated_at = :updated_at
                        WHERE id = :id AND status = "queued"
                    ');
                    $update->execute(['updated_at' => $now, 'id' => $jobId]);
                    if ($update->rowCount() > 0) {
                        $claimedIds[] = $jobId;
                    }
                }

                $pdo->exec('COMMIT');
                return $claimedIds;
            } catch (Throwable $e) {
                $pdo->exec('ROLLBACK');
                throw $e;
            }
        }, 18);
    }

    /**
     * Claim a specific job by ID (already marked "processing" by claimBatch).
     * Fetches full job data for a pre-assigned worker.
     */
    public static function claimById(int $jobId): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT * FROM mockup_generation_jobs
            WHERE id = :id AND status = "processing"
            LIMIT 1
        ');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();
        return $job ?: null;
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

    public static function composeAdminPromptForContext(array $context): string
    {
        return (new AdminPromptComposerPreview())->compose($context);
    }
}
