<?php
// app/Services/CloudTasksService.php
declare(strict_types=1);

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Protobuf\Timestamp;

class CloudTasksService
{
    public static function enqueueGeneration(int $jobId, int $userId, int $artworkId, string $contextId): string
    {
        return self::enqueue('worker.php', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'artwork_id' => $artworkId,
            'context_id' => $contextId,
            'timestamp' => date('c'),
        ]);
    }

    public static function enqueueRootGeneration(string $jobId, int $userId): string
    {
        return self::enqueue('root_worker.php', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'timestamp' => date('c'),
        ]);
    }

    public static function enqueueSocialPublication(int $jobId, DateTimeImmutable $scheduledAt): string
    {
        return self::enqueue('social_publish_worker.php', [
            'job_id' => $jobId,
            'timestamp' => date('c'),
        ], $scheduledAt, app_env('GCP_SOCIAL_QUEUE_NAME', app_env('GCP_QUEUE_NAME', 'mockups-generation-queue')));
    }

    private static function enqueue(
        string $workerScript,
        array $payload,
        ?DateTimeImmutable $scheduledAt = null,
        string $queueOverride = ''
    ): string
    {
        $projectId = app_env('GCP_PROJECT_ID', '');
        $location = app_env('GCP_LOCATION', 'us-central1');
        $queue = $queueOverride !== '' ? $queueOverride : app_env('GCP_QUEUE_NAME', 'mockups-generation-queue');
        $workerUrl = app_env('GCP_WORKER_URL', '');
        $invokerSa = app_env('GCP_TASKS_INVOKER_SA', '');

        if (!$projectId || !$workerUrl || !$invokerSa) {
            throw new RuntimeException('Configuración de GCP incompleta en .env (requiere GCP_PROJECT_ID, GCP_WORKER_URL, GCP_TASKS_INVOKER_SA).');
        }

        $targetUrl = self::targetUrl($workerUrl, $workerScript);

        $client = new CloudTasksClient();
        $queueName = $client->queueName($projectId, $location, $queue);

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
        if ($scheduledAt instanceof DateTimeImmutable) {
            $utc = $scheduledAt->setTimezone(new DateTimeZone('UTC'));
            $timestamp = new Timestamp();
            $timestamp->setSeconds($utc->getTimestamp());
            $task->setScheduleTime($timestamp);
        }

        $response = $client->createTask($queueName, $task);
        return $response->getName();
    }

    private static function targetUrl(string $workerUrl, string $workerScript): string
    {
        $workerUrl = trim($workerUrl);
        if ($workerUrl === '') {
            return $workerUrl;
        }

        if (preg_match('#/[^/]+\.php(?:\?.*)?$#', $workerUrl) === 1) {
            return preg_replace('#/[^/]+\.php(?:\?.*)?$#', '/' . $workerScript, $workerUrl) ?: $workerUrl;
        }

        return rtrim($workerUrl, '/') . '/' . $workerScript;
    }
}
