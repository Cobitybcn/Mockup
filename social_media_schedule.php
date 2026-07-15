<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Use POST to schedule publications.');
    }
    $user = Auth::requireUser();
    Auth::start();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid publication request.');
    $csrf = (string)($_SESSION['social_media_board_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) {
        throw new RuntimeException('The publication session expired. Reload the board and try again.');
    }
    if ((string)($input['confirmation'] ?? '') !== 'PROGRAMAR') {
        throw new RuntimeException('Explicit publication confirmation is required.');
    }

    $pdo = Database::connection();
    $jobs = new SocialPublishJobService($pdo);
    $result = (new SocialBoardPublishService($pdo, $jobs))->schedule($user, $input);
    echo json_encode([
        'ok' => true,
        'publication_count' => $result['publication_count'],
        'jobs' => $result['jobs'],
        'message' => $result['publication_count'] . ' publicaciones fueron validadas y programadas.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
