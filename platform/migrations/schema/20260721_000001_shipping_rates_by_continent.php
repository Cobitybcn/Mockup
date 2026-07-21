<?php
declare(strict_types=1);

return [
    'description' => 'Add editable per-continent shipping rates to Artist Site Manager',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $hasColumn = static function () use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM artist_site_settings LIKE ?");
                $stmt->execute(['shipping_rates_json']);
                return (bool)$stmt->fetchColumn();
            }
            foreach ($pdo->query('PRAGMA table_info(artist_site_settings)') as $row) {
                if ((string)($row['name'] ?? '') === 'shipping_rates_json') return true;
            }
            return false;
        };
        if (!$hasColumn()) {
            $pdo->exec('ALTER TABLE artist_site_settings ADD COLUMN shipping_rates_json ' . ($mysql ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'"));
        }
        $defaults = json_encode([
            'europe' => 25000,
            'africa' => 25000,
            'asia' => 25000,
            'north_america' => 25000,
            'south_america' => 25000,
            'oceania' => 25000,
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $stmt = $pdo->prepare("UPDATE artist_site_settings SET shipping_rates_json=? WHERE shipping_rates_json IS NULL OR shipping_rates_json=''");
        $stmt->execute([$defaults]);
    },
];
