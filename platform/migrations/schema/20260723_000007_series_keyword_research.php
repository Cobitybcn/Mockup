<?php
declare(strict_types=1);

return [
    'description' => 'Add market- and language-specific keyword research for Series',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS series_keyword_research (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                series_id INT UNSIGNED NOT NULL,
                locale VARCHAR(12) NOT NULL,
                market VARCHAR(80) NOT NULL,
                keyword_text VARCHAR(255) NOT NULL,
                avg_monthly_searches INT UNSIGNED NULL,
                volume_label VARCHAR(80) NOT NULL DEFAULT '',
                competition VARCHAR(32) NOT NULL DEFAULT '',
                competition_index SMALLINT UNSIGNED NULL,
                low_top_of_page_bid DECIMAL(14,4) NULL,
                high_top_of_page_bid DECIMAL(14,4) NULL,
                currency_code VARCHAR(8) NOT NULL DEFAULT '',
                selected TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(40) NOT NULL DEFAULT 'google_keyword_planner',
                imported_at VARCHAR(40) NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_series_keyword_market (user_id,series_id,locale,market,keyword_text),
                KEY ix_series_keyword_selection (user_id,series_id,locale,selected),
                CONSTRAINT fk_series_keyword_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_series_keyword_series FOREIGN KEY (series_id) REFERENCES artwork_series(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS series_keyword_research (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            series_id INTEGER NOT NULL,
            locale TEXT NOT NULL,
            market TEXT NOT NULL,
            keyword_text TEXT NOT NULL,
            avg_monthly_searches INTEGER NULL,
            volume_label TEXT NOT NULL DEFAULT '',
            competition TEXT NOT NULL DEFAULT '',
            competition_index INTEGER NULL,
            low_top_of_page_bid REAL NULL,
            high_top_of_page_bid REAL NULL,
            currency_code TEXT NOT NULL DEFAULT '',
            selected INTEGER NOT NULL DEFAULT 0,
            source TEXT NOT NULL DEFAULT 'google_keyword_planner',
            imported_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (user_id,series_id,locale,market,keyword_text),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (series_id) REFERENCES artwork_series(id) ON DELETE CASCADE
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_series_keyword_selection ON series_keyword_research (user_id,series_id,locale,selected)');
    },
];
