<?php
declare(strict_types=1);

return [
    'description' => 'Version user sessions and persist authentication rate limits',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $column = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='session_version'");
            $column->execute();
            if ((int)$column->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status");
            }
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_rate_limits (
                action VARCHAR(64) NOT NULL,
                identity_hash CHAR(64) NOT NULL,
                window_started_at BIGINT UNSIGNED NOT NULL,
                attempts INT UNSIGNED NOT NULL,
                updated_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (action, identity_hash),
                KEY auth_rate_limits_updated_idx (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
                id VARCHAR(255) NOT NULL PRIMARY KEY,
                data MEDIUMTEXT NOT NULL,
                updated_at BIGINT NOT NULL,
                KEY php_sessions_updated_idx (updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $columns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('session_version', $columns, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_rate_limits (
            action TEXT NOT NULL,
            identity_hash TEXT NOT NULL,
            window_started_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL,
            updated_at INTEGER NOT NULL,
            PRIMARY KEY (action, identity_hash)
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS auth_rate_limits_updated_idx ON auth_rate_limits(updated_at)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS php_sessions (
            id TEXT NOT NULL PRIMARY KEY,
            data TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS php_sessions_updated_idx ON php_sessions(updated_at)');
    },
];
