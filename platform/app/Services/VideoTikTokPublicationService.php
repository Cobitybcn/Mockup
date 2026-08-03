<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/Video/VideoMediaStorage.php';

final class VideoTikTokPublicationService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{status:string,privacyLevel:string,publishId:string} */
    public function publish(
        int $userId,
        int $videoExportId,
        string $caption,
        int $coverTimestampMs = 0,
        string $destinationUrl = '',
        string $tags = ''
    ): array {
        $caption = trim($caption);
        $tags = trim($tags);
        if ($caption === '') {
            throw new InvalidArgumentException('El video necesita un texto para publicarse en TikTok.');
        }
        $export = $this->videoExport($videoExportId, $userId);
        if ($export === null) {
            throw new RuntimeException('Video no encontrado o todavía no terminó de procesarse.');
        }

        $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
        if (!str_starts_with(strtolower($base), 'https://')) {
            throw new RuntimeException('Publicar en TikTok requiere el sitio público HTTPS. Localhost solo permite validar el diseño.');
        }
        if (app_env('TIKTOK_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
            throw new RuntimeException('La publicación en vivo de TikTok no está habilitada en este entorno.');
        }

        $this->upsertRow($userId, $videoExportId, [
            'status' => 'queued',
            'media_token' => '',
            'media_expires_at' => null,
            'error' => '',
            'caption' => $caption,
            'tags' => $tags,
            'cover_timestamp_ms' => max(0, $coverTimestampMs),
            'is_aigc' => 1,
            'destination_url' => mb_substr(trim($destinationUrl), 0, 500),
        ]);

        $outputKey = trim((string)($export['output_path'] ?? ''));
        $storage = new VideoMediaStorage();
        $videoPath = $storage->localObjectPath($outputKey);
        if (!is_file($videoPath)) {
            StorageService::downloadFile($outputKey, $videoPath);
        }
        if (!is_file($videoPath)) {
            throw new RuntimeException('No se pudo recuperar el video final para enviarlo a TikTok.');
        }
        $publisher = new TikTokPublisher(new TikTokIntegrationService($this->pdo));
        $fullText = trim($caption . ($tags !== '' ? "\n\n" . $tags : ''));

        try {
            $result = $publisher->publishVideoFile($userId, $videoPath, $fullText, 'artist', max(0, $coverTimestampMs), true);
        } catch (Throwable $e) {
            $this->upsertRow($userId, $videoExportId, [
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 1500),
            ]);
            throw $e;
        }

        $this->upsertRow($userId, $videoExportId, [
            'status' => 'processing',
            'privacy_level' => $result['privacyLevel'],
            'tiktok_publish_id' => $result['publishId'],
            'error' => '',
        ]);

        return ['status' => 'processing', 'privacyLevel' => $result['privacyLevel'], 'publishId' => $result['publishId']];
    }

    public function refreshStatus(int $userId, int $videoExportId): array
    {
        $row = $this->row($userId, $videoExportId);
        if ($row === null || trim((string)$row['tiktok_publish_id']) === '') {
            throw new RuntimeException('Este video todavía no fue enviado a TikTok.');
        }
        $publisher = new TikTokPublisher(new TikTokIntegrationService($this->pdo));
        $status = $publisher->fetchStatus($userId, (string)$row['tiktok_publish_id']);

        $mapped = match (strtoupper($status['status'])) {
            'PUBLISH_COMPLETE' => 'published',
            'FAILED' => 'failed',
            default => 'processing',
        };
        $this->upsertRow($userId, $videoExportId, [
            'status' => $mapped,
            'error' => $mapped === 'failed' ? mb_substr($status['failReason'], 0, 1500) : '',
        ]);
        return ['status' => $mapped, 'raw' => $status['status']];
    }

    public function markScheduled(
        int $userId,
        int $videoExportId,
        int $boardId,
        int $scheduleJobId,
        string $caption,
        int $coverTimestampMs,
        string $destinationUrl,
        string $tags = ''
    ): void {
        $this->upsertRow($userId, $videoExportId, [
            'status' => 'scheduled',
            'board_id' => $boardId,
            'schedule_job_id' => $scheduleJobId,
            'caption' => trim($caption),
            'tags' => trim($tags),
            'cover_timestamp_ms' => max(0, $coverTimestampMs),
            'is_aigc' => 1,
            'destination_url' => mb_substr(trim($destinationUrl), 0, 500),
            'error' => '',
        ]);
    }

    public function clearSchedule(int $userId, int $videoExportId): void
    {
        $row = $this->row($userId, $videoExportId);
        if ($row === null || (string)$row['status'] !== 'scheduled') {
            return;
        }
        $this->upsertRow($userId, $videoExportId, [
            'status' => '',
            'schedule_job_id' => null,
            'error' => '',
        ]);
    }

    public function row(int $userId, int $videoExportId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_tiktok_publications WHERE user_id=? AND video_export_id=? LIMIT 1');
        $stmt->execute([$userId, $videoExportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<int,array> keyed by video_export_id */
    public function rowsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM video_tiktok_publications WHERE user_id=?');
        $stmt->execute([$userId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(int)$row['video_export_id']] = $row;
        }
        return $rows;
    }

    private function videoExport(int $exportId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id,e.output_path,e.status
             FROM video_exports e
             INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
             WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1"
        );
        $stmt->execute([$exportId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function upsertRow(int $userId, int $videoExportId, array $fields): void
    {
        $now = date('c');
        $existing = $this->row($userId, $videoExportId);
        if ($existing) {
            $sets = [];
            $values = [];
            foreach ($fields as $column => $value) {
                $sets[] = "{$column}=?";
                $values[] = $value;
            }
            $sets[] = 'updated_at=?';
            $values[] = $now;
            $values[] = $userId;
            $values[] = $videoExportId;
            $this->pdo->prepare('UPDATE video_tiktok_publications SET '.implode(',', $sets).' WHERE user_id=? AND video_export_id=?')
                ->execute($values);
            return;
        }
        $columns = array_merge(['user_id', 'video_export_id'], array_keys($fields), ['created_at', 'updated_at']);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $values = array_merge([$userId, $videoExportId], array_values($fields), [$now, $now]);
        $this->pdo->prepare('INSERT INTO video_tiktok_publications ('.implode(',', $columns).') VALUES ('.$placeholders.')')
            ->execute($values);
    }
}
