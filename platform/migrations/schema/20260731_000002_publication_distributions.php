<?php
declare(strict_types=1);

return [
    'description' => 'Per-destination distribution state of a publication (adapters read the frozen product)',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS publication_distributions (
            id {$id},
            user_id {$integer} NOT NULL,
            publication_id {$integer} NOT NULL,
            destination VARCHAR(20) NOT NULL,
            locale VARCHAR(8) NOT NULL DEFAULT '',
            status VARCHAR(30) NOT NULL,
            external_id VARCHAR(120) NOT NULL DEFAULT '',
            external_url {$text} NOT NULL,
            error {$text} NOT NULL,
            payload_json {$text} NOT NULL,
            product_fingerprint VARCHAR(64) NOT NULL DEFAULT '',
            attempted_at VARCHAR(40) NOT NULL DEFAULT '',
            created_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        )");
        foreach ([
            'publication_distributions_destination_unique' => ['unique' => true, 'columns' => '(publication_id,destination)'],
            'publication_distributions_user' => ['unique' => false, 'columns' => '(user_id)'],
        ] as $name => $index) {
            if ($mysql) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publication_distributions' AND INDEX_NAME=?");
                $check->execute([$name]);
                if ((int)$check->fetchColumn() > 0) continue;
            }
            $unique = $index['unique'] ? 'UNIQUE ' : '';
            $ifNotExists = $mysql ? '' : 'IF NOT EXISTS ';
            $pdo->exec("CREATE {$unique}INDEX {$ifNotExists}{$name}
                ON publication_distributions {$index['columns']}");
        }
    },
];
