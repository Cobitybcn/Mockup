<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $pdo = Database::connection();
    $service = new VideoTimelineService(new VideoStudioRepository($pdo), new VideoJobRepository($pdo));
    return $service->update(
        (int)$user['id'],
        (int)($input['projectId'] ?? 0),
        (int)($input['version'] ?? 0),
        $input
    );
});
