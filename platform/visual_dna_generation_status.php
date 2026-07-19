<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = Auth::requireUser();
    $jobId = max(0, (int)($_GET['job_id'] ?? 0));
    if ($jobId <= 0) {
        throw new InvalidArgumentException('A Visual DNA job is required.');
    }

    $stmt = Database::connection()->prepare('SELECT id, status, mockup_id, mockup_file, error, selector_state_json, created_at, updated_at
        FROM mockup_generation_jobs WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['id' => $jobId, 'user_id' => (int)$user['id']]);
    $job = $stmt->fetch();
    if (!is_array($job)) {
        http_response_code(404);
        throw new RuntimeException('Visual DNA generation was not found.');
    }
    $state = json_decode((string)($job['selector_state_json'] ?? ''), true);
    if (!is_array($state) || (string)($state['generation_source'] ?? '') !== 'visual_dna_lab') {
        http_response_code(404);
        throw new RuntimeException('Visual DNA generation was not found.');
    }

    $status = (string)$job['status'];
    $mockupFile = basename((string)($job['mockup_file'] ?? ''));
    echo json_encode([
        'ok' => true,
        'job_id' => (int)$job['id'],
        'status' => $status,
        'active' => in_array($status, ['pending_enqueue', 'queued', 'processing'], true),
        'reference_set_id' => (int)($state['reference_set_id'] ?? 0),
        'reference_set_name' => (string)($state['reference_set_name'] ?? ''),
        'mockup_id' => (int)($job['mockup_id'] ?? 0) ?: null,
        'result_url' => $status === 'done' && $mockupFile !== ''
            ? 'media.php?file=' . rawurlencode($mockupFile)
            : null,
        'error' => in_array($status, ['error', 'failed', 'failed_enqueue'], true)
            ? (string)($job['error'] ?? 'Generation failed.')
            : null,
        'created_at' => (string)$job['created_at'],
        'updated_at' => (string)$job['updated_at'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    if (http_response_code() < 400) {
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
