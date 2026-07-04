<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mockupId = max(0, (int)($_POST['mockup_id'] ?? 0));
    if ($mockupId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing mockup id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $mockupId]);
    $mockup = $stmt->fetch();
    if (!$mockup) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Mockup not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((int)$mockup['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Access denied.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mockupFile = basename((string)($mockup['mockup_file'] ?? ''));
    $promptFile = basename((string)($mockup['prompt_file'] ?? ''));

    Database::withBusyRetry(function () use ($pdo, $mockupId): void {
        $delete = $pdo->prepare('DELETE FROM mockups WHERE id = :id');
        $delete->execute(['id' => $mockupId]);
    }, 12);

    if ($mockupFile !== '') {
        $count = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE mockup_file = :file');
        $count->execute(['file' => $mockupFile]);
        if ((int)$count->fetchColumn() === 0) {
            $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $mockupFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    if ($promptFile !== '' && defined('PROMPTS_DIR')) {
        $count = $pdo->prepare('SELECT COUNT(*) FROM mockups WHERE prompt_file = :file');
        $count->execute(['file' => $promptFile]);
        if ((int)$count->fetchColumn() === 0) {
            $path = PROMPTS_DIR . DIRECTORY_SEPARATOR . $promptFile;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
