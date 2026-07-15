<?php
declare(strict_types=1);

function run_seo_filename_regression_tests(): void
{
    TestHarness::group('SEO filename: camara primero sin artista');

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mockups_seo_filename_test_' . getmypid();
    if (!is_dir($tmpDir)) {
        mkdir($tmpDir, 0775, true);
    }

    $filename = Display::generateSeoImageFilename([
        'artistName' => 'Maurizio Valch',
        'artworkTitle' => 'root_art_uploaded_uploaded_root_17830143148857_v1',
        'mockupContext' => 'Loft / Diagonal Moderna de Estudio',
        'cameraAngle' => 'diagonal_estudio_moderno',
        'cameraSlotName' => 'Diagonal Moderna de Estudio',
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ], $tmpDir);

    TestHarness::assertSame(
        'diagonal-moderna-de-estudio-loft-rootartuploadeduploadedroot17830143148857v1-mockup.jpg',
        $filename,
        'el nombre empieza con la camara, conserva contexto/obra y omite el artista'
    );
    TestHarness::assertTrue(!str_contains($filename, 'maurizio') && !str_contains($filename, 'valch'), 'el artista no aparece en el filename');

    $fallbackFilename = Display::generateSeoImageFilename([
        'artistName' => 'Maurizio Valch',
        'artworkTitle' => 'root_art_uploaded_uploaded_root_17830143148857_v1',
        'mockupContext' => 'Loft',
        'cameraAngle' => 'contrapicado_7_8',
        'imageType' => 'mockup',
        'extension' => 'jpg',
    ], $tmpDir);

    TestHarness::assertSame(
        'contrapicado-7-8-loft-rootartuploadeduploadedroot17830143148857v1-mockup.jpg',
        $fallbackFilename,
        'si no hay nombre legible, el id del slot se usa al inicio con guiones'
    );
}
