<?php
declare(strict_types=1);

return [
    'description' => 'TikTok Studio: separate tags column on video_tiktok_publications',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $tableExists = $mysql
            ? (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_tiktok_publications'")->fetchColumn() > 0
            : (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='video_tiktok_publications'")->fetchColumn() > 0;
        if (!$tableExists) return;

        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_tiktok_publications' AND COLUMN_NAME='tags'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            $hasColumn = false;
            foreach ($pdo->query('PRAGMA table_info(video_tiktok_publications)') as $row) {
                if ((string)$row['name'] === 'tags') { $hasColumn = true; break; }
            }
        }
        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE video_tiktok_publications ADD COLUMN tags " . ($mysql ? 'MEDIUMTEXT NOT NULL' : "TEXT NOT NULL DEFAULT ''"));
        }
    },
];
