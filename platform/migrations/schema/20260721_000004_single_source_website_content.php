<?php
declare(strict_types=1);

return [
    'description' => 'Make Artwork Metadata the only source for website artwork content',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';
        if ($mysql) {
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publications'")->fetchColumn() > 0;
        } else {
            $exists = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='publications'")->fetchColumn() > 0;
        }
        if (!$exists) return;

        $pdo->exec("UPDATE publications SET content_source='inherit'");
        $pdo->exec("UPDATE publications
            SET title=COALESCE((SELECT sh.title FROM artwork_sheets sh WHERE sh.id=publications.artwork_sheet_id AND sh.user_id=publications.user_id),title),
                description=COALESCE((SELECT sh.description FROM artwork_sheets sh WHERE sh.id=publications.artwork_sheet_id AND sh.user_id=publications.user_id),description),
                short_description=COALESCE((SELECT sh.short_description FROM artwork_sheets sh WHERE sh.id=publications.artwork_sheet_id AND sh.user_id=publications.user_id),short_description)");
    },
];
