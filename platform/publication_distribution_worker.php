<?php
declare(strict_types=1);

/**
 * Cloud Tasks worker for the spaced social series of the Publication section.
 * OIDC-authenticated by Cloud Run; the host guard below rejects any request
 * that did not arrive through the configured worker origin. Always answers
 * HTTP 200 so Cloud Tasks never blind-retries a possibly-completed send —
 * runScheduledSend() is idempotent (a row no longer scheduled is a no-op).
 */
require_once __DIR__ . '/app/bootstrap.php';

$expectedHost = strtolower((string)(parse_url((string)app_env('GCP_WORKER_URL', ''), PHP_URL_HOST) ?: ''));
$requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if ($expectedHost === '' || $requestHost === '' || !str_contains($requestHost, $expectedHost)) {
    http_response_code(403);
    exit('forbidden');
}

$body = json_decode((string)file_get_contents('php://input'), true);
$distributionId = (int)($body['distribution_id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');
if ($distributionId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'missing distribution_id']);
    exit;
}

try {
    (new PublicationDistributionService(Database::connection()))->runScheduledSend($distributionId);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    Logger::log('publication_distribution_worker: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
