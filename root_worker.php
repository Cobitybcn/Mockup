<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/app/bootstrap.php';

$configuredWorkerUrl = app_env('GCP_WORKER_URL', '');
$configuredWorkerHost = $configuredWorkerUrl !== '' ? (string)(parse_url($configuredWorkerUrl, PHP_URL_HOST) ?: '') : '';
$requestHost = (string)($_SERVER['HTTP_HOST'] ?? '');

if ($configuredWorkerHost !== '' && strcasecmp($configuredWorkerHost, $requestHost) !== 0) {
    http_response_code(404);
    exit;
}

$payload = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

$jobId = basename((string)($payload['job_id'] ?? $_GET['job'] ?? ''));
if ($jobId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing job_id']);
    exit;
}

define('PROCESS_JOB_ID', $jobId);
define('PROCESS_GENERATE_ALLOW_HTTP', true);

require __DIR__ . '/process_generate.php';
