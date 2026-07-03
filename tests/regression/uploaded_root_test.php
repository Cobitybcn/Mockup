<?php
declare(strict_types=1);

/**
 * Zona protegida: "Flujo de obra raiz provista por el usuario"
 * (ver docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md, seccion Zonas protegidas).
 *
 * upload_existing_root.php es un script procedural que ejecuta codigo real
 * (Auth::requireUser(), validacion de metodo POST, insert en BD) apenas se
 * hace `require`. No se puede requerir de forma segura desde un test sin
 * simular una subida real con sesion autenticada, asi que esto es un test
 * de CARACTERIZACION por inspeccion de codigo fuente: verifica que las
 * invariantes criticas sigan presentes textualmente en el archivo, no las
 * ejecuta. Si algun dia se refactoriza la logica a una clase testeable,
 * reemplazar esto por tests reales via Reflection como en root_artwork_test.php.
 */
function run_uploaded_root_regression_tests(): void
{
    TestHarness::group('Obra raiz subida: caracterizacion por inspeccion de codigo (upload_existing_root.php)');

    $path = dirname(__DIR__, 2) . '/upload_existing_root.php';
    TestHarness::assertTrue(is_file($path), 'upload_existing_root.php existe');
    $source = (string)file_get_contents($path);

    TestHarness::assertContains(
        "in_array(\$ext, ['jpg', 'jpeg', 'png', 'webp'], true)",
        $source,
        'la whitelist de extensiones sigue siendo exactamente jpg/jpeg/png/webp'
    );

    TestHarness::assertContains(
        "if (\$width === '' || \$height === '')",
        $source,
        'width y height siguen siendo obligatorios'
    );

    TestHarness::assertContains(
        'INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)',
        $source,
        'el INSERT a artworks conserva las columnas esperadas'
    );

    TestHarness::assertContains(
        "'root_source' => 'uploaded_final'",
        $source,
        "status.json marca root_source='uploaded_final'"
    );
    TestHarness::assertContains(
        "'generation_skipped' => true",
        $source,
        "status.json marca generation_skipped=true (contrato: no se debe regenerar la obra raiz subida)"
    );

    TestHarness::assertContains(
        "header('Location: mockup_combinations_review.php?id=' . \$artworkId . '&world_mother_category=selected');",
        $source,
        'el flujo redirige a mockup_combinations_review.php tras subir la obra raiz'
    );

    TestHarness::assertContains(
        'Auth::requireUser()',
        $source,
        'la subida sigue requiriendo un usuario autenticado'
    );
}
