<?php
declare(strict_types=1);

return [
    'description' => 'Version publication header media and editorial display order columns',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='publications'")->fetchColumn() > 0;
        }
        if (!$tableExists) return;

        $hasColumn = static function (string $column) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications' AND COLUMN_NAME=?");
                $stmt->execute([$column]);
                return (int)$stmt->fetchColumn() > 0;
            }
            foreach ($pdo->query('PRAGMA table_info(publications)') as $row) {
                if ((string)$row['name'] === $column) return true;
            }
            return false;
        };

        if (!$hasColumn('header_file')) {
            $pdo->exec("ALTER TABLE publications ADD COLUMN header_file VARCHAR(255) NOT NULL DEFAULT ''");
        }
        if (!$hasColumn('display_order')) {
            $pdo->exec('ALTER TABLE publications ADD COLUMN display_order INTEGER NOT NULL DEFAULT 0');
        }
    },
];
