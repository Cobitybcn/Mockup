<?php
// app/Services/CloudTasksService.php
declare(strict_types=1);

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\Task;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Protobuf\Timestamp;
use Google\ApiCore\ApiException;

class CloudTasksService
{
    public static function isAvailable(): bool
    {
        return class_exists(CloudTasksClient::class)
            && app_env('GCP_PROJECT_ID', '') !== ''
            && app_env('GCP_WORKER_URL', '') !== ''
            && app_env('GCP_TASKS_INVOKER_SA', '') !== '';
    }

    public static function enqueueGeneration(int $jobId, int $userId, int $artworkId, string $contextId, string $generationProvider = ''): string
    {
        return self::enqueue('worker.php', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'artwork_id' => $artworkId,
            'context_id' => $contextId,
            'generation_provider' => ServiceFactory::generationProvider($generationProvider),
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

    public static function enqueueEditorialGeneration(int $jobId): string
    {
        $editorialWorkerUrl = trim(app_env('GCP_EDITORIAL_WORKER_URL', ''));
        if ($editorialWorkerUrl !== '') {
            $editorialWorkerToken = trim(app_env('EDITORIAL_WORKER_TOKEN', ''));
            if ($editorialWorkerToken === '') {
                throw new RuntimeException('EDITORIAL_WORKER_TOKEN is required for the public editorial worker.');
            }

            return self::enqueue('editorial_worker.php', [
                'job_id' => $jobId,
                'timestamp' => date('c'),
            ], null, '', $editorialWorkerUrl, [
                'X-Editorial-Worker-Token' => $editorialWorkerToken,
            ], false);
        }

        return self::enqueue('editorial_worker.php', [
            'job_id' => $jobId,
            'timestamp' => date('c'),
        ]);
    }

    public static function deleteTask(string $taskName): void
    {
        $taskName = trim($taskName);
        if ($taskName === '') {
            return;
        }

        $client = new CloudTasksClient();
        try {
            // google/cloud-tasks 1.x uses the flattened task-name argument.
            $client->deleteTask($taskName);
        } catch (ApiException $e) {
            $status = method_exists($e, 'getStatus') ? strtoupper((string)$e->getStatus()) : '';
            if ((int)$e->getCode() === 5 || $status === 'NOT_FOUND') {
                return;
            }
            throw $e;
        } finally {
            if (method_exists($client, 'close')) {
                $client->close();
            }
        }
    }

    private static function enqueue(
        string $workerScript,
        array $payload,
        ?DateTimeImmutable $scheduledAt = null,
        string $queueOverride = '',
        string $workerUrlOverride = '',
        array $additionalHeaders = [],
        bool $withOidc = true
    ): string
    {
        $projectId = app_env('GCP_PROJECT_ID', '');
        $location = app_env('GCP_LOCATION', 'us-central1');
        $queue = $queueOverride !== '' ? $queueOverride : app_env('GCP_QUEUE_NAME', 'mockups-generation-queue');
        $workerUrl = $workerUrlOverride !== '' ? $workerUrlOverride : app_env('GCP_WORKER_URL', '');
        $invokerSa = app_env('GCP_TASKS_INVOKER_SA', '');

        if (!$projectId || !$workerUrl || ($withOidc && !$invokerSa)) {
            throw new RuntimeException('Configuración de GCP incompleta en .env (requiere GCP_PROJECT_ID, GCP_WORKER_URL, GCP_TASKS_INVOKER_SA).');
        }

        $targetUrl = self::targetUrl($workerUrl, $workerScript);

        $client = new CloudTasksClient();
        $queueName = $client->queueName($projectId, $location, $queue);

        $httpRequest = new HttpRequest();
        $httpRequest->setUrl($targetUrl);
        $httpRequest->setHttpMethod(HttpMethod::POST);
        $httpRequest->setHeaders(array_merge(
            ['Content-Type' => 'application/json'],
            $additionalHeaders
        ));
        $httpRequest->setBody(json_encode($payload));
        if ($withOidc) {
            $oidcAudience = self::oidcAudience($workerUrl);
            $oidcToken = new OidcToken();
            $oidcToken->setServiceAccountEmail($invokerSa);
            // Cloud Run validates the service origin as the OIDC audience.
            $oidcToken->setAudience($oidcAudience);
            $httpRequest->setOidcToken($oidcToken);
        }

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

    private static function oidcAudience(string $workerUrl): string
    {
        $parts = parse_url(trim($workerUrl));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return rtrim(trim($workerUrl), '/');
        }

        $audience = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'];
        if (isset($parts['port'])) {
            $audience .= ':' . (int)$parts['port'];
        }
        return $audience;
    }
}
