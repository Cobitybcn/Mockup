<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    Auth::start();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('Use POST.');

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    $csrf = (string)($input['csrf'] ?? '');
    if ($csrf === '' || !hash_equals((string)($_SESSION['website_board_csrf'] ?? ''), $csrf)) {
        throw new RuntimeException('La sesión expiró. Recarga la página.');
    }

    $service = new WebsiteBoardService(Database::connection());
    $userId = (int)$user['id'];
    $action = (string)($input['action'] ?? '');
    $result = match ($action) {
        'catalog_add' => $service->addCatalogArtwork($userId, (string)($input['sourceKey'] ?? '')),
        'catalog_save' => $service->saveCatalog($userId, (int)($input['id'] ?? 0), (array)($input['fields'] ?? [])),
        'catalog_publish', 'catalog_hide', 'catalog_show', 'catalog_unpublish', 'catalog_delete'
            => $service->catalogAction($userId, (int)($input['id'] ?? 0), substr($action, 8)),
        'note_create' => $service->createNote($userId, (string)($input['sourceKey'] ?? '')),
        'note_add_media' => $service->addNoteMedia($userId, (int)($input['id'] ?? 0), (string)($input['sourceKey'] ?? '')),
        'note_reorder_media' => $service->reorderNoteMedia($userId, (int)($input['id'] ?? 0), array_map('strval', (array)($input['keys'] ?? []))),
        'note_remove_media' => $service->removeNoteMedia($userId, (int)($input['id'] ?? 0), (string)($input['sourceKey'] ?? '')),
        'note_save' => $service->saveNote($userId, (int)($input['id'] ?? 0), (string)($input['title'] ?? ''), (string)($input['objective'] ?? '')),
        'note_publish', 'note_unpublish', 'note_delete'
            => $service->noteAction($userId, (int)($input['id'] ?? 0), substr($action, 5)),
        default => throw new RuntimeException('Acción desconocida.'),
    };

    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
