<?php
declare(strict_types=1);

return [
    'description' => 'Add reversible published snapshots for Spanish editorial content',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $columns = [
            'is_published' => $mysql ? 'TINYINT(1) NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0',
            'published_content_json' => $mysql ? 'LONGTEXT NULL' : "TEXT NOT NULL DEFAULT '{}'",
            'published_at' => $mysql ? 'VARCHAR(40) NULL' : "TEXT NOT NULL DEFAULT ''",
        ];
        $existing = [];
        if ($mysql) {
            foreach ($pdo->query('SHOW COLUMNS FROM bilingual_editorial_content')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[(string)$row['Field']] = true;
            }
        } else {
            foreach ($pdo->query('PRAGMA table_info(bilingual_editorial_content)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[(string)$row['name']] = true;
            }
        }
        foreach ($columns as $name => $definition) {
            if (!isset($existing[$name])) {
                $pdo->exec("ALTER TABLE bilingual_editorial_content ADD COLUMN {$name} {$definition}");
            }
        }
        if ($mysql) {
            $pdo->exec("UPDATE bilingual_editorial_content SET published_content_json='{}' WHERE published_content_json IS NULL");
        }
    },
];
