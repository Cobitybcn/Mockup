<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'ok' => false,
        'error' => 'Use POST to schedule publications.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $user = Auth::requireUser();
    Auth::start();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid publication request.');
    $csrf = (string)($_SESSION['social_media_board_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) {
        throw new RuntimeException('The publication session expired. Reload the board and try again.');
    }
    $deliveryMode = strtolower(trim((string)($input['schedule']['mode'] ?? 'scheduled'))) === 'now' ? 'now' : 'scheduled';
    $expectedConfirmation = $deliveryMode === 'now' ? 'PUBLICAR_AHORA' : 'PROGRAMAR';
    if ((string)($input['confirmation'] ?? '') !== $expectedConfirmation) {
        throw new RuntimeException('Explicit publication confirmation is required.');
    }

    $pdo = Database::connection();
    $jobs = new SocialPublishJobService($pdo);
    $result = (new SocialBoardPublishService($pdo, $jobs))->schedule($user, $input);
    echo json_encode([
        'ok' => true,
        'publication_count' => $result['publication_count'],
        'jobs' => $result['jobs'],
        'delivery_mode' => $result['delivery_mode'],
        'message' => $result['delivery_mode'] === 'now'
            ? $result['publication_count'] . ' publicaciones entraron en la cola para publicarse ahora.'
            : $result['publication_count'] . ' publicaciones fueron validadas y programadas.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
