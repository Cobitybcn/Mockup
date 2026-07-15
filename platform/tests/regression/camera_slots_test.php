<?php
declare(strict_types=1);

/**
 * Zona protegida: "Slots de camara" + "Geometria base de las camaras"
 * (ver docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, seccion Zonas protegidas).
 *
 * Llama a MockupCombinationEngine::activeCameraSlots() real (el mismo metodo
 * que usa mockup_combinations_review.php) para obtener la geometria YA
 * compuesta (camera_height_block + lens_block + ... + negativos), en vez de
 * reimplementar esa composicion. No escribe en la base de datos.
 */
function run_camera_slots_regression_tests(): void
{
    TestHarness::group('Camera slots: config e integridad');

    $configPath = dirname(__DIR__, 2) . '/app/Config/mockup_camera_slots.php';
    TestHarness::assertTrue(is_file($configPath), 'mockup_camera_slots.php existe');
    $config = require $configPath;

    $slots = $config['slots'] ?? [];
    TestHarness::assertTrue(is_array($slots) && count($slots) > 0, 'la clave "slots" no esta vacia');

    // Cada slot referenciado en "sets" debe existir en el catalogo top-level "slots".
    $missingFromSets = [];
    foreach (($config['sets'] ?? []) as $setId => $set) {
        foreach (($set['slots'] ?? []) as $slotId) {
            if (!isset($slots[$slotId])) {
                $missingFromSets[] = "{$setId} -> {$slotId}";
            }
        }
    }
    TestHarness::assertTrue(
        $missingFromSets === [],
        'todos los slot_id referenciados en "sets" existen en "slots" (huerfanos: ' . implode(', ', $missingFromSets) . ')'
    );

    $requiredKeys = [
        'slot_id', 'slot_name', 'enabled', 'orientation_supported',
        'camera_height_block', 'lens_block', 'vertical_tilt_block',
        'lateral_rotation_block', 'composition_block', 'scale_block',
        'depth_of_field_block', 'negative_directives',
    ];
    foreach ($slots as $slotId => $slot) {
        $missing = [];
        foreach ($requiredKeys as $key) {
            $val = $slot[$key] ?? null;
            $empty = $key === 'enabled'
                ? !array_key_exists($key, $slot)
                : (is_string($val) ? trim($val) === '' : empty($val));
            if ($empty) {
                $missing[] = $key;
            }
        }
        TestHarness::assertTrue(
            $missing === [],
            "slot '{$slotId}' tiene todos los campos obligatorios (faltan: " . implode(', ', $missing) . ')'
        );
        TestHarness::assertSame($slotId, (string)($slot['slot_id'] ?? ''), "slot '{$slotId}': la clave del array coincide con slot_id interno");
    }

    TestHarness::group('Camera slots: activeCameraSlots() real (motor de MockupCombinationEngine)');

    $engine = new MockupCombinationEngine(Database::connection());
    $active = $engine->activeCameraSlots();

    TestHarness::assertSame(14, count($active), 'hay exactamente 14 camera slots activos (linea base 2026-07-02 sin Camera 15, combo 17 ni 1NV)');

    $missingGeometry = [];
    foreach ($active as $slotId => $slot) {
        if (trim((string)($slot['camera_slot_geometry'] ?? '')) === '') {
            $missingGeometry[] = $slotId;
        }
    }
    TestHarness::assertTrue(
        $missingGeometry === [],
        'todos los slots activos tienen camera_slot_geometry compuesta y no vacia (vacios: ' . implode(', ', $missingGeometry) . ')'
    );

    $snapshotData = [];
    foreach ($active as $slotId => $slot) {
        $snapshotData[$slotId] = [
            'slot_name' => $slot['slot_name'],
            'enabled' => (bool)($slot['enabled'] ?? false),
            'camera_slot_geometry' => $slot['camera_slot_geometry'],
        ];
    }
    ksort($snapshotData);
    TestHarness::snapshot(
        'camera_slots_snapshot.json',
        $snapshotData,
        'nombres + geometria base de los 14 camera slots activos no cambiaron sin querer'
    );
}
