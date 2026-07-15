<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/ExternalMockupUploadService.php';

function run_external_mockup_upload_regression_tests(): void
{
    TestHarness::group('Importación de mockups externos');

    TestHarness::assertSame(
        ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        ExternalMockupUploadService::allowedMimeTypes(),
        'la whitelist acepta únicamente JPG, PNG y WebP'
    );
    TestHarness::assertSame(
        'folder/mockup.jpg',
        ExternalMockupUploadService::normalizeRelativePath('../folder/./mockup.jpg'),
        'las rutas de carpetas se conservan sin segmentos de traversal'
    );

    $temporaryImage = tempnam(sys_get_temp_dir(), 'external-mockup-test-');
    file_put_contents(
        $temporaryImage,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
    );
    try {
        $image = ExternalMockupUploadService::inspectImage($temporaryImage);
        TestHarness::assertSame('image/png', $image['mime'], 'la inspección reconoce el MIME real de una imagen');
        TestHarness::assertSame(1, $image['width'], 'la inspección obtiene el ancho real');
        TestHarness::assertSame(1, $image['height'], 'la inspección obtiene el alto real');
    } finally {
        @unlink($temporaryImage);
    }

    $serviceSource = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/ExternalMockupUploadService.php');
    TestHarness::assertContains("AND user_id = :user_id AND status = :status", $serviceSource, 'la obra se valida contra el usuario autenticado');
    TestHarness::assertContains("'generation_source' => 'external_upload'", $serviceSource, 'la importación queda distinguida de la generación IA');
    TestHarness::assertContains('StorageService::uploadFile', $serviceSource, 'el mockup se replica al almacenamiento remoto cuando está activo');
    TestHarness::assertContains('source_artwork_id', $serviceSource, 'cada mockup queda vinculado por id a la obra');

    $pageSource = (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_upload.php');
    $scriptSource = (string)file_get_contents(dirname(__DIR__, 2) . '/mockup_upload.js');
    TestHarness::assertContains('webkitdirectory', $pageSource, 'el tablero permite elegir una carpeta completa');
    TestHarness::assertContains('data-remove-file', $scriptSource, 'las miniaturas se pueden quitar antes de guardar');
    TestHarness::assertContains('window.Sortable.create', $scriptSource, 'las miniaturas se pueden reordenar por drag and drop');
    TestHarness::assertContains('webkitGetAsEntry', $scriptSource, 'el dropzone recorre carpetas soltadas desde el ordenador');
}
