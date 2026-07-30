<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Videos');

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $result = (new VideoTikTokPublicationService(Database::connection()))->refreshStatus(
        (int)$user['id'],
        (int)($_POST['exportId'] ?? 0)
    );
    return ['publication' => $result];
});
