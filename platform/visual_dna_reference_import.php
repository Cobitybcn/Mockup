<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('POST is required.');
    }
    if (!filter_var(app_env('STUDIO_REFERENCES_LAB_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN)) {
        http_response_code(404);
        throw new RuntimeException('Visual DNA LAB is disabled.');
    }

    $user = Auth::requireUser();
    if (!Auth::isAdmin($user)) {
        http_response_code(403);
        throw new RuntimeException('You do not have access to this ADMIN LAB.');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid external reference payload.');
    }
    $expectedCsrf = (string)($_SESSION['reference_set_csrf'] ?? '');
    $providedCsrf = (string)($input['csrf'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        http_response_code(403);
        throw new RuntimeException('The editor session expired. Reload the page and try again.');
    }

    $reference = (new ReferenceAssetService(Database::connection()))->importFromUrl(
        (int)$user['id'],
        (string)($input['url'] ?? ''),
        (string)($input['title'] ?? ''),
        (string)($input['category'] ?? 'Other')
    );

    echo json_encode(['ok' => true, 'reference' => $reference], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    error_log('Visual DNA remote reference import failed: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
