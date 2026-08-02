<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

/**
 * Hands the browser a place to PUT a finished video. Returning null for the URL
 * is a valid answer: without a bucket the caller posts the file the old way,
 * which is what local development does.
 */
VideoHttp::handle(static function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Your session expired. Sign in again and retry.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $repository = new VideoStudioRepository(Database::connection());
    $service = new VideoFinalUploadService($repository, new VideoJobRepository($repository->pdo()));
    $destination = $service->signedDestination(
        (int)$user['id'],
        (int)($input['projectId'] ?? 0),
        (string)($input['fileName'] ?? ''),
        (string)($input['contentType'] ?? ''),
        (int)($input['bytes'] ?? 0)
    );

    return $destination ?? ['uploadUrl' => null];
});
