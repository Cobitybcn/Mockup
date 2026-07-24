<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$configuredWorkerUrls = array_filter([
    app_env('GCP_WORKER_URL', ''),
    app_env('GCP_EDITORIAL_WORKER_URL', ''),
]);
$configuredWorkerHosts = array_values(array_filter(array_map(
    static fn(string $url): string => (string)(parse_url($url, PHP_URL_HOST) ?: ''),
    $configuredWorkerUrls
)));
$requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');
if (PHP_SAPI !== 'cli' && $configuredWorkerHosts !== [] && !in_array(strtolower($requestHost), array_map('strtolower', $configuredWorkerHosts), true)) {
    http_response_code(404);
    exit;
}

if (PHP_SAPI !== 'cli') {
    $expectedWorkerToken = trim(app_env('EDITORIAL_WORKER_TOKEN', ''));
    $receivedWorkerToken = trim((string)($_SERVER['HTTP_X_EDITORIAL_WORKER_TOKEN'] ?? ''));
    if ($expectedWorkerToken !== '' && !hash_equals($expectedWorkerToken, $receivedWorkerToken)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Editorial worker authorization failed.']);
        exit;
    }
}

$payload = PHP_SAPI === 'cli'
    ? ['job_id' => (int)($argv[1] ?? 0)]
    : json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($payload) ? $payload : [];
$jobId = max(0, (int)($payload['job_id'] ?? 0));

if ($jobId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing editorial generation job ID.']);
    exit(PHP_SAPI === 'cli' ? 1 : 0);
}

$result = (new BilingualEditorialGenerationWorker(Database::connection()))->process($jobId);
// The terminal outcome is persisted for the artist. Do not multiply a
// completed or failed AI call through infrastructure retries.
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit(!empty($result['ok']) ? 0 : (PHP_SAPI === 'cli' ? 1 : 0));
