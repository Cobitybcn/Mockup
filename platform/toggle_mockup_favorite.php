<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $pdo = Database::connection();
    $mockupId = (int)($_POST['mockup_id'] ?? $_GET['mockup_id'] ?? 0);
    $result = MockupFavorites::toggle($pdo, (int)$user['id'], $mockupId);

    echo json_encode([
        'ok' => true,
        'mockup_id' => $mockupId,
        'favorite' => $result['favorite'],
        'favorites' => $result['favorites'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
