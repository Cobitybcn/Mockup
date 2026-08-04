<?php
declare(strict_types=1);

/**
 * Pie de Saatchi por imagen.
 *
 * El formulario de Saatchi tiene un campo "Enter Caption" por imagen adicional,
 * y sus pies son etiquetas cortas. Los del sitio miden 250 caracteres y sirven
 * para otra cosa: no se tocan. Este es un campo aparte, propio de ese canal.
 */
return [
    'description' => 'Pie corto de Saatchi por imagen, sin tocar el pie del sitio',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        $tableExists = static function (string $table) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t');
                $stmt->execute(['t' => $table]);
                return (int)$stmt->fetchColumn() > 0;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:t");
            $stmt->execute(['t' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $columnExists = static function (string $table, string $column) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c');
                $stmt->execute(['t' => $table, 'c' => $column]);
                return (int)$stmt->fetchColumn() > 0;
            }
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }
            return false;
        };

        if (!$tableExists('mockup_sheets') || $columnExists('mockup_sheets', 'saatchi_caption')) {
            return;
        }

        $pdo->exec($mysql
            ? "ALTER TABLE mockup_sheets ADD COLUMN saatchi_caption VARCHAR(120) NOT NULL DEFAULT ''"
            : "ALTER TABLE mockup_sheets ADD COLUMN saatchi_caption TEXT NOT NULL DEFAULT ''");
    },
];
