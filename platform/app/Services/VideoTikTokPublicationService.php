<?php
declare(strict_types=1);

final class VideoTikTokPublicationService
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{status:string,privacyLevel:string,publishId:string} */
    public function publish(int $userId, int $videoExportId, string $caption): array
    {
        $caption = trim($caption);
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

        $mediaToken = bin2hex(random_bytes(32));
        $mediaExpiresAt = date('c', time() + 172800);
        $this->upsertRow($userId, $videoExportId, [
            'status' => 'queued',
            'media_token' => $mediaToken,
            'media_expires_at' => $mediaExpiresAt,
            'error' => '',
        ]);

        $publicVideoUrl = $base.'/tiktok_video_media.php?token='.rawurlencode($mediaToken);
        $publisher = new TikTokPublisher(new TikTokIntegrationService($this->pdo));

        try {
            $result = $publisher->publishVideo($userId, $publicVideoUrl, $caption);
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
