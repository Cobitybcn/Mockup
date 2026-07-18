<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
VideoHttp::requirePost();
$user = Auth::requireUser();

function editor_uploaded_files(array $input): array
{
    if (!isset($input['name']) || !is_array($input['name'])) return [];
    $files = [];
    foreach ($input['name'] as $index => $name) {
        if ((int)($input['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'name' => $name,'type' => $input['type'][$index] ?? '',
            'tmp_name' => $input['tmp_name'][$index] ?? '',
            'error' => $input['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $input['size'][$index] ?? 0,
        ];
    }
    return $files;
}

VideoHttp::handle(static function () use ($user): array {
    VideoHttp::verifyCsrf(['csrf' => (string)($_POST['csrf'] ?? '')]);
    $service = new VideoEditorService(Database::connection(), new VideoJobRepository(Database::connection()), new VideoTaskDispatcher());
    return $service->start(
        (int)$user['id'],
        (string)($_POST['sourceType'] ?? ''),
        (int)($_POST['sourceId'] ?? 0),
        (string)($_POST['prompt'] ?? ''),
        editor_uploaded_files(is_array($_FILES['images'] ?? null) ? $_FILES['images'] : [])
    );
});
