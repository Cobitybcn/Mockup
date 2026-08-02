<?php
declare(strict_types=1);

return [
    'description' => 'Persist the duration and audio presence of imported video assets for multitrack editing',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $table = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_reference_assets'");
            $table->execute();
            if ((int)$table->fetchColumn() === 0) return;
            $columns = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_reference_assets'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('duration_seconds', $columns, true)) {
                $pdo->exec('ALTER TABLE video_reference_assets ADD COLUMN duration_seconds DECIMAL(9,3) NULL AFTER height');
            }
            if (!in_array('has_audio', $columns, true)) {
                $pdo->exec('ALTER TABLE video_reference_assets ADD COLUMN has_audio TINYINT(1) NOT NULL DEFAULT 0 AFTER duration_seconds');
            }
            return;
        }

        $table = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='video_reference_assets'");
        $table->execute();
        if ((int)$table->fetchColumn() === 0) return;
        $columns = array_column($pdo->query('PRAGMA table_info(video_reference_assets)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('duration_seconds', $columns, true)) $pdo->exec('ALTER TABLE video_reference_assets ADD COLUMN duration_seconds REAL');
        if (!in_array('has_audio', $columns, true)) $pdo->exec('ALTER TABLE video_reference_assets ADD COLUMN has_audio INTEGER NOT NULL DEFAULT 0');
    },
];
