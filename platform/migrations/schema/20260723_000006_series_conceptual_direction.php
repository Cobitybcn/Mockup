<?php
declare(strict_types=1);

return [
    'description' => 'Store artist-authored conceptual direction and interpretive limits for each series',
    'up' => static function (PDO $pdo): void {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $columns = [];
        if ($driver === 'mysql') {
            foreach ($pdo->query('SHOW COLUMNS FROM artwork_series')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[(string)$row['Field']] = true;
            }
            if (!isset($columns['conceptual_core'])) {
                $pdo->exec('ALTER TABLE artwork_series ADD COLUMN conceptual_core MEDIUMTEXT NULL');
            }
            if (!isset($columns['interpretive_limits'])) {
                $pdo->exec('ALTER TABLE artwork_series ADD COLUMN interpretive_limits MEDIUMTEXT NULL');
            }
            return;
        }

        foreach ($pdo->query('PRAGMA table_info(artwork_series)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['name']] = true;
        }
        if (!isset($columns['conceptual_core'])) {
            $pdo->exec('ALTER TABLE artwork_series ADD COLUMN conceptual_core TEXT');
        }
        if (!isset($columns['interpretive_limits'])) {
            $pdo->exec('ALTER TABLE artwork_series ADD COLUMN interpretive_limits TEXT');
        }
    },
];
