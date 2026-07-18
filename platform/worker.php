<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/app/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$payload = [];
if ($isCli) {
    $payload = [
        'job_id' => (int)($argv[1] ?? 0),
        'generation_provider' => (string)($argv[2] ?? ''),
    ];
} else {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    $payload = is_array($decoded) ? $decoded : [];
}

$jobId = max(0, (int)($payload['job_id'] ?? 0));
if ($jobId <= 0) {
    if (!$isCli) {
        http_response_code(400);
    }
    echo json_encode(['ok' => false, 'error' => 'Missing job_id in payload.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit($isCli ? 1 : 0);
}

$result = (new MockupGenerationWorker())->process(
    $jobId,
    (string)($payload['generation_provider'] ?? '')
);

// A handled generation error is terminal and must not be multiplied by an
// infrastructure retry. Its status and credit refund are already persisted.
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit(!empty($result['ok']) ? 0 : ($isCli ? 1 : 0));
