<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
VideoHttp::requirePost();

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Studio');
    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $pdo = Database::connection();
    $studio = new VideoStudioRepository($pdo);
    $service = new VideoExportService($studio, new VideoJobRepository($pdo), new VideoTaskDispatcher(), new VideoExportBuilder(new VideoMediaStorage()));
    return $service->start((int)$user['id'], (int)($input['projectId'] ?? 0), (int)($input['version'] ?? 0), (string)($input['kind'] ?? 'final'));
});
