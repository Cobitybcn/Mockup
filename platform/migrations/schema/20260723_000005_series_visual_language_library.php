<?php
declare(strict_types=1);

return [
    'description' => 'Link visual-language evidence to existing artworks and mockups and store artist guidance',
    'up' => static function (PDO $pdo): void {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $columns = [];

        if ($driver === 'mysql') {
            foreach ($pdo->query('SHOW COLUMNS FROM series_visual_language_images')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $columns[(string)$row['Field']] = true;
            }
            foreach ([
                'source_type' => "ALTER TABLE series_visual_language_images ADD COLUMN source_type VARCHAR(30) NOT NULL DEFAULT 'upload' AFTER image_role",
                'source_artwork_id' => 'ALTER TABLE series_visual_language_images ADD COLUMN source_artwork_id INT UNSIGNED NULL AFTER source_type',
                'source_mockup_id' => 'ALTER TABLE series_visual_language_images ADD COLUMN source_mockup_id INT UNSIGNED NULL AFTER source_artwork_id',
                'user_note' => 'ALTER TABLE series_visual_language_images ADD COLUMN user_note TEXT NULL AFTER source_mockup_id',
            ] as $column => $sql) {
                if (!isset($columns[$column])) {
                    $pdo->exec($sql);
                }
            }
            try {
                $pdo->exec('CREATE INDEX series_visual_language_source_artwork_idx ON series_visual_language_images(user_id, source_artwork_id, id)');
            } catch (Throwable) {
                // Index may already exist in an environment restored from a later snapshot.
            }
            try {
                $pdo->exec('CREATE INDEX series_visual_language_source_mockup_idx ON series_visual_language_images(user_id, source_mockup_id, id)');
            } catch (Throwable) {
                // Index may already exist in an environment restored from a later snapshot.
            }
            return;
        }

        foreach ($pdo->query('PRAGMA table_info(series_visual_language_images)')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string)$row['name']] = true;
        }
        foreach ([
            'source_type' => "ALTER TABLE series_visual_language_images ADD COLUMN source_type TEXT NOT NULL DEFAULT 'upload'",
            'source_artwork_id' => 'ALTER TABLE series_visual_language_images ADD COLUMN source_artwork_id INTEGER NULL',
            'source_mockup_id' => 'ALTER TABLE series_visual_language_images ADD COLUMN source_mockup_id INTEGER NULL',
            'user_note' => 'ALTER TABLE series_visual_language_images ADD COLUMN user_note TEXT NULL',
        ] as $column => $sql) {
            if (!isset($columns[$column])) {
                $pdo->exec($sql);
            }
        }
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_source_artwork_idx ON series_visual_language_images(user_id, source_artwork_id, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS series_visual_language_source_mockup_idx ON series_visual_language_images(user_id, source_mockup_id, id)');
    },
];
