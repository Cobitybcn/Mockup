<?php
declare(strict_types=1);

/**
 * Sembrado inicial de la configuración custom de cámaras en app_settings.
 *
 * Si la clave 'mockup_camera_slots_custom' no existe en app_settings, se lee el
 * archivo semilla app/Config/mockup_camera_slots_custom.php y se inserta en
 * app_settings. Esto garantiza que la base de datos tenga la configuración inicial
 * sin sobrescribir ediciones personalizadas previas si ya existen.
 */
return [
    'description' => 'Sembrado inicial de mockup_camera_slots_custom en app_settings si no existe',
    'up' => static function (PDO $pdo): void {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM app_settings WHERE `key` = :key');
        $stmt->execute([':key' => 'mockup_camera_slots_custom']);
        $count = (int)$stmt->fetchColumn();

        if ($count > 0) {
            return; // Ya existe en la base de datos, no sobrescribir.
        }

        $seedPath = dirname(__DIR__, 2) . '/app/Config/mockup_camera_slots_custom.php';
        if (!is_file($seedPath)) {
            return;
        }

        $seedData = require $seedPath;
        if (!is_array($seedData)) {
            return;
        }

        $encoded = json_encode($seedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        $now = date('c');
        $mysql = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql';

        if ($mysql) {
            $insert = $pdo->prepare('INSERT INTO app_settings (`key`, `value`, created_at, updated_at) VALUES (:key, :value, :created_at, :updated_at) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)');
        } else {
            $insert = $pdo->prepare('INSERT OR REPLACE INTO app_settings (`key`, `value`, created_at, updated_at) VALUES (:key, :value, :created_at, :updated_at)');
        }

        $insert->execute([
            ':key' => 'mockup_camera_slots_custom',
            ':value' => $encoded,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    },
];
