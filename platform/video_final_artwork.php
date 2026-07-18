<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Studio');

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $repository = new VideoStudioRepository(Database::connection());
    $final = $repository->assignFinalArtwork(
        (int)$user['id'],
        (int)($_POST['exportId'] ?? 0),
        (int)($_POST['artworkId'] ?? 0)
    );
    return ['final' => $final];
});
