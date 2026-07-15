<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) {
        VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    }

    $input = VideoHttp::input();
    VideoHttp::verifyCsrf($input);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $userId = (int)$user['id'];
    $repository = new VideoStudioRepository(Database::connection());
    $service = new VideoStudioService($repository);
    $action = strtolower(trim((string)($input['action'] ?? '')));

    return match ($action) {
        'project_list' => ['projects' => $service->listProjects($userId)],
        'project_read' => $service->studioPayload($userId, (int)($input['projectId'] ?? 0)),
        'project_create' => $service->createProject($userId, (array)($input['project'] ?? $input)),
        'project_update' => $service->updateProject(
            $userId,
            (int)($input['projectId'] ?? 0),
            (int)($input['version'] ?? 0),
            (array)($input['changes'] ?? [])
        ),
        'project_delete' => $service->deleteProject(
            $userId,
            (int)($input['projectId'] ?? 0),
            (int)($input['version'] ?? 0)
        ),
        'scene_create' => $service->createScene(
            $userId,
            (int)($input['projectId'] ?? 0),
            (int)($input['version'] ?? 0),
            (array)($input['scene'] ?? [])
        ),
        'scene_update' => $service->updateScene(
            $userId,
            (int)($input['sceneId'] ?? 0),
            (int)($input['version'] ?? 0),
            (array)($input['changes'] ?? [])
        ),
        'scene_reorder' => $service->reorderScenes(
            $userId,
            (int)($input['projectId'] ?? 0),
            (int)($input['version'] ?? 0),
            (array)($input['sceneIds'] ?? [])
        ),
        'scene_duplicate' => $service->duplicateScene(
            $userId,
            (int)($input['sceneId'] ?? 0),
            (int)($input['version'] ?? 0)
        ),
        'scene_delete' => $service->deleteScene(
            $userId,
            (int)($input['sceneId'] ?? 0),
            (int)($input['version'] ?? 0)
        ),
        'reference_add' => $service->addReference(
            $userId,
            (int)($input['sceneId'] ?? 0),
            (int)($input['version'] ?? 0),
            (array)($input['reference'] ?? [])
        ),
        'reference_remove' => $service->removeReference(
            $userId,
            (int)($input['referenceId'] ?? 0),
            (int)($input['version'] ?? 0)
        ),
        'library_list' => ['assets' => $service->library($userId)],
        default => throw new InvalidArgumentException('Unknown Video Studio action.'),
    };
});
