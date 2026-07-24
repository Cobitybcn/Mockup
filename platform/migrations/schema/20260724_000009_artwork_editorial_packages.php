<?php
declare(strict_types=1);

return [
    'description' => 'Coordinate artwork editorial packages across series, artwork and mockups',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_editorial_packages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                artwork_id INT UNSIGNED NOT NULL,
                series_id BIGINT UNSIGNED NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'queued',
                current_stage INT UNSIGNED NOT NULL DEFAULT 0,
                scope_json LONGTEXT NOT NULL,
                error LONGTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                completed_at VARCHAR(40) NULL,
                PRIMARY KEY (id),
                KEY ix_artwork_editorial_packages_artwork (user_id,artwork_id,status),
                CONSTRAINT fk_artwork_editorial_packages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_artwork_editorial_packages_artwork FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_editorial_package_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                package_id BIGINT UNSIGNED NOT NULL,
                entity_type VARCHAR(24) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                stage_order INT UNSIGNED NOT NULL,
                action VARCHAR(24) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                editorial_job_id BIGINT UNSIGNED NULL,
                error LONGTEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_artwork_editorial_package_item (package_id,entity_type,entity_id),
                KEY ix_artwork_editorial_package_items_stage (package_id,stage_order,status),
                KEY ix_artwork_editorial_package_items_job (editorial_job_id),
                CONSTRAINT fk_artwork_editorial_package_items_package FOREIGN KEY (package_id) REFERENCES artwork_editorial_packages(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_editorial_packages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            artwork_id INTEGER NOT NULL,
            series_id INTEGER,
            status TEXT NOT NULL DEFAULT 'queued',
            current_stage INTEGER NOT NULL DEFAULT 0,
            scope_json TEXT NOT NULL DEFAULT '{}',
            error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            completed_at TEXT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (artwork_id) REFERENCES artworks(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_artwork_editorial_packages_artwork ON artwork_editorial_packages (user_id,artwork_id,status)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_editorial_package_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            stage_order INTEGER NOT NULL,
            action TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            editorial_job_id INTEGER,
            error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (package_id,entity_type,entity_id),
            FOREIGN KEY (package_id) REFERENCES artwork_editorial_packages(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_artwork_editorial_package_items_stage ON artwork_editorial_package_items (package_id,stage_order,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_artwork_editorial_package_items_job ON artwork_editorial_package_items (editorial_job_id)');
    },
];
