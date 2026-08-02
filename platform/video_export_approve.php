<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

/**
 * The artist saying a montage is finished. Until this runs, a built montage is
 * just the project's current cut and stays out of the finished videos.
 */
VideoHttp::handle(static function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Your session expired. Sign in again and retry.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $repository = new VideoStudioRepository(Database::connection());
    $repository->approveExport(
        (int)$user['id'],
        (int)($input['exportId'] ?? 0),
        !array_key_exists('approved', $input) || (bool)$input['approved']
    );

    return (new VideoStudioService($repository))->studioPayload((int)$user['id'], (int)($input['projectId'] ?? 0));
});
