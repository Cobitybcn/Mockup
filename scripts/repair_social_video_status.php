<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$pdo = Database::connection();
if (Database::isMysql()) {
    $pdo->exec('ALTER TABLE social_video_workflows MODIFY video_status VARCHAR(255) NOT NULL');
    $pdo->exec('ALTER TABLE social_video_workflows MODIFY status VARCHAR(100) NOT NULL');
    $pdo->exec('ALTER TABLE social_video_jobs MODIFY status VARCHAR(100) NOT NULL');
}
echo "Social Video status columns repaired.\n";
