<?php
declare(strict_types=1);

return [
    'description' => 'Add opt-in bilingual editorial content for series, artworks, and mockups',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_settings (
                user_id INT UNSIGNED NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                source_locale VARCHAR(12) NOT NULL DEFAULT 'es',
                publication_locale VARCHAR(12) NOT NULL DEFAULT 'en',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (user_id),
                CONSTRAINT fk_bilingual_editorial_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_content (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(24) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                locale VARCHAR(12) NOT NULL,
                content_json LONGTEXT NOT NULL,
                private_memo LONGTEXT NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'draft',
                source_hash CHAR(64) NOT NULL DEFAULT '',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_bilingual_editorial_entity_locale (user_id, entity_type, entity_id, locale),
                KEY ix_bilingual_editorial_status (user_id, locale, status),
                CONSTRAINT fk_bilingual_editorial_content_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_settings (
            user_id INTEGER NOT NULL PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 0,
            source_locale TEXT NOT NULL DEFAULT 'es',
            publication_locale TEXT NOT NULL DEFAULT 'en',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_content (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            content_json TEXT NOT NULL DEFAULT '{}',
            private_memo TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'draft',
            source_hash TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (user_id, entity_type, entity_id, locale),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_bilingual_editorial_status ON bilingual_editorial_content (user_id, locale, status)');
    },
];
