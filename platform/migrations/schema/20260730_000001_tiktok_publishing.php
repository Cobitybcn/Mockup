<?php
declare(strict_types=1);

return [
    'description' => 'TikTok account connection and per-video publication tracking',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                tiktok_open_id VARCHAR(190) NOT NULL DEFAULT '',
                tiktok_username VARCHAR(255) NOT NULL DEFAULT '',
                tiktok_display_name VARCHAR(255) NOT NULL DEFAULT '',
                access_token_encrypted MEDIUMTEXT NULL,
                refresh_token_encrypted MEDIUMTEXT NULL,
                token_expires_at VARCHAR(40) NULL,
                refresh_expires_at VARCHAR(40) NULL,
                scopes TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                connected_at VARCHAR(40) NULL,
                disconnected_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_tiktok_connections_user_purpose (user_id,purpose),
                CONSTRAINT tiktok_connections_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS video_tiktok_publications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                video_export_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                privacy_level VARCHAR(30) NOT NULL DEFAULT '',
                tiktok_publish_id VARCHAR(190) NOT NULL DEFAULT '',
                tiktok_video_id VARCHAR(190) NOT NULL DEFAULT '',
                media_token VARCHAR(64) NOT NULL DEFAULT '',
                media_expires_at VARCHAR(40) NULL,
                error MEDIUMTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_video_tiktok_publications_export (user_id,video_export_id),
                KEY idx_video_tiktok_publications_status (user_id,status),
                CONSTRAINT video_tiktok_publications_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS tiktok_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            purpose TEXT NOT NULL DEFAULT 'artist',
            tiktok_open_id TEXT NOT NULL DEFAULT '',
            tiktok_username TEXT NOT NULL DEFAULT '',
            tiktok_display_name TEXT NOT NULL DEFAULT '',
            access_token_encrypted TEXT,
            refresh_token_encrypted TEXT,
            token_expires_at TEXT,
            refresh_expires_at TEXT,
            scopes TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            connected_at TEXT,
            disconnected_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id,purpose)
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_tiktok_publications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            video_export_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued',
            privacy_level TEXT NOT NULL DEFAULT '',
            tiktok_publish_id TEXT NOT NULL DEFAULT '',
            tiktok_video_id TEXT NOT NULL DEFAULT '',
            media_token TEXT NOT NULL DEFAULT '',
            media_expires_at TEXT,
            error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id,video_export_id)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_video_tiktok_publications_status ON video_tiktok_publications(user_id,status)');
    },
];
