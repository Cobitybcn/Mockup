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
    @mkdir($lockDir, 0775, true);
}
$lock = @fopen($lockDir . DIRECTORY_SEPARATOR . 'mockup-generation-' . $slot . '.lock', 'c+');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

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
    $stmt->execute(['generation_source' => '%"generation_source":"mockup_combination_review"%']);
    $jobId = (int)($stmt->fetchColumn() ?: 0);
    if ($jobId <= 0) {
        $idleRounds++;
        usleep(400000);
        continue;
    }

    $idleRounds = 0;
    $worker->process($jobId);
}

@flock($lock, LOCK_UN);
@fclose($lock);
