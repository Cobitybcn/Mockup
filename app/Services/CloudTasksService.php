<?php
// app/Services/CloudTasksService.php
declare(strict_types=1);

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\OidcToken;

class CloudTasksService
{
    public static function enqueueGeneration(int $jobId, int $userId, int $artworkId, string $contextId): string
    {
        $projectId = app_env('GCP_PROJECT_ID', '');
        $location = app_env('GCP_LOCATION', 'us-central1');
        $queue = app_env('GCP_QUEUE_NAME', 'mockups-generation-queue');
        $workerUrl = app_env('GCP_WORKER_URL', '');
        $invokerSa = app_env('GCP_TASKS_INVOKER_SA', '');

        if (!$projectId || !$workerUrl || !$invokerSa) {
            throw new RuntimeException('Configuración de GCP incompleta en .env (requiere GCP_PROJECT_ID, GCP_WORKER_URL, GCP_TASKS_INVOKER_SA).');
        }

        // Direct the task execution to worker.php instead of the PoC endpoint
        $targetUrl = str_replace('poc_worker.php', 'worker.php', $workerUrl);

        $client = new CloudTasksClient();
        $queueName = $client->queueName($projectId, $location, $queue);

        $payload = [
            'job_id' => $jobId,
            'user_id' => $userId,
            'artwork_id' => $artworkId,
            'context_id' => $contextId,
            'timestamp' => date('c'),
        ];

        $oidcToken = new OidcToken();
        $oidcToken->setServiceAccountEmail($invokerSa);
        $oidcToken->setAudience($targetUrl);

        $httpRequest = new HttpRequest();
        $httpRequest->setUrl($targetUrl);
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setHeaders(['Content-Type' => 'application/json']);
        $httpRequest->setBody(json_encode($payload));
        $httpRequest->setOidcToken($oidcToken);

        $task = new Task();
        $task->setHttpRequest($httpRequest);

        $response = $client->createTask($queueName, $task);
        return $response->getName();
    }
}
