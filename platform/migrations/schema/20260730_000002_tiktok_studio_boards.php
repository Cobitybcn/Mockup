<?php
declare(strict_types=1);

return [
    'description' => 'TikTok Studio: date boards, board membership, and scheduled-publish metadata on video_tiktok_publications',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_boards (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                publish_date DATE NOT NULL,
                title VARCHAR(255) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_tiktok_boards_user_date (user_id,publish_date),
                CONSTRAINT tiktok_boards_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_board_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                board_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                video_export_id BIGINT UNSIGNED NOT NULL,
                position INT NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_tiktok_board_items_video (user_id,video_export_id),
                KEY idx_tiktok_board_items_board (board_id),
                CONSTRAINT tiktok_board_items_board_fk FOREIGN KEY (board_id) REFERENCES tiktok_boards(id) ON DELETE CASCADE,
                CONSTRAINT tiktok_board_items_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_boards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                publish_date TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id,publish_date)
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_board_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                video_export_id INTEGER NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                FOREIGN KEY(board_id) REFERENCES tiktok_boards(id) ON DELETE CASCADE,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE(user_id,video_export_id)
            )");
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_tiktok_board_items_board ON tiktok_board_items(board_id)');
        }

        $tableExists = $mysql
            ? (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_tiktok_publications'")->fetchColumn() > 0
            : (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='video_tiktok_publications'")->fetchColumn() > 0;
        if (!$tableExists) return;

        $columns = [
            'caption' => $mysql ? 'MEDIUMTEXT NOT NULL' : "TEXT NOT NULL DEFAULT ''",
            'cover_timestamp_ms' => 'INT NOT NULL DEFAULT 0',
            'is_aigc' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'destination_url' => $mysql ? "VARCHAR(500) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
            'board_id' => 'INT NULL',
            'schedule_job_id' => 'INT NULL',
        ];
        foreach ($columns as $column => $definition) {
            if ($mysql) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_tiktok_publications' AND COLUMN_NAME=?");
                $stmt->execute([$column]);
                $hasColumn = (int)$stmt->fetchColumn() > 0;
            } else {
                $hasColumn = false;
                foreach ($pdo->query('PRAGMA table_info(video_tiktok_publications)') as $row) {
                    if ((string)$row['name'] === $column) { $hasColumn = true; break; }
                }
            }
            if (!$hasColumn) {
                $pdo->exec("ALTER TABLE video_tiktok_publications ADD COLUMN {$column} {$definition}");
            }
        }
    },
];
