<?php
declare(strict_types=1);

return [
    'description' => 'Version artist access control and add an immutable access audit trail',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $column = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='plan_code'");
            $column->execute();
            if ((int)$column->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN plan_code VARCHAR(40) NOT NULL DEFAULT 'artist_studio' AFTER credits");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_feature_overrides (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                feature_key VARCHAR(100) NOT NULL,
                allowed TINYINT(1) NOT NULL,
                expires_at VARCHAR(40) NULL,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY user_feature_overrides_user_feature_unique (user_id, feature_key),
                KEY user_feature_overrides_user_idx (user_id),
                CONSTRAINT user_feature_overrides_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_access_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                target_user_id INT UNSIGNED NOT NULL,
                actor_user_id INT UNSIGNED NULL,
                actor_context VARCHAR(80) NOT NULL DEFAULT 'system',
                before_json MEDIUMTEXT NOT NULL,
                after_json MEDIUMTEXT NOT NULL,
                note VARCHAR(255) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY user_access_audit_target_idx (target_user_id, created_at),
                KEY user_access_audit_actor_idx (actor_user_id, created_at),
                CONSTRAINT user_access_audit_target_fk FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT user_access_audit_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('plan_code', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN plan_code TEXT NOT NULL DEFAULT 'artist_studio'");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_feature_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            feature_key TEXT NOT NULL,
            allowed INTEGER NOT NULL,
            expires_at TEXT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, feature_key)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS user_feature_overrides_user_idx ON user_feature_overrides(user_id)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_access_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target_user_id INTEGER NOT NULL,
            actor_user_id INTEGER NULL,
            actor_context TEXT NOT NULL DEFAULT 'system',
            before_json TEXT NOT NULL,
            after_json TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            FOREIGN KEY(target_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS user_access_audit_target_idx ON user_access_audit(target_user_id, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS user_access_audit_actor_idx ON user_access_audit(actor_user_id, created_at)');
    },
];
