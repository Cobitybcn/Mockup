<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
VideoHttp::requirePost();

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    VideoHttp::verifyCsrf($_POST);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $service = new VideoTimelineImportService(new VideoStudioRepository(Database::connection()));
    $objectKey = trim((string)($_POST['objectKey'] ?? ''));
    if ($objectKey !== '') {
        return $service->attachObject(
            (int)$user['id'], (int)($_POST['projectId'] ?? 0), (int)($_POST['version'] ?? 0),
            $objectKey, (string)($_POST['originalName'] ?? '')
        );
    }
    return $service->upload((int)$user['id'], (int)($_POST['projectId'] ?? 0), (int)($_POST['version'] ?? 0), (array)($_FILES['video'] ?? []));
});
