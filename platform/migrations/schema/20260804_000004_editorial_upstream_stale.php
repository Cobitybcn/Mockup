<?php
declare(strict_types=1);

/**
 * Marca de "mi fuente cambio": el contenido editorial de un mockup se escribio a
 * partir de una lectura de la obra que ya no es la aprobada.
 *
 * Hasta ahora publicar una obra regeneraba el texto de todos sus mockups en el
 * acto — una llamada al modelo por cada uno, dentro de la misma peticion. Eso
 * ponia a la seccion Publicacion a escribir contenido, cuando su trabajo es leer
 * y distribuir, y hacia de publicar un acto caro y sorpresivo.
 *
 * Con esta columna, publicar solo MARCA. La ficha de la obra —que es la unica
 * que escribe— muestra cuantos quedaron desactualizados y el artista decide
 * cuando regenerarlos, viendo el numero antes de apretar.
 *
 * No se reutiliza el estado 'stale' que ya existe: ese dice "falta adaptar al
 * otro idioma", y esto dice "cambio la fuente". Mezclarlos dejaria un contador
 * que miente en los dos sentidos.
 */
return [
    'description' => 'Marca de fuente cambiada en el contenido editorial, para que publicar avise en vez de regenerar',
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

        if (!$tableExists('bilingual_editorial_content') || $columnExists('bilingual_editorial_content', 'upstream_changed_at')) {
            return;
        }

        $pdo->exec($mysql
            ? 'ALTER TABLE bilingual_editorial_content ADD COLUMN upstream_changed_at VARCHAR(40) NULL'
            : "ALTER TABLE bilingual_editorial_content ADD COLUMN upstream_changed_at TEXT NOT NULL DEFAULT ''");
    },
];
