<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $userId = (int)$user['id'];
    $projectId = (int)($_POST['projectId'] ?? 0);
    $version = (int)($_POST['version'] ?? 0);
    $file = (array)($_FILES['music'] ?? []);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $repository = new VideoStudioRepository(Database::connection());
    return (new VideoMusicService($repository))->upload($userId, $projectId, $version, $file);
});
