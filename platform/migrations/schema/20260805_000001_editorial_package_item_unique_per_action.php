<?php
declare(strict_types=1);

/**
 * La unicidad de un item de paquete editorial incluye la accion.
 *
 * Desde que el canal entra al PRIMER paquete de una obra nueva, la misma obra
 * lleva legitimamente DOS items en un paquete: su lectura en la etapa 20
 * (action 'prepare' o 'adapt') y sus textos de canal en la etapa 40 (action
 * 'channel'). La clave unica original era (package_id, entity_type, entity_id)
 * y el segundo INSERT chocaba: "Duplicate entry '13-artwork-10106'".
 *
 * Ensanchar una clave unica nunca invalida filas existentes: es seguro sobre
 * datos vivos.
 */
return [
    'description' => 'Un item de paquete editorial es unico por entidad Y accion, no solo por entidad',
    'up' => static function (PDO $pdo): void {
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $columnas = static function () use ($pdo): int {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'artwork_editorial_package_items'
                    AND INDEX_NAME = 'uq_artwork_editorial_package_item'");
                $stmt->execute();
                return (int)$stmt->fetchColumn();
            };
            if ($columnas() >= 4) {
                return; // Ya incluye la accion.
            }
            $pdo->exec('ALTER TABLE artwork_editorial_package_items
                DROP KEY uq_artwork_editorial_package_item,
                ADD UNIQUE KEY uq_artwork_editorial_package_item (package_id,entity_type,entity_id,action)');
            return;
        }

        // SQLite no puede soltar una restriccion declarada en linea: se
        // reconstruye la tabla con la clave nueva y se copian las filas.
        $info = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='artwork_editorial_package_items'")->fetchColumn();
        if (!is_string($info) || $info === '') {
            return; // Sin tabla no hay nada que ensanchar.
        }
        if (str_contains($info, 'entity_id,action') || str_contains($info, 'entity_id, action')) {
            return; // Ya incluye la accion.
        }
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec("CREATE TABLE artwork_editorial_package_items_nueva (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            package_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            stage_order INTEGER NOT NULL,
            action TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            editorial_job_id INTEGER,
            error TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            UNIQUE (package_id,entity_type,entity_id,action),
            FOREIGN KEY (package_id) REFERENCES artwork_editorial_packages(id) ON DELETE CASCADE
        )");
        $pdo->exec('INSERT INTO artwork_editorial_package_items_nueva
            SELECT id,package_id,entity_type,entity_id,stage_order,action,status,editorial_job_id,error,created_at,updated_at
            FROM artwork_editorial_package_items');
        $pdo->exec('DROP TABLE artwork_editorial_package_items');
        $pdo->exec('ALTER TABLE artwork_editorial_package_items_nueva RENAME TO artwork_editorial_package_items');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_artwork_editorial_package_items_stage ON artwork_editorial_package_items (package_id,stage_order,status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS ix_artwork_editorial_package_items_job ON artwork_editorial_package_items (editorial_job_id)');
        $pdo->exec('PRAGMA foreign_keys=ON');
    },
];
