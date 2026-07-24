<?php
declare(strict_types=1);

return [
    'description' => 'Persist the artist-defined series display order',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artwork_series'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='artwork_series'")->fetchColumn() > 0;
        }
        if (!$tableExists) return;

        $hasColumn = false;
        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artwork_series' AND COLUMN_NAME='display_order'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            foreach ($pdo->query('PRAGMA table_info(artwork_series)') as $row) {
                if ((string)$row['name'] === 'display_order') {
                    $hasColumn = true;
                    break;
                }
            }
        }

        if (!$hasColumn) {
            $pdo->exec('ALTER TABLE artwork_series ADD COLUMN display_order INTEGER NOT NULL DEFAULT 0');
        }
    },
];
