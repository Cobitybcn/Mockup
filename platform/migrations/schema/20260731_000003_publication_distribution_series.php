<?php
declare(strict_types=1);

return [
    'description' => 'Distribution series: per-part rows with cadence scheduling on publication_distributions',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        $columns = [
            'part' => 'SMALLINT NOT NULL DEFAULT 0',
            'scheduled_at' => $mysql ? "VARCHAR(40) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
            'task_name' => $mysql ? "VARCHAR(255) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
            'publish_attempt_id' => $mysql ? "VARCHAR(64) NOT NULL DEFAULT ''" : "TEXT NOT NULL DEFAULT ''",
        ];
        foreach ($columns as $column => $definition) {
            if ($mysql) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publication_distributions' AND COLUMN_NAME=?");
                $check->execute([$column]);
                $hasColumn = (int)$check->fetchColumn() > 0;
            } else {
                $hasColumn = false;
                foreach ($pdo->query('PRAGMA table_info(publication_distributions)') as $row) {
                    if ((string)$row['name'] === $column) { $hasColumn = true; break; }
                }
            }
            if (!$hasColumn) {
                $pdo->exec("ALTER TABLE publication_distributions ADD COLUMN {$column} {$definition}");
            }
        }

        // One row per (publication, destination, part): series destinations keep
        // one row per post; single-post destinations stay at part 0.
        if ($mysql) {
            $oldIndex = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publication_distributions' AND INDEX_NAME='publication_distributions_destination_unique'");
            $oldIndex->execute();
            if ((int)$oldIndex->fetchColumn() > 0) {
                $pdo->exec('DROP INDEX publication_distributions_destination_unique ON publication_distributions');
            }
            $newIndex = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='publication_distributions' AND INDEX_NAME='publication_distributions_part_unique'");
            $newIndex->execute();
            if ((int)$newIndex->fetchColumn() === 0) {
                $pdo->exec('CREATE UNIQUE INDEX publication_distributions_part_unique
                    ON publication_distributions (publication_id,destination,part)');
            }
        } else {
            $pdo->exec('DROP INDEX IF EXISTS publication_distributions_destination_unique');
            $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS publication_distributions_part_unique
                ON publication_distributions (publication_id,destination,part)');
        }
    },
];
