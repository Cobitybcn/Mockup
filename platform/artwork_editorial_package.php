<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Method not allowed.');
    }

    $user = Auth::requireUser();
    Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'artwork_editorial_package');
    $userId = (int)$user['id'];
    $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
    if ($artworkId < 1) {
        throw new RuntimeException('Artwork not found.');
    }

    $pdo = Database::connection();
    if (!(new BilingualEditorialService($pdo))->isEnabled($userId)) {
        throw new RuntimeException('Editorial preparation is not enabled for this account.');
    }

    $service = new ArtworkEditorialPackageService($pdo);
    $action = trim((string)($_POST['action'] ?? 'status'));
    if ($action === 'start') {
        $service->start($userId, $artworkId);
    } elseif ($action === 'retry_failed') {
        $packageId = max(0, (int)($_POST['package_id'] ?? 0));
        if ($packageId < 1) {
            throw new RuntimeException('Editorial package not found.');
        }
        $service->retryFailed($userId, $packageId);
    } elseif ($action !== 'status') {
        throw new RuntimeException('Invalid editorial package action.');
    }

    echo json_encode([
        'ok' => true,
        'audit' => $service->audit($userId, $artworkId),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error->getMessage() === 'Method not allowed.' ? 405 : 422);
    echo json_encode(
        ['ok' => false, 'error' => $error->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
