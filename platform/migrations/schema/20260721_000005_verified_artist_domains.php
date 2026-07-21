<?php
declare(strict_types=1);

return [
    'description' => 'Add tenant-safe DNS ownership verification for artist custom domains',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $long = $mysql ? 'TEXT' : 'TEXT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_domains (
            id {$id},
            user_id {$integer} NOT NULL UNIQUE,
            hostname VARCHAR(253) NOT NULL UNIQUE,
            verification_token VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            verified_at VARCHAR(40) NULL,
            last_checked_at VARCHAR(40) NULL,
            last_error {$long} NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        )");

        if ($mysql) {
            $profileTableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artist_profiles'")->fetchColumn() > 0;
        } else {
            $profileTableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='artist_profiles'")->fetchColumn() > 0;
        }
        if (!$profileTableExists) return;

        $now = date('c');
        if ($mysql) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO artist_domains
                (user_id,hostname,verification_token,status,verified_at,last_checked_at,last_error,created_at,updated_at)
                SELECT user_id,LOWER(custom_domain),'','pending',NULL,NULL,NULL,?,?
                FROM artist_profiles WHERE TRIM(custom_domain)<>''");
        } else {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO artist_domains
                (user_id,hostname,verification_token,status,verified_at,last_checked_at,last_error,created_at,updated_at)
                SELECT user_id,LOWER(custom_domain),'','pending',NULL,NULL,NULL,?,?
                FROM artist_profiles WHERE TRIM(custom_domain)<>''");
        }
        $stmt->execute([$now, $now]);
    },
];
