<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $sessionCsrf = (string)($_SESSION['series_csrf'] ?? '');
    $requestCsrf = (string)($_POST['csrf'] ?? '');
    if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $requestCsrf)) {
        throw new RuntimeException('Invalid request.');
    }

    $orderedSeriesIds = is_array($_POST['series_ids'] ?? null)
        ? (array)$_POST['series_ids']
        : [];
    ArtworkSeries::reorderSeries(
        Database::connection(),
        (int)$user['id'],
        $orderedSeriesIds
    );

    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
