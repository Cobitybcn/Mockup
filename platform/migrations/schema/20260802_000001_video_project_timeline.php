<?php
declare(strict_types=1);

/**
 * Until now a montage was its sequences, whole and in order. Cutting, splitting
 * and duplicating need something finer: a list of blocks, each pointing at a
 * generated clip with its own in and out points. Several blocks can share one
 * clip, which is what makes duplicating a fragment possible.
 *
 * Left null, the montage still means "every sequence, whole", so nothing that
 * exists today has to be migrated.
 */
return [
    'description' => 'Let a video project keep a cut list instead of whole sequences',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $table = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_projects'");
            $table->execute();
            if ((int)$table->fetchColumn() === 0) return;

            $column = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='video_projects' AND COLUMN_NAME='timeline_json'");
            $column->execute();
            if ((int)$column->fetchColumn() > 0) return;

            $pdo->exec('ALTER TABLE video_projects ADD COLUMN timeline_json MEDIUMTEXT NULL');
            return;
        }

        $table = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='video_projects'");
        $table->execute();
        if ((int)$table->fetchColumn() === 0) return;

        $columns = array_column($pdo->query('PRAGMA table_info(video_projects)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (in_array('timeline_json', $columns, true)) return;

        $pdo->exec('ALTER TABLE video_projects ADD COLUMN timeline_json TEXT');
    },
];
