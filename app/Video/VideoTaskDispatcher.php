<?php
declare(strict_types=1);

use Google\Cloud\Tasks\V2\CloudTasksClient;
use Google\Cloud\Tasks\V2\HttpMethod;
use Google\Cloud\Tasks\V2\HttpRequest;
use Google\Cloud\Tasks\V2\OidcToken;
use Google\Cloud\Tasks\V2\Task;
use Google\Protobuf\Timestamp;

final class VideoTaskDispatcher
{
    public function dispatchGeneration(int $jobId, int $delaySeconds = 0): string
    {
        return $this->dispatch('video_worker.php', ['job_id' => $jobId], $jobId, $delaySeconds);
    }

    public function dispatchExport(int $exportId, int $delaySeconds = 0): string
    {
        return $this->dispatch('video_export_worker.php', ['export_id' => $exportId], $exportId, $delaySeconds);
    }

    private function dispatch(string $script, array $payload, int $id, int $delaySeconds): string
    {
        $delaySeconds = max(0, min(3600, $delaySeconds));
        if ($this->cloudTasksConfigured()) return $this->cloudTask($script, $payload, $delaySeconds);
        if (self::truthy(app_env('VIDEO_DISABLE_BACKGROUND_JOBS', 'false'))) return 'manual:' . $script . ':' . $id;
        return $this->localProcess($script, $id, $delaySeconds);
    }

    private function cloudTasksConfigured(): bool
    {
        return class_exists(CloudTasksClient::class)
            && app_env('GCP_PROJECT_ID', '') !== ''
            && app_env('GCP_WORKER_URL', '') !== ''
            && app_env('GCP_TASKS_INVOKER_SA', '') !== '';
    }

    private function cloudTask(string $script, array $payload, int $delaySeconds): string
    {
        $project = app_env('GCP_PROJECT_ID', '');
        $location = app_env('GCP_LOCATION', 'us-central1');
        $queue = trim(app_env('GCP_VIDEO_QUEUE_NAME', ''));
        if ($queue === '') $queue = app_env('GCP_QUEUE_NAME', 'mockups-generation-queue');
        $base = app_env('GCP_WORKER_URL', '');
        $serviceAccount = app_env('GCP_TASKS_INVOKER_SA', '');
        $workerToken = app_env('VIDEO_WORKER_TOKEN', '');
        if ($workerToken === '') throw new RuntimeException('VIDEO_WORKER_TOKEN is required for remote video workers.');
        $url = preg_match('#/[^/]+\.php(?:\?.*)?$#', $base)
            ? (preg_replace('#/[^/]+\.php(?:\?.*)?$#', '/' . $script, $base) ?: $base)
            : rtrim($base, '/') . '/' . $script;

        $client = new CloudTasksClient();
        $request = (new HttpRequest())
            ->setUrl($url)
            ->setHttpMethod(HttpMethod::POST)
            ->setHeaders(['Content-Type' => 'application/json', 'X-Video-Worker-Token' => $workerToken])
            ->setBody(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $request->setOidcToken((new OidcToken())->setServiceAccountEmail($serviceAccount)->setAudience($url));
        $task = (new Task())->setHttpRequest($request);
        if ($delaySeconds > 0) {
            $timestamp = new Timestamp();
            $timestamp->setSeconds(time() + $delaySeconds);
            $task->setScheduleTime($timestamp);
        }
        $created = $client->createTask($client->queueName($project, $location, $queue), $task);
        return $created->getName();
    }

    private function localProcess(string $script, int $id, int $delaySeconds): string
    {
        $php = trim(app_env('PHP_BINARY_PATH', ''));
        if ($php === '') $php = PHP_BINARY ?: 'php';
        if (stripos($php, 'apache') !== false || stripos($php, 'httpd') !== false || stripos($php, 'php-cgi') !== false) $php = 'php';
        $scriptPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . $script;
        if (!is_file($scriptPath)) throw new RuntimeException('Video worker script is missing.');

        if (DIRECTORY_SEPARATOR === '\\') {
            $safePhp = str_replace('"', '', $php);
            $safeScript = str_replace('"', '', $scriptPath);
            $command = sprintf('start /B "" "%s" "%s" %d --delay=%d > NUL 2>&1', $safePhp, $safeScript, $id, $delaySeconds);
            $handle = @popen($command, 'r');
            if ($handle === false) throw new RuntimeException('Could not start the local video worker.');
            pclose($handle);
        } else {
            $command = sprintf('nohup %s %s %d --delay=%d >/dev/null 2>&1 &', escapeshellarg($php), escapeshellarg($scriptPath), $id, $delaySeconds);
            @exec($command);
        }
        return 'local:' . $script . ':' . $id . ':' . bin2hex(random_bytes(4));
    }

    public static function authorizeWorkerRequest(): void
    {
        if (PHP_SAPI === 'cli') return;
        $expected = app_env('VIDEO_WORKER_TOKEN', '');
        $received = trim((string)($_SERVER['HTTP_X_VIDEO_WORKER_TOKEN'] ?? ''));
        if ($expected === '' || !hash_equals($expected, $received)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Worker authorization failed.']);
            exit;
        }
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1','true','yes','on'], true);
    }
}
