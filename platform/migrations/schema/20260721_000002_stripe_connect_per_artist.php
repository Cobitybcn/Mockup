<?php
declare(strict_types=1);

return [
    'description' => 'Add per-artist Stripe Connect accounts and payment account snapshots on orders',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $pdo->exec("CREATE TABLE IF NOT EXISTS artist_site_payment_connections (
            id {$id}, user_id {$integer} NOT NULL UNIQUE,
            provider VARCHAR(40) NOT NULL DEFAULT 'stripe',
            external_account_id VARCHAR(120) NOT NULL DEFAULT '',
            livemode INTEGER NOT NULL DEFAULT 0,
            charges_enabled INTEGER NOT NULL DEFAULT 0,
            payouts_enabled INTEGER NOT NULL DEFAULT 0,
            details_submitted INTEGER NOT NULL DEFAULT 0,
            connection_status VARCHAR(30) NOT NULL DEFAULT 'not_connected',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        )");

        $hasOrderColumn = false;
        if ($mysql) {
            $stmt = $pdo->prepare('SHOW COLUMNS FROM artist_site_orders LIKE ?');
            $stmt->execute(['provider_account_id']);
            $hasOrderColumn = (bool)$stmt->fetchColumn();
        } else {
            foreach ($pdo->query('PRAGMA table_info(artist_site_orders)') as $row) {
                if ((string)($row['name'] ?? '') === 'provider_account_id') $hasOrderColumn = true;
            }
        }
        if (!$hasOrderColumn) {
            $pdo->exec("ALTER TABLE artist_site_orders ADD COLUMN provider_account_id VARCHAR(120) NOT NULL DEFAULT ''");
        }
    },
];
