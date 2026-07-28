<?php
declare(strict_types=1);

return [
    'description' => 'Per-artwork Saatchi Art listing URL on publications',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='publications'")->fetchColumn() > 0;
        }
        if (!$tableExists) return;

        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications' AND COLUMN_NAME='saatchi_url'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            $hasColumn = false;
            foreach ($pdo->query('PRAGMA table_info(publications)') as $row) {
                if ((string)$row['name'] === 'saatchi_url') { $hasColumn = true; break; }
            }
        }

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE publications ADD COLUMN saatchi_url VARCHAR(500) NOT NULL DEFAULT ''");
        }
    },
];
