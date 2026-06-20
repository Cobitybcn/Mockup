<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$artworkId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
if (!ProviderSettings::socialVideoVeoEnabled()) { http_response_code(409); exit('Vertex/Veo is disabled in API Settings.'); }
$pdo = Database::connection();
$workflowStmt = $pdo->prepare('SELECT w.*, a.root_file FROM social_video_workflows w INNER JOIN artworks a ON a.id=w.artwork_id WHERE w.artwork_id=:id AND a.user_id=:user_id LIMIT 1');
$workflowStmt->execute(['id' => $artworkId, 'user_id' => (int)$user['id']]);
$workflow = $workflowStmt->fetch();
if (!$workflow || empty($workflow['final_concept_json'])) { http_response_code(422); exit('Generate a Video Concept JSON first.'); }
$concept = json_decode((string)$workflow['final_concept_json'], true);
$segments = is_array($concept['segments'] ?? null) ? $concept['segments'] : [];
if ($segments === [] || count($segments) > 5) { http_response_code(422); exit('The Video Concept JSON must contain between 1 and 5 segments.'); }
$now = date('c');
$pdo->prepare('UPDATE social_video_workflows SET status=:status, video_status=:video_status, error=:error, updated_at=:updated_at WHERE id=:id')->execute(['status' => 'video_processing', 'video_status' => 'Video processing - segment 1 of 1', 'error' => '', 'updated_at' => $now, 'id' => (int)$workflow['id']]);
try {
    $rootFile = basename((string)($workflow['root_file'] ?? '')); $rootPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile;
    $result = (new VeoVideoClient())->generateSequence($segments, (array)($concept['social_video_specs'] ?? []), $rootPath, function (int $current, int $total, string $phase) use ($pdo, $workflow): void {
        $pdo->prepare('UPDATE social_video_workflows SET status=:status, video_status=:video_status, updated_at=:updated_at WHERE id=:id')->execute(['status' => 'video_processing', 'video_status' => 'Video processing - segment ' . $current . ' of ' . $total . ' (' . $phase . ')', 'updated_at' => date('c'), 'id' => (int)$workflow['id']]);
    });
    $pdo->prepare('UPDATE social_video_workflows SET status=:status, video_status=:video_status, video_url=:video_url, error=:error, updated_at=:updated_at WHERE id=:id')->execute(['status' => 'video_ready', 'video_status' => 'Video ready', 'video_url' => $result['file'], 'error' => '', 'updated_at' => date('c'), 'id' => (int)$workflow['id']]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: social_video.php?id=' . $artworkId . '&video=ready'); exit; }
    header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => true] + $result); exit;
} catch (Throwable $e) {
    $pdo->prepare('UPDATE social_video_workflows SET status=:status, video_status=:video_status, error=:error, updated_at=:updated_at WHERE id=:id')->execute(['status' => 'error', 'video_status' => 'Error - segment 1 of 1', 'error' => $e->getMessage(), 'updated_at' => date('c'), 'id' => (int)$workflow['id']]);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: social_video.php?id=' . $artworkId . '&video=error'); exit; }
    http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}
