<?php
declare(strict_types=1);

return [
    'description' => 'Store mockup favorites in a table instead of per-user JSON files',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';

        $pdo->exec("CREATE TABLE IF NOT EXISTS mockup_favorites (
            id {$id},
            user_id {$integer} NOT NULL,
            mockup_id {$integer} NOT NULL,
            created_at VARCHAR(40) NOT NULL
        )");

        $name = 'uq_mockup_favorites_user_mockup';
        if ($mysql) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mockup_favorites' AND INDEX_NAME=?");
            $check->execute([$name]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec("CREATE UNIQUE INDEX {$name} ON mockup_favorites (user_id,mockup_id)");
            }
        } else {
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON mockup_favorites (user_id,mockup_id)");
        }
    },
];
