<?php
// LEGACY / DO NOT USE IN PHASE 2.3 FLOW
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (!defined('LEGACY_MOCKUP_FLOW_ENABLED') || !LEGACY_MOCKUP_FLOW_ENABLED) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Legacy mockup flow disabled. Use Phase 2 reviewed mockup generation.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$currentUser = Auth::user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Session expired.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$image = basename(trim((string)($_POST['image'] ?? $_GET['image'] ?? '')));
$contextIds = $_POST['context_ids'] ?? $_GET['context_ids'] ?? [];

if (is_string($contextIds)) {
    $contextIds = array_filter(array_map('trim', explode(',', $contextIds)));
}

if (!is_array($contextIds)) {
    $contextIds = [];
}

$maxBatchSize = ProviderSettings::mockupWorkerCount();
$contextIds = array_slice(array_values(array_unique(array_filter(array_map(
    fn($id): string => trim((string)$id),
    $contextIds
)))), 0, $maxBatchSize);

if ($image === '' || $contextIds === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing artwork image or context ids.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = Database::connection();
    $stmtArtwork = $pdo->prepare('SELECT * FROM artworks WHERE root_file = :root_file AND user_id = :user_id LIMIT 1');
    $stmtArtwork->execute([
        'root_file' => $image,
        'user_id' => (int)$currentUser['id'],
    ]);
    $artwork = $stmtArtwork->fetch();

    if (!$artwork) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Artwork not found.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $jobIds = MockupBatchQueue::enqueueAndClaimContexts(
        (int)$artwork['id'],
        (int)$currentUser['id'],
        $image,
        $contextIds
    );

    foreach ($jobIds as $jobId) {
        start_mockup_batch_worker_for_job((int)$jobId);
    }

    echo json_encode([
        'ok' => true,
        'started' => count($jobIds),
        'job_ids' => $jobIds,
        'context_ids' => $contextIds,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    Logger::log('Error starting mockup batch: ' . $e->getMessage(), 'error');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not start this mockup batch.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function start_mockup_batch_worker_for_job(int $jobId): void
{
    $script = __DIR__ . DIRECTORY_SEPARATOR . 'process_mockup_queue.php';
    $php = resolve_mockup_batch_php_binary();

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' 0 1 ' . (int)$jobId . ' > NUL 2>&1';
        @pclose(@popen($cmd, 'r'));
        return;
    }

    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' 0 1 ' . (int)$jobId . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function resolve_mockup_batch_php_binary(): string
{
    if (defined('PHP_BINARY_PATH') && trim((string)PHP_BINARY_PATH) !== '' && is_file((string)PHP_BINARY_PATH)) {
        return (string)PHP_BINARY_PATH;
    }

    if (is_file(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') !== false) {
        return PHP_BINARY;
    }

    $laragonMatches = glob('C:\\laragon\\bin\\php\\*\\php.exe') ?: [];
    if (!empty($laragonMatches) && is_file((string)$laragonMatches[0])) {
        return (string)$laragonMatches[0];
    }

    return 'php';
}
