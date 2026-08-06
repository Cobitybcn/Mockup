<?php
declare(strict_types=1);

/**
 * La configuracion de Camera Boards se guarda en la base, nunca en el filesystem.
 *
 * Historia: hasta el 2026-08-03 se escribia como archivo PHP dentro del contenedor.
 * En Cloud Run ese disco es efimero: cada despliegue revertia las camaras a la
 * version commiteada, sin error ni aviso. Este test impide volver a ese diseño.
 */
function run_camera_slots_persistence_tests(): void
{
    TestHarness::group('Camera Boards: persistencia en base, no en disco');

    $platformRoot = dirname(__DIR__, 2);
    $studioSource = (string)file_get_contents($platformRoot . '/app/Services/CameraSlotStudio.php');
    $configSource = (string)file_get_contents($platformRoot . '/app/Config/mockup_camera_slots.php');

    TestHarness::assertTrue(
        !str_contains($studioSource, 'file_put_contents($this->customConfigPath()'),
        'Camera Boards no escribe la configuracion al filesystem: el disco del contenedor es efimero'
    );
    TestHarness::assertContains(
        'CameraSlotsStore::save',
        $studioSource,
        'Camera Boards guarda en app_settings, junto al resto de la configuracion de runtime'
    );
    TestHarness::assertContains(
        'CameraSlotsStore::load',
        $studioSource,
        'la lectura previa al guardado sale de la base: guardar es leer-modificar-escribir y leer del archivo perderia las ediciones anteriores'
    );
    TestHarness::assertContains(
        'CameraSlotsStore::load',
        $configSource,
        'el config que consume el generador de mockups lee lo guardado en la base'
    );

    // El archivo versionado sigue siendo la semilla valida cuando la base esta vacia.
    TestHarness::assertTrue(
        is_file($platformRoot . '/app/Config/mockup_camera_slots_custom.php'),
        'el archivo custom permanece como semilla versionada para bases sin configuracion'
    );
    TestHarness::assertContains(
        '$customCameraSlotsPath',
        $configSource,
        'el config conserva el fallback al archivo semilla'
    );

    $storePath = $platformRoot . '/app/Support/CameraSlotsStore.php';
    TestHarness::assertTrue(is_file($storePath), 'CameraSlotsStore existe');
    $storeSource = (string)file_get_contents($storePath);
    TestHarness::assertContains(
        'mockup_camera_slots_custom',
        $storeSource,
        'la clave de app_settings esta declarada en el store'
    );
    TestHarness::assertContains(
        'appSettingUpsertSql',
        $storeSource,
        'el guardado usa el upsert estandar de app_settings'
    );
    TestHarness::assertContains(
        'catch (Throwable)',
        $storeSource,
        'si la base no esta disponible el store degrada a null y el config cae a la semilla, en vez de tumbar el request'
    );

    $migrationPath = $platformRoot . '/migrations/schema/20260806_000001_seed_camera_slots_custom.php';
    TestHarness::assertTrue(is_file($migrationPath), 'existe la migracion inmutable de sembrado de slots de camara');
    $migrationContent = (string)file_get_contents($migrationPath);
    TestHarness::assertContains('mockup_camera_slots_custom', $migrationContent, 'la migracion de sembrado referencia la clave correcta');
}

