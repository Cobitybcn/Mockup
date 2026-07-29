<?php
declare(strict_types=1);

return [
    'description' => 'Registro semantico de titulos (EDITORIAL_CORE Libro I Cap. 6: control de repeticion exacta y conceptual) + ADN de titulos por serie',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artwork_title_registry'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='artwork_title_registry'")->fetchColumn() > 0;
        }
        if (!$tableExists) {
            $id = $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            $pdo->exec("CREATE TABLE artwork_title_registry (
                id {$id},
                user_id INT NOT NULL,
                series_id INT NOT NULL DEFAULT 0,
                artwork_id INT NOT NULL DEFAULT 0,
                title VARCHAR(190) NOT NULL,
                normalized VARCHAR(190) NOT NULL,
                language VARCHAR(40) NOT NULL DEFAULT '',
                meaning VARCHAR(255) NOT NULL DEFAULT '',
                semantic_root VARCHAR(80) NOT NULL DEFAULT '',
                tone VARCHAR(40) NOT NULL DEFAULT '',
                confidence VARCHAR(12) NOT NULL DEFAULT '',
                reason TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'suggested',
                created_at VARCHAR(40) NOT NULL DEFAULT '',
                updated_at VARCHAR(40) NOT NULL DEFAULT ''
            )");
            $pdo->exec('CREATE INDEX idx_title_registry_user_norm ON artwork_title_registry (user_id, normalized)');
            $pdo->exec('CREATE INDEX idx_title_registry_user_root ON artwork_title_registry (user_id, semantic_root)');
            $pdo->exec('CREATE INDEX idx_title_registry_artwork ON artwork_title_registry (user_id, artwork_id, status)');
        }

        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artwork_series' AND COLUMN_NAME='title_dna'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            $hasColumn = false;
            foreach ($pdo->query('PRAGMA table_info(artwork_series)') as $row) {
                if ((string)$row['name'] === 'title_dna') { $hasColumn = true; break; }
            }
        }
        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE artwork_series ADD COLUMN title_dna TEXT NULL");
        }
    },
];
