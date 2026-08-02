<?php
declare(strict_types=1);

/**
 * X's connection, shaped like the ones already in place for Instagram and
 * Facebook: one row per user and purpose, tokens kept encrypted, and the state
 * of the link readable without decrypting anything.
 *
 * X issues short-lived access tokens, so the refresh token is not optional the
 * way it is on Meta: without it the artist would be reconnecting several times
 * a day.
 */
return [
    'description' => 'Keep the artist X connection beside the other networks',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS x_connections (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(20) NOT NULL DEFAULT 'artist',
                x_user_id VARCHAR(190) NOT NULL DEFAULT '',
                x_username VARCHAR(255) NOT NULL DEFAULT '',
                x_display_name VARCHAR(255) NOT NULL DEFAULT '',
                access_token_encrypted MEDIUMTEXT NULL,
                refresh_token_encrypted MEDIUMTEXT NULL,
                token_expires_at VARCHAR(40) NULL,
                scopes TEXT NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                connected_at VARCHAR(40) NULL,
                disconnected_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY uq_x_connections_user_purpose (user_id,purpose)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS x_connections (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            purpose TEXT NOT NULL DEFAULT 'artist',
            x_user_id TEXT NOT NULL DEFAULT '',
            x_username TEXT NOT NULL DEFAULT '',
            x_display_name TEXT NOT NULL DEFAULT '',
            access_token_encrypted TEXT,
            refresh_token_encrypted TEXT,
            token_expires_at TEXT,
            scopes TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'pending',
            connected_at TEXT,
            disconnected_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(user_id,purpose)
        )");
    },
];
