<?php
declare(strict_types=1);

final class MockupGenerationDispatcher
{
    public function dispatch(int $jobId, int $userId, int $artworkId, string $contextId, string $generationProvider): string
    {
        Database::updateJobStatus($jobId, 'queued');

        if (CloudTasksService::isAvailable()) {
            CloudTasksService::enqueueGeneration($jobId, $userId, $artworkId, $contextId, $generationProvider);
            return 'cloud_tasks';
        }

        $this->startLocalPool();
        return 'local_worker';
    }

    private function startLocalPool(): void
    {
        $script = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'mockup_queue_worker.php';
        if (!is_file($script)) {
            throw new RuntimeException('Mockup background worker was not found.');
        }

        $php = $this->phpBinary();
        $workerCount = max(1, min(8, ProviderSettings::mockupWorkerCount()));
        for ($slot = 1; $slot <= $workerCount; $slot++) {
            if (PHP_OS_FAMILY === 'Windows') {
                $command = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
                    . ' ' . $slot . ' > NUL 2>&1';
                $handle = @popen($command, 'r');
                if (is_resource($handle)) {
                    @pclose($handle);
                }
                continue;
            }

            $command = escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' ' . $slot . ' > /dev/null 2>&1 &';
            @exec($command);
        }
    }

    private function phpBinary(): string
    {
        if (defined('PHP_BINARY_PATH') && is_file((string)PHP_BINARY_PATH)) {
            return (string)PHP_BINARY_PATH;
        }

        if (is_file(PHP_BINARY) && strtolower(basename(PHP_BINARY)) === 'php.exe') {
            return PHP_BINARY;
        }

        $bindirBinary = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (is_file($bindirBinary)) {
            return $bindirBinary;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $matches = glob('C:\\laragon\\bin\\php\\*\\php.exe') ?: [];
            rsort($matches, SORT_NATURAL);
            if ($matches && is_file((string)$matches[0])) {
                return (string)$matches[0];
            }
        }

        return 'php';
    }
}
