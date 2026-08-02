<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

VideoHttp::handle(static function (): array {
    // Same reason as the upload endpoint: a redirect here reaches fetch() as an
    // unreadable body, so the session problem has to answer in JSON.
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Your session expired. Sign in again and retry.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $repository = new VideoStudioRepository(Database::connection());
    $final = $repository->assignFinalArtwork(
        (int)$user['id'],
        (int)($_POST['exportId'] ?? 0),
        (int)($_POST['artworkId'] ?? 0)
    );
    return ['final' => $final];
});
