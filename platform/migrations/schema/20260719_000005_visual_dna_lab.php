<?php
declare(strict_types=1);

return [
    'description' => 'Store real Visual DNA reference assets and trace isolated LAB mockup generations',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reference_assets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(120) NOT NULL,
                storage_path VARCHAR(600) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY reference_assets_user_path_unique (user_id, storage_path),
                KEY reference_assets_user_created_idx (user_id, created_at),
                CONSTRAINT reference_assets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $tableExists = static function (string $table) use ($pdo): bool {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name");
                $stmt->execute(['table_name' => $table]);
                return (int)$stmt->fetchColumn() > 0;
            };
            $columnExists = static function (string $table, string $column) use ($pdo): bool {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name");
                $stmt->execute(['table_name' => $table, 'column_name' => $column]);
                return (int)$stmt->fetchColumn() > 0;
            };
            $indexExists = static function (string $table, string $index) use ($pdo): bool {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND INDEX_NAME=:index_name");
                $stmt->execute(['table_name' => $table, 'index_name' => $index]);
                return (int)$stmt->fetchColumn() > 0;
            };

            if ($tableExists('reference_set_items')) {
                if (!$columnExists('reference_set_items', 'reference_asset_id')) {
                    $pdo->exec('ALTER TABLE reference_set_items ADD COLUMN reference_asset_id INT UNSIGNED NULL');
                }
                if (!$indexExists('reference_set_items', 'reference_set_items_asset_idx')) {
                    $pdo->exec('ALTER TABLE reference_set_items ADD INDEX reference_set_items_asset_idx (reference_asset_id)');
                }
            }

            foreach (['mockup_generation_jobs', 'mockups'] as $table) {
                if (!$tableExists($table)) {
                    continue;
                }
                if (!$columnExists($table, 'reference_set_id')) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN reference_set_id INT UNSIGNED NULL");
                }
                $index = $table . '_reference_set_idx';
                if (!$indexExists($table, $index)) {
                    $pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} (reference_set_id)");
                }
            }
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS reference_assets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            storage_path TEXT NOT NULL,
            original_name TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, storage_path)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS reference_assets_user_created_idx ON reference_assets(user_id, created_at)');

        foreach (['reference_set_items', 'mockup_generation_jobs', 'mockups'] as $table) {
            $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
            $tableStmt->execute(['table_name' => $table]);
            if ((int)$tableStmt->fetchColumn() === 0) {
                continue;
            }
            $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $column = $table === 'reference_set_items' ? 'reference_asset_id' : 'reference_set_id';
            if (!in_array($column, $columns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} INTEGER NULL");
            }
            $index = $table === 'reference_set_items'
                ? 'reference_set_items_asset_idx'
                : $table . '_reference_set_idx';
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}({$column})");
        }
    },
];
