<?php
declare(strict_types=1);

/**
 * FASE 3 - Tests de no-regresion para las zonas protegidas de la auditoria
 * de prompts (docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md).
 *
 * No genera mockups, no llama a Gemini, no escribe en tablas de produccion.
 * Cubre: generacion de obra raiz (prompts + normalizacion pura), obra raiz
 * subida por el usuario (caracterizacion de codigo), y slots/geometria de
 * camara (motor real MockupCombinationEngine::activeCameraSlots()).
 *
 * Uso: php tests/run_regression_tests.php
 * Exit code 0 si todo pasa, 1 si alguna asercion falla.
 */

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/TestHarness.php';
require_once __DIR__ . '/regression/camera_slots_test.php';
require_once __DIR__ . '/regression/root_artwork_test.php';
require_once __DIR__ . '/regression/seo_filename_test.php';
require_once __DIR__ . '/regression/slot_full_prompt_isolation_test.php';
require_once __DIR__ . '/regression/uploaded_root_test.php';
require_once __DIR__ . '/regression/public_pages_test.php';
require_once __DIR__ . '/regression/world_mother_library_admin_test.php';
require_once __DIR__ . '/regression/generation_provider_isolation_test.php';
require_once __DIR__ . '/regression/external_mockup_upload_test.php';

run_camera_slots_regression_tests();
run_root_artwork_regression_tests();
run_seo_filename_regression_tests();
run_slot_full_prompt_isolation_tests();
run_uploaded_root_regression_tests();
run_public_pages_regression_tests();
run_world_mother_library_admin_tests();
run_generation_provider_isolation_tests();
run_external_mockup_upload_regression_tests();

exit(TestHarness::summary());
