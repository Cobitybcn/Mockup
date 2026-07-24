<?php
declare(strict_types=1);

return [
    'description' => 'Ensure the artwork series baseline exists before later series extensions',
    'up' => static function (PDO $pdo): void {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'mysql') {
            $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_series (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(210) NOT NULL,
                description TEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                UNIQUE KEY artwork_series_user_slug (user_id, slug),
                KEY artwork_series_user_status (user_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS artwork_series (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            slug TEXT NOT NULL,
            description TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE(user_id, slug)
        )");
    },
];
