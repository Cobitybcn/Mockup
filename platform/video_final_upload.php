<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

VideoHttp::handle(static function (): array {
    // Answering in JSON matters here: requireUser() would redirect to the login
    // page, and fetch() then reports an unreadable body instead of the reason.
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Your session expired. Sign in again and retry the upload.'], 401);
    FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $repository = new VideoStudioRepository(Database::connection());
    $service = new VideoFinalUploadService($repository, new VideoJobRepository($repository->pdo()));

    // The browser puts large files in the bucket itself and sends only the key.
    $objectKey = trim((string)($_POST['objectKey'] ?? ''));
    if ($objectKey !== '') {
        return $service->attachObject(
            (int)$user['id'],
            (int)($_POST['projectId'] ?? 0),
            $objectKey,
            (string)($_POST['originalName'] ?? ''),
            (int)($_POST['artworkId'] ?? 0)
        );
    }

    $file = $_FILES['video'] ?? null;
    if (!is_array($file)) throw new InvalidArgumentException('Select a final video.');
    return $service->upload(
        (int)$user['id'],
        (int)($_POST['projectId'] ?? 0),
        $file,
        (int)($_POST['artworkId'] ?? 0)
    );
});
