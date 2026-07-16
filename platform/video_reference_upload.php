<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

VideoHttp::requirePost();

/** @return list<array<string,mixed>> */
function video_uploaded_reference_files(array $input): array
{
    if (!isset($input['name'])) return [];
    if (!is_array($input['name'])) return [$input];
    $files = [];
    foreach (array_keys($input['name']) as $index) {
        $files[] = [
            'name' => $input['name'][$index] ?? '',
            'type' => $input['type'][$index] ?? '',
            'tmp_name' => $input['tmp_name'][$index] ?? '',
            'error' => $input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'][$index] ?? 0,
        ];
    }
    return $files;
}

VideoHttp::handle(function (): array {
    $user = Auth::user();
    if (!$user) VideoHttp::respond(['ok' => false, 'error' => 'Authentication required.'], 401);
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $userId = (int)$user['id'];
    $sceneId = (int)($_POST['sceneId'] ?? 0);
    $version = (int)($_POST['version'] ?? 0);
    $role = (string)($_POST['role'] ?? 'reference');
    $files = video_uploaded_reference_files((array)($_FILES['references'] ?? []));
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $repository = new VideoStudioRepository(Database::connection());
    return (new VideoReferenceUploadService($repository))->upload($userId, $sceneId, $version, $files, $role);
});
