<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$id = (int)($argv[1] ?? 0);
$stmt = Database::connection()->prepare('SELECT artwork_id, status, video_status, video_url, error, updated_at FROM social_video_workflows WHERE artwork_id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
echo json_encode($stmt->fetch(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
