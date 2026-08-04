<?php
declare(strict_types=1);

/**
 * Contexto de publicacion de Saatchi, separado del nucleo conceptual de la obra.
 *
 * Son los campos estructurados del formulario de Saatchi (category, subject,
 * mediums, materials, styles) que la plataforma ya indexa por su cuenta y que por
 * eso deben EXCLUIRSE de las keywords. No existian en la base, asi que la regla de
 * deduplicacion no tenia insumo.
 *
 * Se guarda como JSON en tres niveles con precedencia obra > serie > artista, para
 * que el artista lo defina una vez y solo corrija la excepcion.
 *
 * No lleva campo "support": si el soporte es una opcion de Materials, se resuelve
 * ahi. Y el modelo nunca infiere estos valores desde la imagen.
 */
return [
    'description' => 'Contexto de listing de Saatchi por artista, serie y obra',
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

        $tipo = $mysql ? 'MEDIUMTEXT NULL' : "TEXT NOT NULL DEFAULT ''";

        foreach ([
            'artist_profiles' => 'saatchi_defaults',
            'artwork_series' => 'saatchi_defaults',
            'artworks' => 'saatchi_overrides',
        ] as $tabla => $columna) {
            if (!$tableExists($tabla) || $columnExists($tabla, $columna)) {
                continue;
            }
            $pdo->exec("ALTER TABLE {$tabla} ADD COLUMN {$columna} {$tipo}");
        }
    },
];
