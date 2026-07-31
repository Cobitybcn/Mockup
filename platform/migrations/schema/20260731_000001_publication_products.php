<?php
declare(strict_types=1);

return [
    'description' => 'Frozen per-publication product assembling all destination copy from the editorial memory',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS publication_products (
            id {$id},
            user_id {$integer} NOT NULL,
            publication_id {$integer} NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'current',
            payload_json {$text} NOT NULL,
            source_fingerprint VARCHAR(64) NOT NULL,
            generated_at VARCHAR(40) NOT NULL,
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        )");
        foreach ([
            'publication_products_publication_unique' => ['unique' => true, 'columns' => '(publication_id)'],
            'publication_products_user' => ['unique' => false, 'columns' => '(user_id)'],
        ] as $name => $index) {
            if ($mysql) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publication_products' AND INDEX_NAME=?");
                $check->execute([$name]);
                if ((int)$check->fetchColumn() > 0) continue;
            }
            $unique = $index['unique'] ? 'UNIQUE ' : '';
            $ifNotExists = $mysql ? '' : 'IF NOT EXISTS ';
            $pdo->exec("CREATE {$unique}INDEX {$ifNotExists}{$name}
                ON publication_products {$index['columns']}");
        }
    },
];
