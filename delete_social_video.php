<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
$user = Auth::requireUser();
$artworkId = (int)($_POST['id'] ?? 0);
$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT id,video_url FROM social_video_workflows WHERE artwork_id=:artwork_id AND user_id=:user_id LIMIT 1');
$stmt->execute(['artwork_id'=>$artworkId,'user_id'=>(int)$user['id']]);
$workflow = $stmt->fetch();
if (!$workflow) { http_response_code(404); exit('Video workflow not found.'); }
$video = (string)($workflow['video_url'] ?? '');
if ($video !== '' && preg_match('#^social-video/[A-Za-z0-9._-]+\.mp4$#', $video) === 1) {
    $path = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $video);
    if (is_file($path) && !unlink($path)) { http_response_code(500); exit('No se pudo eliminar el archivo de video.'); }
}
$pdo->prepare('UPDATE social_video_workflows SET video_url="",video_status="not_started",status="concept_generated",error="",updated_at=:updated_at WHERE id=:id')->execute(['updated_at'=>date('c'),'id'=>(int)$workflow['id']]);
header('Location: social_video.php?id=' . $artworkId . '&video=deleted');
exit;
