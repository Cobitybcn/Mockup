<?php
declare(strict_types=1);

return [
    'description' => 'Give each video project one music track positioned on its timeline',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        // The track itself lives in video_reference_assets, which already stores
        // and serves uploaded media. Only the project's choice is recorded here.
        //
        // music_offset_seconds is where the track's own start sits on the video
        // timeline: negative means the montage begins partway into the track,
        // positive means the montage opens in silence before the music comes in.
        $columns = $mysql
            ? [
                'music_asset_id' => 'INT UNSIGNED NULL',
                'music_offset_seconds' => 'DECIMAL(9,3) NOT NULL DEFAULT 0',
                'music_fade_in_seconds' => 'DECIMAL(6,3) NOT NULL DEFAULT 0',
                'music_fade_out_seconds' => 'DECIMAL(6,3) NOT NULL DEFAULT 0.5',
                'music_duration_seconds' => 'DECIMAL(9,3) NOT NULL DEFAULT 0',
                // Peak amplitudes sampled from the track, so the timeline can
                // draw the waveform crisply at any zoom without fetching audio.
                'music_peaks_json' => 'MEDIUMTEXT NULL',
            ]
            : [
                'music_asset_id' => 'INTEGER NULL',
                'music_offset_seconds' => 'REAL NOT NULL DEFAULT 0',
                'music_fade_in_seconds' => 'REAL NOT NULL DEFAULT 0',
                'music_fade_out_seconds' => 'REAL NOT NULL DEFAULT 0.5',
                'music_duration_seconds' => 'REAL NOT NULL DEFAULT 0',
                'music_peaks_json' => 'TEXT NULL',
            ];

        if ($mysql) {
            $exists = static function (string $column) use ($pdo): bool {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_projects' AND COLUMN_NAME=?");
                $stmt->execute([$column]);
                return (int)$stmt->fetchColumn() > 0;
            };
        } else {
            $present = array_column($pdo->query('PRAGMA table_info(video_projects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $exists = static fn(string $column): bool => in_array($column, $present, true);
        }

        foreach ($columns as $column => $definition) {
            if ($exists($column)) continue;
            $pdo->exec("ALTER TABLE video_projects ADD COLUMN {$column} {$definition}");
        }
    },
];
