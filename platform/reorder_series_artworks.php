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

    $seriesId = max(0, (int)($_POST['series_id'] ?? 0));
    $orderedArtworkIds = is_array($_POST['artwork_ids'] ?? null)
        ? (array)$_POST['artwork_ids']
        : [];
    $positions = ArtworkSeries::reorderArtworks(
        Database::connection(),
        (int)$user['id'],
        $seriesId,
        $orderedArtworkIds
    );

    echo json_encode([
        'ok' => true,
        'positions' => $positions,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
