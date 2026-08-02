<?php
declare(strict_types=1);

/**
 * video_projects is normally created by VideoStudioSchema, which only runs when
 * a Video Lab page boots. Schema migrations run earlier than that, so on a fresh
 * database the migration that adds the music columns had nothing to alter and
 * brought the whole request down.
 *
 * This runs before it and creates the table only when it is missing, so an
 * existing installation is untouched and a new one has something to alter.
 * Foreign keys are left to VideoStudioSchema: the tables they point at may not
 * exist yet at this point in the order.
 */
return [
    'description' => 'Make sure video_projects exists before the music columns are added',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS video_projects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL,
                global_prompt MEDIUMTEXT NOT NULL,
                artwork_id INT UNSIGNED NULL,
                series_id INT UNSIGNED NULL,
                aspect_ratio VARCHAR(12) NOT NULL DEFAULT '9:16',
                target_duration_seconds DECIMAL(8,2) NOT NULL DEFAULT 30.00,
                project_type VARCHAR(40) NOT NULL DEFAULT 'custom',
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                master_volume DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                KEY idx_video_projects_user_updated(user_id,updated_at),
                KEY idx_video_projects_user_status(user_id,status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS video_projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            global_prompt TEXT NOT NULL DEFAULT '',
            artwork_id INTEGER,
            series_id INTEGER,
            aspect_ratio TEXT NOT NULL DEFAULT '9:16',
            target_duration_seconds REAL NOT NULL DEFAULT 30,
            project_type TEXT NOT NULL DEFAULT 'custom',
            status TEXT NOT NULL DEFAULT 'draft',
            master_volume REAL NOT NULL DEFAULT 1,
            version INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
    },
];
