<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Videos');

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $userId = (int)$user['id'];
    $exportId = (int)($_POST['exportId'] ?? 0);
    $pdo = Database::connection();

    $publications = new VideoTikTokPublicationService($pdo);
    $row = $publications->row($userId, $exportId);
    if (is_array($row) && in_array((string)$row['status'], ['processing', 'published'], true)) {
        throw new DomainException('Este video ya se envió a TikTok y no se puede quitar del board.');
    }
    if (is_array($row) && (string)$row['status'] === 'scheduled' && (int)($row['schedule_job_id'] ?? 0) > 0) {
        $jobs = new SocialPublishJobService($pdo);
        $scheduled = new SocialScheduledPublicationService($pdo, $jobs);
        try {
            $scheduled->cancel((int)$row['schedule_job_id'], $userId);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo cancelar la publicación programada. ' . $e->getMessage());
        }
        $publications->clearSchedule($userId, $exportId);
    }

    (new TikTokBoardService($pdo))->unassignVideo($userId, $exportId);
    return [];
});
