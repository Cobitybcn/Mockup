<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$job = basename((string)($_GET['job'] ?? ''));

header('Content-Type: application/json; charset=utf-8');

function scene_flow_user_message(string $error): string
{
    $lower = strtolower($error);
    if (str_contains($lower, 'capacity') || str_contains($lower, 'resource_exhausted') || str_contains($lower, '429') || str_contains($lower, 'quota')) {
        return 'Image generation is temporarily busy. Please try again in a few minutes.';
    }
    if (str_contains($lower, 'too long') || str_contains($lower, 'timeout') || str_contains($lower, 'timed out')) {
        return 'Artwork preparation took too long. Please try again with a clearer or smaller photo.';
    }
    if (str_contains($lower, 'clearer') || str_contains($lower, 'photo') || str_contains($lower, 'base image')) {
        return 'Please try again with a clearer, well-lit photo.';
    }
    return 'Artwork preparation could not be completed. Please try again.';
}

function scene_flow_error_code(string $error): string
{
    $lower = strtolower($error);
    if (str_contains($lower, 'cloud task') || str_contains($lower, 'enqueue') || str_contains($lower, 'queue')) return 'TASK_ENQUEUE_FAILED';
    if (str_contains($lower, 'permission') || str_contains($lower, 'forbidden') || str_contains($lower, '403') || str_contains($lower, 'unauthenticated')) return 'CLOUD_PERMISSION_DENIED';
    if (str_contains($lower, 'storage') || str_contains($lower, 'bucket') || str_contains($lower, 'object')) return 'STORAGE_ACCESS_FAILED';
    if (str_contains($lower, 'capacity') || str_contains($lower, 'resource_exhausted') || str_contains($lower, '429') || str_contains($lower, 'quota')) return 'AI_CAPACITY_LIMIT';
    if (str_contains($lower, 'timeout') || str_contains($lower, 'timed out') || str_contains($lower, 'too long')) return 'ARTWORK_PREPARATION_TIMEOUT';
    if (str_contains($lower, 'vertex') || str_contains($lower, 'gemini') || str_contains($lower, 'model')) return 'AI_PREPARATION_FAILED';
    if (str_contains($lower, 'image') || str_contains($lower, 'photo') || str_contains($lower, 'base image')) return 'ARTWORK_IMAGE_INVALID';
    return 'ARTWORK_PREPARATION_FAILED';
}

function scene_flow_safe_error_detail(string $error): string
{
    $error = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [redacted]', $error) ?? $error;
    $error = preg_replace('/AIza[A-Za-z0-9_-]{20,}/', '[redacted-api-key]', $error) ?? $error;
    $error = preg_replace('/([?&](?:token|key|signature|credential)=)[^&\s]+/i', '$1[redacted]', $error) ?? $error;
    return mb_substr(trim($error), 0, 700);
}

if ($job === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta job.']);
    exit;
}

$statusFile = __DIR__ . '/jobs/' . $job . '/status.json';
$jobDir = dirname($statusFile);
$remoteStatus = null;

if (!is_dir($jobDir)) {
    mkdir($jobDir, 0775, true);
}

// Web and worker run in different Cloud Run containers. The copy created by
// the web service remains "queued", while the worker publishes progress to
// shared storage. Refresh it on every poll so the UI never reads a stale local
// status file.
if (StorageService::isGcsActive()) {
    $remoteStatusFile = tempnam($jobDir, 'status_remote_');
    if (is_string($remoteStatusFile)) {
        if (StorageService::downloadFile('jobs/' . $job . '/status.json', $remoteStatusFile) && is_file($remoteStatusFile)) {
            $remoteStatus = json_decode((string)file_get_contents($remoteStatusFile), true);
            if (is_array($remoteStatus)) {
                // Keep a best-effort local cache, but serve this request from
                // the freshly downloaded GCS value. Cloud Run instances have
                // isolated filesystems, so the local queued copy is not an
                // authoritative job status.
                @rename($remoteStatusFile, $statusFile);
                if (is_file($remoteStatusFile)) {
                    @copy($remoteStatusFile, $statusFile);
                }
            }
        }
        if (is_file($remoteStatusFile)) {
            @unlink($remoteStatusFile);
        }
    }
}

if (!is_file($statusFile)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Trabajo no encontrado.']);
    exit;
}

$status = is_array($remoteStatus)
    ? $remoteStatus
    : json_decode((string)file_get_contents($statusFile), true);

if (!is_array($status)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo leer el estado.']);
    exit;
}

if ((int)($status['user_id'] ?? 0) !== (int)$user['id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No tienes acceso a este trabajo.']);
    exit;
}

if (!empty($status['user_scene_flow']) && (string)($status['status'] ?? '') === 'done' && !empty($status['result_file'])) {
    try {
        $stmtArtwork = Database::connection()->prepare('SELECT id FROM artworks WHERE job_id = :job_id AND user_id = :user_id LIMIT 1');
        $stmtArtwork->execute([
            'job_id' => $job,
            'user_id' => (int)$user['id'],
        ]);
        $artworkId = (int)$stmtArtwork->fetchColumn();
        if ($artworkId > 0) {
            $sceneCategory = trim(str_replace(['\\', '/'], '', (string)($status['scene_category'] ?? 'selected')));
            $sceneBoard = max(1, min(3, (int)($status['scene_board'] ?? 1)));
            $sceneLimit = max(1, min(4, (int)($status['scene_limit'] ?? 4)));
            $status['scene_redirect'] = 'mockup_combinations_review.php?id=' . $artworkId
                . '&board=' . $sceneBoard
                . '&world_mother_category=' . rawurlencode($sceneCategory)
                . '&auto_generate=1&compact=1&scene_limit=' . $sceneLimit;
        }
    } catch (Throwable $e) {
        $status['scene_redirect_error'] = $e->getMessage();
    }
}

if (!empty($status['user_scene_flow']) && (string)($status['status'] ?? '') === 'error') {
    $technicalError = (string)($status['error'] ?? '');
    $status['error_code'] = scene_flow_error_code($technicalError);
    $status['debug_error'] = scene_flow_safe_error_detail($technicalError);
    $status['user_message'] = scene_flow_user_message($technicalError);
    unset($status['error']);
}

echo json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
