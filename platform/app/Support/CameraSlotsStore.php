<?php
declare(strict_types=1);

/**
 * Persistencia de la configuracion custom de camaras (Camera Boards).
 *
 * Vive en app_settings, junto al resto de la configuracion de runtime, porque el
 * filesystem del contenedor es efimero: lo que se escribia como archivo se perdia
 * en cada despliegue sin avisar.
 *
 * El archivo app/Config/mockup_camera_slots_custom.php sigue existiendo como
 * semilla versionada: se usa mientras la base no tenga nada guardado.
 */
class CameraSlotsStore
{
    public const SETTING_KEY = 'mockup_camera_slots_custom';

    /**
     * Configuracion guardada, o null si no hay ninguna (o la base no esta disponible).
     *
     * @return array<string,mixed>|null
     */
    public static function load(): ?array
    {
        try {
            $stmt = Database::connection()->prepare('SELECT value FROM app_settings WHERE `key` = :key');
            $stmt->execute([':key' => self::SETTING_KEY]);
            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $custom
     */
    public static function save(array $custom): void
    {
        $encoded = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RuntimeException('No se pudo serializar la configuración de cámaras.');
        }

        $stmt = Database::connection()->prepare(Database::appSettingUpsertSql());
        $stmt->execute([
            ':key' => self::SETTING_KEY,
            ':value' => $encoded,
            ':updated_at' => date('c'),
        ]);
    }
}
