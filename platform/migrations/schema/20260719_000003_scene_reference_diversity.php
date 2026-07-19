<?php
declare(strict_types=1);

return [
    'description' => 'Cache scene reference fingerprints and editorial similarity groups',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS scene_reference_profiles (
                reference_key VARCHAR(255) NOT NULL,
                content_hash CHAR(64) NOT NULL DEFAULT '',
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
                descriptor_json LONGTEXT NULL,
                similarity_group VARCHAR(80) NULL,
                analyzed_at VARCHAR(40) NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (reference_key),
                KEY scene_reference_content_hash_idx (content_hash),
                KEY scene_reference_similarity_group_idx (similarity_group)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS scene_reference_profiles (
            reference_key TEXT NOT NULL PRIMARY KEY,
            content_hash TEXT NOT NULL DEFAULT '',
            file_size INTEGER NOT NULL DEFAULT 0,
            file_mtime INTEGER NOT NULL DEFAULT 0,
            descriptor_json TEXT NULL,
            similarity_group TEXT NULL,
            analyzed_at TEXT NULL,
            updated_at TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS scene_reference_content_hash_idx ON scene_reference_profiles(content_hash)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS scene_reference_similarity_group_idx ON scene_reference_profiles(similarity_group)');
    },
];

