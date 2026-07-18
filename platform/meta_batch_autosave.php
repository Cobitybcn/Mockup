<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    FeatureAccess::requireJson($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
    Auth::start();
    $userId = (int)$user['id'];
    if (!hash_equals(
        (string)($_SESSION['meta_batch_csrf'] ?? ''),
        (string)($_POST['csrf'] ?? '')
    )) {
        throw new RuntimeException('Meta review session expired. Reload the batch.');
    }
    $batchId = max(0, (int)($_POST['id'] ?? 0));
    $draftId = max(0, (int)($_POST['draft_id'] ?? 0));
    $pdo = Database::connection();
    $drafts = new MetaSocialDraftService($pdo);
    $batches = new MetaBatchService($pdo, $drafts);
    $items = $batches->items($batchId, $userId);
    if (!in_array($draftId, array_map(static fn (array $item): int => (int)$item['id'], $items), true)) {
        throw new RuntimeException('Draft does not belong to this Meta batch.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_content') {
        $drafts->updateContent(
            $draftId,
            $userId,
            (string)($_POST['title'] ?? ''),
            (string)($_POST['description'] ?? ''),
            (string)($_POST['hashtags'] ?? ''),
            (string)($_POST['alt_text'] ?? ''),
            (string)($_POST['destination_url'] ?? '')
        );
    } elseif ($action === 'save_crop') {
        $drafts->saveCrop(
            $draftId,
            $userId,
            (float)($_POST['crop_x'] ?? 0.5),
            (float)($_POST['crop_y'] ?? 0.5),
            (float)($_POST['crop_zoom'] ?? 1)
        );
    } else {
        throw new RuntimeException('Unknown Meta batch action.');
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
