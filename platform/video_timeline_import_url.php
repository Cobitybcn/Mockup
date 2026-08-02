<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
VideoHttp::requirePost();

VideoHttp::handle(static function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $destination = (new VideoTimelineImportService(new VideoStudioRepository(Database::connection())))->signedDestination(
        (int)$user['id'], (int)($input['projectId'] ?? 0), (int)($input['version'] ?? 0),
        (string)($input['contentType'] ?? ''), (int)($input['bytes'] ?? 0)
    );
    return $destination ?? ['uploadUrl' => null];
});
