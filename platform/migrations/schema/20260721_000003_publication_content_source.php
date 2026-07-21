<?php
declare(strict_types=1);

return [
    'description' => 'Let website artwork copy inherit canonical Artwork Metadata unless explicitly customized',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='publications'")->fetchColumn() > 0;
        }
        if (!$tableExists) return;

        $hasColumn = false;
        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications' AND COLUMN_NAME='content_source'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            foreach ($pdo->query('PRAGMA table_info(publications)') as $row) {
                if ((string)($row['name'] ?? '') === 'content_source') $hasColumn = true;
            }
        }

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE publications ADD COLUMN content_source VARCHAR(20) NOT NULL DEFAULT 'inherit'");
        }

        // Preserve intentionally published copy. Drafts adopt the new single-source workflow.
        $pdo->exec("UPDATE publications SET content_source='custom' WHERE status='published'");
        $pdo->exec("UPDATE publications SET content_source='inherit' WHERE content_source NOT IN ('inherit','custom') OR content_source IS NULL");
    },
];
