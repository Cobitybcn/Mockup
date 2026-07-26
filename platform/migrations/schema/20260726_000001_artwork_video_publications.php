<?php
declare(strict_types=1);

return [
    'description' => 'Publish one final video on each artwork page',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        $id = $mysql ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $integer = $mysql ? 'INT UNSIGNED' : 'INTEGER';

        $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_video_publications (
            id {$id},
            user_id {$integer} NOT NULL,
            artwork_id {$integer} NOT NULL,
            video_export_id {$integer} NOT NULL,
            published_at VARCHAR(40) NOT NULL,
            updated_at VARCHAR(40) NOT NULL
        )");
        foreach ([
            'artwork_video_publications_artwork_unique' => '(user_id,artwork_id)',
            'artwork_video_publications_export_unique' => '(video_export_id)',
        ] as $name => $columns) {
            if ($mysql) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='artwork_video_publications' AND INDEX_NAME=?");
                $check->execute([$name]);
                if ((int)$check->fetchColumn() > 0) continue;
            }
            $ifNotExists = $mysql ? '' : 'IF NOT EXISTS ';
            $pdo->exec("CREATE UNIQUE INDEX {$ifNotExists}{$name}
                ON artwork_video_publications {$columns}");
        }
    },
];
