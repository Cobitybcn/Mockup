<?php
declare(strict_types=1);

/**
 * Schedules a finished video for TikTok delivery through the same Cloud
 * Tasks engine already used for Instagram/Facebook scheduled publications
 * (SocialPublishJobService + CloudTasksService::enqueueSocialPublication) —
 * no separate queue or worker service, only a 'tiktok' channel branch in
 * social_publish_worker.php.
 */
final class TikTokPublishScheduler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SocialPublishJobService $jobs,
        private readonly VideoTikTokPublicationService $publications
    ) {}

    /** @return array{id:int,channel:string,status:string,scheduled_at:string} */
    public function schedule(
        array $user,
        int $videoExportId,
        string $caption,
        DateTimeImmutable $when,
        int $boardId = 0,
        int $coverTimestampMs = 0,
        string $destinationUrl = '',
        string $tags = ''
    ): array {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            throw new RuntimeException('Sesión inválida.');
        }
        $caption = trim($caption);
        $tags = trim($tags);
        if ($caption === '') {
            throw new InvalidArgumentException('El video necesita un texto para publicarse en TikTok.');
        }
        if (mb_strlen($caption . ' ' . $tags) > 2200) {
            throw new InvalidArgumentException('TikTok acepta hasta 2200 caracteres entre el texto y los tags.');
        }
        $this->assertVideoExportOwned($userId, $videoExportId);

        $base = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
        if (!str_starts_with(strtolower($base), 'https://')) {
            throw new RuntimeException('Programar en TikTok requiere el sitio público HTTPS. Localhost solo permite validar el diseño.');
        }
        if (app_env('TIKTOK_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
            throw new RuntimeException('La publicación en vivo de TikTok no está habilitada en este entorno.');
        }

        $coverTimestampMs = max(0, $coverTimestampMs);
        $destinationUrl = mb_substr(trim($destinationUrl), 0, 500);
        $normalized = [
            'video_export_id' => $videoExportId,
            'caption' => $caption,
            'tags' => $tags,
            'cover_timestamp_ms' => $coverTimestampMs,
            'destination_url' => $destinationUrl,
            'scheduled_at' => $when->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM),
        ];
        $key = hash('sha256', json_encode([$userId, 'tiktok', $normalized], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $job = $this->jobs->findByKey($userId, $key) ?? $this->jobs->create($userId, 'tiktok', 'artist', $when, [
            'schema_version' => 'tiktok-studio-job.v2',
            'video_export_id' => $videoExportId,
            'caption' => $caption,
            'tags' => $tags,
            'cover_timestamp_ms' => $coverTimestampMs,
            'destination_url' => $destinationUrl,
        ], $key);

        $summary = $this->enqueueIfNeeded($job, $when);
        if ($summary['status'] !== 'published') {
            $this->publications->markScheduled($userId, $videoExportId, $boardId, $summary['id'], $caption, $coverTimestampMs, $destinationUrl, $tags);
        }
        return $summary;
    }

    public function scheduledAt(string $date, string $time, string $timezone): DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        try {
            $zone = new DateTimeZone(trim($timezone) !== '' ? trim($timezone) : 'UTC');
        } catch (Throwable) {
            throw new InvalidArgumentException('Zona horaria inválida.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            throw new InvalidArgumentException('Elegí una fecha y hora de publicación válidas.');
        }
        $when = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$when || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            throw new InvalidArgumentException('Elegí una fecha y hora de publicación válidas.');
        }
        $now = new DateTimeImmutable('now', $zone);
        if ($when < $now->modify('-1 minute')) {
            throw new InvalidArgumentException('La fecha de publicación no puede estar en el pasado.');
        }
        if ($when > $now->modify('+30 days')) {
            throw new InvalidArgumentException('Se puede programar hasta 30 días por adelantado.');
        }
        return $when < $now ? $now : $when;
    }

    private function enqueueIfNeeded(array $job, DateTimeImmutable $when): array
    {
        $status = (string)($job['status'] ?? '');
        if ($status === 'failed') {
            throw new RuntimeException('Esta publicación falló anteriormente. Usá Reintentar para no crear un duplicado incierto.');
        }
        if ($status === 'needs_verification') {
            throw new RuntimeException('Verificá primero la cuenta de TikTok antes de intentar esta publicación nuevamente.');
        }
        if ($status === 'published' || trim((string)($job['task_name'] ?? '')) !== '') {
            return $this->summary($job);
        }
        try {
            $taskName = CloudTasksService::enqueueSocialPublication((int)$job['id'], $when);
            $this->jobs->attachTask((int)$job['id'], (int)$job['user_id'], $taskName);
        } catch (Throwable $e) {
            $this->jobs->markEnqueueFailed((int)$job['id'], (int)$job['user_id'], $e->getMessage());
            throw new RuntimeException('El video quedó preparado pero no se pudo agendar. ' . $e->getMessage(), 0, $e);
        }
        return $this->summary($this->jobs->job((int)$job['id'], (int)$job['user_id']));
    }

    private function summary(array $job): array
    {
        return [
            'id' => (int)$job['id'],
            'channel' => (string)$job['channel'],
            'status' => (string)$job['status'],
            'scheduled_at' => (string)$job['scheduled_at'],
        ];
    }

    private function assertVideoExportOwned(int $userId, int $videoExportId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.id FROM video_exports e
             INNER JOIN video_projects p ON p.id=e.video_project_id AND p.user_id=e.user_id
             WHERE e.id=? AND e.user_id=? AND e.status='succeeded' LIMIT 1"
        );
        $stmt->execute([$videoExportId, $userId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('El video seleccionado no está disponible.');
        }
    }
}
