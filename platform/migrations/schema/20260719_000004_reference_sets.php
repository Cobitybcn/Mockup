<?php
declare(strict_types=1);

return [
    'description' => 'Create reusable visual Reference Sets and link them to artworks and groups',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reference_sets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(160) NOT NULL,
                description TEXT NOT NULL,
                thumbnail LONGTEXT NOT NULL,
                identifier_color VARCHAR(24) NOT NULL DEFAULT 'rose',
                categories_json TEXT NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY reference_sets_user_created_idx (user_id, created_at),
                KEY reference_sets_user_name_idx (user_id, name),
                CONSTRAINT reference_sets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $pdo->exec("CREATE TABLE IF NOT EXISTS reference_set_items (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_set_id INT UNSIGNED NOT NULL,
                reference_key VARCHAR(160) NOT NULL,
                title VARCHAR(255) NOT NULL,
                category VARCHAR(120) NOT NULL,
                thumbnail LONGTEXT NOT NULL,
                position INT UNSIGNED NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY reference_set_items_position_unique (reference_set_id, position),
                KEY reference_set_items_reference_idx (reference_key),
                CONSTRAINT reference_set_items_set_fk FOREIGN KEY (reference_set_id) REFERENCES reference_sets(id) ON DELETE CASCADE
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

            foreach (['artworks', 'artwork_groups'] as $table) {
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS reference_sets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            thumbnail TEXT NOT NULL DEFAULT '',
            identifier_color TEXT NOT NULL DEFAULT 'rose',
            categories_json TEXT NOT NULL DEFAULT '[]',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS reference_sets_user_created_idx ON reference_sets(user_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS reference_sets_user_name_idx ON reference_sets(user_id, name)');

        $pdo->exec("CREATE TABLE IF NOT EXISTS reference_set_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference_set_id INTEGER NOT NULL,
            reference_key TEXT NOT NULL,
            title TEXT NOT NULL,
            category TEXT NOT NULL,
            thumbnail TEXT NOT NULL DEFAULT '',
            position INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(reference_set_id) REFERENCES reference_sets(id) ON DELETE CASCADE,
            UNIQUE(reference_set_id, position)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS reference_set_items_reference_idx ON reference_set_items(reference_key)');

        foreach (['artworks', 'artwork_groups'] as $table) {
            $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
            $tableStmt->execute(['table_name' => $table]);
            if ((int)$tableStmt->fetchColumn() === 0) {
                continue;
            }
            $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('reference_set_id', $columns, true)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN reference_set_id INTEGER NULL");
            }
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$table}_reference_set_idx ON {$table}(reference_set_id)");
        }
    },
];

