<?php
declare(strict_types=1);

return [
    'description' => 'First-party visitor event log for the public artist site',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';

        // Privacy-clean by design: no IP address, no user agent, no cookies.
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_events (
            id {$id},
            user_id {$integer} NOT NULL,
            event_type VARCHAR(40) NOT NULL,
            entity_type VARCHAR(30) NOT NULL DEFAULT '',
            slug VARCHAR(255) NOT NULL DEFAULT '',
            locale VARCHAR(12) NOT NULL DEFAULT '',
            referrer_host VARCHAR(255) NOT NULL DEFAULT '',
            detail VARCHAR(255) NOT NULL DEFAULT '',
            created_at VARCHAR(40) NOT NULL
        )");

        foreach ([
            'idx_site_events_user_type_created' => '(user_id, event_type, created_at)',
            'idx_site_events_user_slug' => '(user_id, slug)',
        ] as $name => $columns) {
            if ($mysql) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artist_site_events' AND INDEX_NAME=?");
                $check->execute([$name]);
                if ((int)$check->fetchColumn() > 0) continue;
                $pdo->exec("CREATE INDEX {$name} ON artist_site_events {$columns}");
            } else {
                $pdo->exec("CREATE INDEX IF NOT EXISTS {$name} ON artist_site_events {$columns}");
            }
        }
    },
];
