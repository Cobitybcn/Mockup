<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
$user = Auth::requireUser();
FeatureAccess::requireJson($user, FeatureAccess::VIDEO_MANAGE, 'Video Studio');

VideoHttp::handle(static function () use ($user): array {
    $service = new VideoEditorService(Database::connection(), new VideoJobRepository(Database::connection()), new VideoTaskDispatcher());
    return ['job' => $service->status((int)$user['id'], (int)($_GET['jobId'] ?? 0))];
});
