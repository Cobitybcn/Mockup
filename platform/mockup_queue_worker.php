<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('max_execution_time', '0');

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/app/bootstrap.php';

$slot = max(1, min(8, (int)($argv[1] ?? 1)));
$lockDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'background-locks';
if (!is_dir($lockDir)) {
    if (!mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
        error_log('Mockup queue worker could not create its lock directory: ' . $lockDir);
        exit(1);
    }
}
$lockPath = $lockDir . DIRECTORY_SEPARATOR . 'mockup-generation-' . $slot . '.lock';
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock)) {
    error_log('Mockup queue worker could not open its lock file: ' . $lockPath);
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    exit(0);
}

try {
    $worker = new MockupGenerationWorker();
    $idleRounds = 0;
    while ($idleRounds < 8) {
        $stmt = Database::connection()->prepare('
            SELECT id
            FROM mockup_generation_jobs
            WHERE status = "queued"
            AND selector_state_json LIKE :generation_source
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([
            'generation_source' => '%"generation_source":"mockup_combination_review"%',
        ]);
        $jobId = (int)($stmt->fetchColumn() ?: 0);
        if ($jobId <= 0) {
            $idleRounds++;
            usleep(400000);
            continue;
        }

        $idleRounds = 0;
        $worker->process($jobId);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
