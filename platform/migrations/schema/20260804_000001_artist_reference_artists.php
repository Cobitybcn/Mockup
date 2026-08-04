<?php
declare(strict_types=1);

/**
 * Artistas de referencia del perfil.
 *
 * Saatchi Art recomienda explicitamente incluir entre las keywords "artists who
 * inspired the work": es de las pocas que traen trafico de descubrimiento real,
 * porque quien busca "Rothko" ahi esta buscando comprar en esa linea.
 *
 * Va como campo del artista y no como deduccion del modelo a proposito: una
 * filiacion es una afirmacion verificable sobre la obra, y EDITORIAL_CORE
 * prohibe inventar validacion externa. Si el campo esta vacio, el generador no
 * nombra a nadie.
 */
return [
    'description' => 'Artistas de referencia declarados por el artista, para las keywords de Saatchi',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        $tableExists = static function (string $table) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name');
                $stmt->execute(['table_name' => $table]);
                return (int)$stmt->fetchColumn() > 0;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table_name");
            $stmt->execute(['table_name' => $table]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $columnExists = static function (string $table, string $column) use ($pdo, $mysql): bool {
            if ($mysql) {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table_name AND COLUMN_NAME=:column_name');
                $stmt->execute(['table_name' => $table, 'column_name' => $column]);
                return (int)$stmt->fetchColumn() > 0;
            }
            foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (strcasecmp((string)($row['name'] ?? ''), $column) === 0) {
                    return true;
                }
            }
            return false;
        };

        // La tabla nace en el baseline de runtime, que en una base vacia todavia
        // no corrio: sin esta guarda, el test de gobernanza falla.
        if (!$tableExists('artist_profiles')) {
            return;
        }
        if ($columnExists('artist_profiles', 'reference_artists')) {
            return;
        }

        $pdo->exec($mysql
            ? "ALTER TABLE artist_profiles ADD COLUMN reference_artists TEXT NULL"
            : "ALTER TABLE artist_profiles ADD COLUMN reference_artists TEXT NOT NULL DEFAULT ''");
    },
];
