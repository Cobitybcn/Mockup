<?php
// poc_trigger.php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Storage\StorageClient;

// Ensure this file is only run by admins or during local debug
$user = Auth::user();
if (!$user || !Auth::isAdmin($user)) {
    // If not running in CLI and not admin, reject
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$projectId = app_env('GCP_PROJECT_ID', '');
$location = app_env('GCP_LOCATION', 'us-central1');
$queue = app_env('GCP_QUEUE_NAME', 'mockups-generation-queue');
$workerUrl = app_env('GCP_WORKER_URL', '');
$invokerSa = app_env('GCP_TASKS_INVOKER_SA', '');
$bucketName = app_env('GCS_BUCKET_NAME', '');

$action = $_GET['action'] ?? $argv[1] ?? 'status';

echo "<h1>PoC Trigger & Verification Page</h1>";
echo "<p>Action selected: <strong>" . htmlspecialchars($action) . "</strong></p>";

if (!$projectId || !$bucketName) {
    echo "<p style='color:red;'>Error: GCP_PROJECT_ID and GCS_BUCKET_NAME must be configured in .env</p>";
    exit;
}

if ($action === 'status') {
    echo "<h3>Current Configured Values:</h3>";
    echo "<ul>";
    echo "<li>Project ID: " . htmlspecialchars($projectId) . "</li>";
    echo "<li>Location: " . htmlspecialchars($location) . "</li>";
    echo "<li>Queue: " . htmlspecialchars($queue) . "</li>";
    echo "<li>Worker URL: " . htmlspecialchars($workerUrl) . "</li>";
    echo "<li>Invoker SA: " . htmlspecialchars($invokerSa) . "</li>";
    echo "<li>GCS Bucket: " . htmlspecialchars($bucketName) . "</li>";
    echo "</ul>";
    echo "<p><a href='?action=tasks'>Test 1: Enqueue Cloud Task targeting private worker</a></p>";
    echo "<p><a href='?action=storage'>Test 2: Test GCS V4 Signed URL generation</a></p>";
} elseif ($action === 'tasks') {
    if (!$workerUrl || !$invokerSa) {
        echo "<p style='color:red;'>Error: GCP_WORKER_URL and GCP_TASKS_INVOKER_SA must be configured for tasks test</p>";
        exit;
    }

    try {
        echo "<p>Initializing CloudTasksClient...</p>";
        $client = new CloudTasksClient();
        $queueName = $client->queueName($projectId, $location, $queue);

        $payload = [
            'poc' => true,
            'job_id' => 9999,
            'timestamp' => date('c')
        ];

        echo "<p>Creating HttpRequest payload targeting <code>" . htmlspecialchars($workerUrl) . "</code>...</p>";
        $oidcToken = new OidcToken();
        $oidcToken->setServiceAccountEmail($invokerSa);
        $oidcToken->setAudience($workerUrl);

        $httpRequest = new HttpRequest();
        $httpRequest->setUrl($workerUrl);
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setHeaders(['Content-Type' => 'application/json']);
        $httpRequest->setBody(json_encode($payload));
        $httpRequest->setOidcToken($oidcToken);

        $task = new Task();
        $task->setHttpRequest($httpRequest);

        echo "<p>Enqueuing task to queue <code>" . htmlspecialchars($queueName) . "</code>...</p>";
        $response = $client->createTask($queueName, $task);

        echo "<p style='color:green;'><strong>Success!</strong> Task enqueued successfully.</p>";
        echo "<pre>" . htmlspecialchars(print_r($response->getName(), true)) . "</pre>";
    } catch (Exception $e) {
        echo "<p style='color:red;'><strong>Failed to enqueue task:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} elseif ($action === 'storage') {
    try {
        echo "<p>Initializing StorageClient...</p>";
        $storage = new StorageClient([
            'projectId' => $projectId
        ]);
        $bucket = $storage->bucket($bucketName);

        $testObjectName = 'poc_test_file.txt';
        $testContent = "Proof of Concept test file generated at " . date('c');

        echo "<p>Uploading test object <code>" . htmlspecialchars($testObjectName) . "</code> to private bucket...</p>";
        $bucket->upload($testContent, [
            'name' => $testObjectName
        ]);

        echo "<p style='color:green;'>Upload complete!</p>";

        echo "<p>Generating V4 Signed URL (valid for 2 minutes)...</p>";
        $object = $bucket->object($testObjectName);
        $signedUrl = $object->signedUrl(
            new DateTime('2 minutes'),
            [
                'version' => 'v4'
            ]
        );

        echo "<p style='color:green;'><strong>Signed URL Generated:</strong></p>";
        echo "<p><a href='" . htmlspecialchars($signedUrl) . "' target='_blank'>Click here to open signed URL (expires in 2m)</a></p>";
        echo "<textarea style='width:100%;height:100px;'>" . htmlspecialchars($signedUrl) . "</textarea>";

        $unsignedUrl = sprintf("https://storage.googleapis.com/%s/%s", $bucketName, $testObjectName);
        echo "<p><strong>Unsigned (Direct) URL (Should return 403 Access Denied):</strong></p>";
        echo "<p><a href='" . htmlspecialchars($unsignedUrl) . "' target='_blank'>" . htmlspecialchars($unsignedUrl) . "</a></p>";

    } catch (Exception $e) {
        echo "<p style='color:red;'><strong>Storage test failed:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<p><a href='?action=status'>Back to Status</a></p>";
