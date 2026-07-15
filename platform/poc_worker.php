<?php
// poc_worker.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// Secondary validation check for Cloud Tasks headers
$queueHeader = $_SERVER['HTTP_X_CLOUDTASKS_QUEUENAME'] ?? null;

$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Log to php error log (which Cloud Run captures to stdout/Stderr and outputs to Cloud Logging)
error_log("PoC Worker triggered!");
if ($queueHeader) {
    error_log("Triggered from queue: " . $queueHeader);
}
error_log("Payload data: " . json_encode($data));

echo json_encode([
    'ok' => true,
    'message' => 'PoC Worker executed successfully!',
    'queue' => $queueHeader,
    'payload' => $data
]);
