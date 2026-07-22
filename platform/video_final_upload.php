<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $file = $_FILES['video'] ?? null;
    if (!is_array($file)) throw new InvalidArgumentException('Select a final video.');
    $repository = new VideoStudioRepository(Database::connection());
    $service = new VideoFinalUploadService($repository, new VideoJobRepository($repository->pdo()));
    return $service->upload(
        (int)$user['id'],
        (int)($_POST['projectId'] ?? 0),
        $file,
        (int)($_POST['artworkId'] ?? 0)
    );
});
