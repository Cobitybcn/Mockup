<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'error' => 'Use POST to save destination links.']);
    exit;
}

try {
    $user = Auth::requireUser();
    FeatureAccess::requireJson($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
    Auth::start();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid destination settings request.');
    $csrf = (string)($_SESSION['social_media_board_csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($input['csrf'] ?? ''))) {
        throw new RuntimeException('The settings session expired. Reload the board and try again.');
    }

    $destinations = (new SocialBoardDestinationSettings(Database::connection()))->save(
        (int)$user['id'],
        (string)($input['website'] ?? ''),
        (string)($input['saatchi'] ?? '')
    );
    echo json_encode([
        'ok' => true,
        'destinations' => $destinations,
        'message' => 'Default destination links saved.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
