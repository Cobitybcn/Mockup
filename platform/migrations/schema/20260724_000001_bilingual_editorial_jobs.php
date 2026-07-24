<?php
declare(strict_types=1);

return [
    'description' => 'Persist bilingual editorial generation jobs across navigation',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_jobs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                entity_type VARCHAR(24) NOT NULL,
                entity_id INT UNSIGNED NOT NULL,
                action VARCHAR(24) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'queued',
                source_locale VARCHAR(12) NOT NULL DEFAULT 'es',
                target_locale VARCHAR(12) NOT NULL DEFAULT 'en',
                payload_json LONGTEXT NOT NULL,
                result_json LONGTEXT NOT NULL,
                error LONGTEXT NOT NULL,
                task_name VARCHAR(512) NOT NULL DEFAULT '',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                started_at VARCHAR(40) NULL,
                completed_at VARCHAR(40) NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                KEY ix_bilingual_editorial_jobs_entity (user_id,entity_type,entity_id,status),
                KEY ix_bilingual_editorial_jobs_status (status,updated_at),
                CONSTRAINT fk_bilingual_editorial_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS bilingual_editorial_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'queued',
            source_locale TEXT NOT NULL DEFAULT 'es',
            target_locale TEXT NOT NULL DEFAULT 'en',
            payload_json TEXT NOT NULL DEFAULT '{}',
            result_json TEXT NOT NULL DEFAULT '{}',
            error TEXT NOT NULL DEFAULT '',
            task_name TEXT NOT NULL DEFAULT '',
            attempts INTEGER NOT NULL DEFAULT 0,
            started_at TEXT,
            completed_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_bilingual_editorial_jobs_entity ON bilingual_editorial_jobs (user_id,entity_type,entity_id,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_bilingual_editorial_jobs_status ON bilingual_editorial_jobs (status,updated_at)');
    },
];
