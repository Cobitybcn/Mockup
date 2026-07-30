<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Videos');

VideoHttp::handle(static function () use ($user): array {
    $userId = (int)$user['id'];
    $csrf = (string)($_POST['csrf'] ?? '');
    VideoHttp::verifyCsrf(['csrf' => $csrf]);

    $pdo = Database::connection();
    $jobs = new SocialPublishJobService($pdo);
    $service = new SocialScheduledPublicationService($pdo, $jobs);

    $jobId = max(0, (int)($_POST['jobId'] ?? 0));
    if ($jobId <= 0) {
        throw new InvalidArgumentException('Elegí una publicación programada.');
    }
    $existing = $jobs->job($jobId, $userId);
    if ((string)$existing['channel'] !== 'tiktok') {
        throw new RuntimeException('Esta publicación no pertenece a TikTok Studio.');
    }

    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $confirmation = (string)($_POST['confirmation'] ?? '');
    if ($action === 'reschedule' && $confirmation === 'REPROGRAMAR') {
        $job = $service->reschedule(
            $jobId,
            $userId,
            (string)($_POST['date'] ?? ''),
            (string)($_POST['time'] ?? ''),
            (string)($_POST['timezone'] ?? 'UTC')
        );
    } elseif ($action === 'publish_now' && $confirmation === 'PUBLICAR_AHORA') {
        $job = $service->publishNow($jobId, $userId);
    } elseif ($action === 'retry' && $confirmation === 'REINTENTAR') {
        $job = $service->retry($jobId, $userId);
    } elseif ($action === 'cancel' && $confirmation === 'CANCELAR') {
        $job = $service->cancel($jobId, $userId);
        $payload = json_decode((string)$existing['payload_json'], true);
        $videoExportId = is_array($payload) ? (int)($payload['video_export_id'] ?? 0) : 0;
        if ($videoExportId > 0) {
            (new VideoTikTokPublicationService($pdo))->clearSchedule($userId, $videoExportId);
        }
    } else {
        throw new InvalidArgumentException('Confirmá la acción sobre la publicación programada.');
    }

    return ['job' => $job];
});
