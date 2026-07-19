<?php
declare(strict_types=1);

return [
    'description' => 'Add editorial scene ranking profiles for curated discovery',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS scene_ranking_profiles (
                category_slug VARCHAR(80) NOT NULL,
                featured_score INT UNSIGNED NOT NULL DEFAULT 0,
                featured_until VARCHAR(10) NULL,
                editorial_score INT UNSIGNED NOT NULL DEFAULT 50,
                discovered_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (category_slug),
                KEY scene_ranking_featured_idx (featured_score, featured_until),
                KEY scene_ranking_editorial_idx (editorial_score)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS scene_ranking_profiles (
            category_slug TEXT NOT NULL PRIMARY KEY,
            featured_score INTEGER NOT NULL DEFAULT 0,
            featured_until TEXT NULL,
            editorial_score INTEGER NOT NULL DEFAULT 50,
            discovered_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS scene_ranking_featured_idx ON scene_ranking_profiles(featured_score, featured_until)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS scene_ranking_editorial_idx ON scene_ranking_profiles(editorial_score)');
    },
];
