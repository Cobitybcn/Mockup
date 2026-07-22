<?php
declare(strict_types=1);

return [
    'description' => 'Store each artist Stripe secret key and webhook secret encrypted',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $columnExists = static function (string $column) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SHOW COLUMNS FROM artist_site_payment_connections LIKE ?');
                $stmt->execute([$column]);
                return (bool)$stmt->fetchColumn();
            }
            foreach ($pdo->query('PRAGMA table_info(artist_site_payment_connections)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ((string)($row['name'] ?? '') === $column) return true;
            }
            return false;
        };
        foreach (['secret_key_encrypted', 'webhook_secret_encrypted'] as $column) {
            if ($columnExists($column)) continue;
            $pdo->exec('ALTER TABLE artist_site_payment_connections ADD COLUMN ' . $column . ' ' . ($mysql ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT ''"));
            if ($mysql) $pdo->exec("UPDATE artist_site_payment_connections SET {$column}='' WHERE {$column} IS NULL");
        }
    },
];
