<?php
declare(strict_types=1);

final class RootArtworkViewSetService
{
    public const VIEW_TYPES = [
        1 => 'frontal',
        2 => 'three-quarter-left',
        3 => 'three-quarter-right',
    ];

    public static function requiredCount(): int
    {
        return count(self::VIEW_TYPES);
    }

    /**
     * @return array<string,string> view type => safe result filename
     */
    public static function completeViewSet(array $files): array
    {
        $views = [];
        foreach (array_values($files) as $index => $fileValue) {
            $file = basename(trim((string)$fileValue));
            if ($file === '' || $file === '.' || $file === '..') {
                continue;
            }

            $version = $index + 1;
            if (preg_match('/_v(\d+)\.(?:png|jpe?g|webp)$/i', $file, $matches) === 1) {
                $version = (int)$matches[1];
            }
            if (!isset(self::VIEW_TYPES[$version])) {
                continue;
            }

            $viewType = self::VIEW_TYPES[$version];
            if (!isset($views[$viewType])) {
                $views[$viewType] = $file;
            }
        }

        $orderedViews = [];
        foreach (self::VIEW_TYPES as $viewType) {
            if (!isset($views[$viewType])) {
                throw new RuntimeException('The complete front, left and right root artwork view set was not generated.');
            }
            $orderedViews[$viewType] = $views[$viewType];
        }

        return $orderedViews;
    }

    /**
     * Replaces the generated candidates for one artwork while keeping a single
     * artwork record. The frontal view remains the active root.
     *
     * @return array{artwork_id:int,selected_file:string,views:array<string,string>}
     */
    public function replaceForJob(PDO $pdo, string $jobId, int $userId, array $files): array
    {
        $jobId = trim($jobId);
        if ($jobId === '' || $userId <= 0) {
            throw new InvalidArgumentException('A valid artwork job and owner are required.');
        }

        $views = self::completeViewSet($files);
        $selectedFile = $views['frontal'];

        return Database::withBusyRetry(function () use ($pdo, $jobId, $userId, $views, $selectedFile): array {
            $artworkStmt = $pdo->prepare('SELECT id, user_id, job_id FROM artworks WHERE job_id = :job_id AND user_id = :user_id LIMIT 1');
            $artworkStmt->execute([
                'job_id' => $jobId,
                'user_id' => $userId,
            ]);
            $artwork = $artworkStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($artwork)) {
                throw new RuntimeException('The artwork record for this root view set was not found.');
            }

            $artworkId = (int)$artwork['id'];
            $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            $columnRows = $driver === 'mysql'
                ? $pdo->query('SHOW COLUMNS FROM root_artwork_candidates')->fetchAll(PDO::FETCH_ASSOC)
                : $pdo->query('PRAGMA table_info(root_artwork_candidates)')->fetchAll(PDO::FETCH_ASSOC);
            $columnsAvailable = array_map(
                static fn(array $row): string => (string)($row['Field'] ?? $row['name'] ?? ''),
                $columnRows
            );

            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            try {
                $pdo->prepare('DELETE FROM root_artwork_candidates WHERE artwork_id = :artwork_id')
                    ->execute(['artwork_id' => $artworkId]);

                $insertColumns = ['artwork_id', 'file_name', 'view_type', 'is_selected', 'created_at'];
                if (in_array('user_id', $columnsAvailable, true)) {
                    array_unshift($insertColumns, 'user_id');
                }
                if (in_array('job_id', $columnsAvailable, true)) {
                    $insertColumns[] = 'job_id';
                }
                if (in_array('updated_at', $columnsAvailable, true)) {
                    $insertColumns[] = 'updated_at';
                }

                $insert = $pdo->prepare(sprintf(
                    'INSERT INTO root_artwork_candidates (%s) VALUES (%s)',
                    implode(', ', $insertColumns),
                    implode(', ', array_map(static fn(string $column): string => ':' . $column, $insertColumns))
                ));
                $now = date('c');
                foreach ($views as $viewType => $file) {
                    $payload = [
                        'artwork_id' => $artworkId,
                        'file_name' => $file,
                        'view_type' => $viewType,
                        'is_selected' => $file === $selectedFile ? 1 : 0,
                        'created_at' => $now,
                    ];
                    if (in_array('user_id', $insertColumns, true)) {
                        $payload['user_id'] = $userId;
                    }
                    if (in_array('job_id', $insertColumns, true)) {
                        $payload['job_id'] = $jobId;
                    }
                    if (in_array('updated_at', $insertColumns, true)) {
                        $payload['updated_at'] = $now;
                    }
                    $insert->execute($payload);
                }

                if ($startedTransaction) {
                    $pdo->commit();
                }
            } catch (Throwable $e) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            return [
                'artwork_id' => $artworkId,
                'selected_file' => $selectedFile,
                'views' => $views,
            ];
        }, 12);
    }
}
