<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.');
    }
    if (PHP_SAPI !== 'cli') {
        $expectedToken = trim(app_env('EDITORIAL_WORKER_TOKEN', ''));
        $receivedToken = trim((string)($_SERVER['HTTP_X_EDITORIAL_WORKER_TOKEN'] ?? ''));
        if ($expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
            http_response_code(403);
            throw new RuntimeException('Editorial batch authorization failed.');
        }
    }

    $payload = PHP_SAPI === 'cli'
        ? [
            'action' => (string)($argv[1] ?? 'audit'),
            'email' => (string)($argv[2] ?? ''),
            'audit_token' => (string)($argv[3] ?? ''),
        ]
        : json_decode((string)file_get_contents('php://input'), true);
    $payload = is_array($payload) ? $payload : [];
    $action = trim((string)($payload['action'] ?? 'audit'));
    $email = trim((string)($payload['email'] ?? ''));
    if (!in_array($action, ['audit', 'enqueue'], true)) {
        throw new InvalidArgumentException('Invalid batch action.');
    }

    $service = new MockupEditorialBatchService(Database::connection());
    $result = $action === 'audit'
        ? $service->audit($email)
        : $service->enqueue($email, trim((string)($payload['audit_token'] ?? '')));
    echo json_encode(['ok' => true, 'action' => $action, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    }
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(PHP_SAPI === 'cli' ? 1 : 0);
}
