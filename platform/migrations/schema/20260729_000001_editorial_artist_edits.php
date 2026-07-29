<?php
declare(strict_types=1);

return [
    'description' => 'Track artist-authored edits on editorial content (EDITORIAL_CORE Libro VI Cap. 4: la edicion del artista es soberana y la cascada de regeneracion la respeta)',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bilingual_editorial_content'")->fetchColumn() > 0;
        } else {
            $tableExists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='bilingual_editorial_content'")->fetchColumn() > 0;
        }
        if (!$tableExists) return;

        if ($mysql) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bilingual_editorial_content' AND COLUMN_NAME='edited_by_artist'");
            $stmt->execute();
            $hasColumn = (int)$stmt->fetchColumn() > 0;
        } else {
            $hasColumn = false;
            foreach ($pdo->query('PRAGMA table_info(bilingual_editorial_content)') as $row) {
                if ((string)$row['name'] === 'edited_by_artist') { $hasColumn = true; break; }
            }
        }

        if (!$hasColumn) {
            $pdo->exec("ALTER TABLE bilingual_editorial_content ADD COLUMN edited_by_artist TINYINT(1) NOT NULL DEFAULT 0");
        }
    },
];
