<?php
declare(strict_types=1);

return [
    'description' => 'Store versioned visual-language studies and their real series reference images',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS series_visual_language_runs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                series_id INT UNSIGNED NOT NULL,
                schema_version VARCHAR(80) NOT NULL,
                status VARCHAR(40) NOT NULL DEFAULT 'draft',
                output_json LONGTEXT NULL,
                last_error TEXT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                completed_at VARCHAR(40) NULL,
                PRIMARY KEY (id),
                KEY series_visual_language_user_series_idx (user_id, series_id, id),
                KEY series_visual_language_status_idx (user_id, status),
                CONSTRAINT series_visual_language_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS series_visual_language_images (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                series_id INT UNSIGNED NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                relative_path VARCHAR(600) NOT NULL,
                image_role VARCHAR(30) NOT NULL DEFAULT 'general',
                storage_path VARCHAR(700) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width_px INT UNSIGNED NOT NULL DEFAULT 0,
                height_px INT UNSIGNED NOT NULL DEFAULT 0,
                position INT UNSIGNED NOT NULL DEFAULT 0,
                palette_json LONGTEXT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY series_visual_language_images_run_idx (run_id, position, id),
                KEY series_visual_language_images_owner_idx (user_id, series_id, id),
                CONSTRAINT series_visual_language_images_run_fk FOREIGN KEY (run_id) REFERENCES series_visual_language_runs(id) ON DELETE CASCADE,
                CONSTRAINT series_visual_language_images_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS series_visual_language_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            series_id INTEGER NOT NULL,
            schema_version TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'draft',
            output_json TEXT NULL,
            last_error TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_user_series_idx ON series_visual_language_runs(user_id, series_id, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_status_idx ON series_visual_language_runs(user_id, status)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS series_visual_language_images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            series_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            relative_path TEXT NOT NULL,
            image_role TEXT NOT NULL DEFAULT 'general',
            storage_path TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            byte_size INTEGER NOT NULL DEFAULT 0,
            width_px INTEGER NOT NULL DEFAULT 0,
            height_px INTEGER NOT NULL DEFAULT 0,
            position INTEGER NOT NULL DEFAULT 0,
            palette_json TEXT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(run_id) REFERENCES series_visual_language_runs(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_images_run_idx ON series_visual_language_images(run_id, position, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_images_owner_idx ON series_visual_language_images(user_id, series_id, id)');
    },
];
