<?php
declare(strict_types=1);

/**
 * El paquete de Saatchi se descarga como ZIP escrito a mano: ext-zip no existe
 * en este runtime. Estas aserciones custodian el formato — si el archivo deja
 * de ser un ZIP válido, el artista se entera al intentar abrirlo, no acá.
 */
function run_zip_package_tests(): void
{
    TestHarness::group('Paquete ZIP sin dependencias (descarga para Saatchi)');

    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zip-package-test-' . bin2hex(random_bytes(6)) . '.zip';
    $text = "KEYWORDS\r\nabstract painting, oil on canvas\r\n";
    $binary = str_repeat('BINARY', 2000);

    $zip = new ZipPackage($path, 1767225600);
    $zip->addFromString('saatchi.txt', $text);
    $zip->addFromString('01-obra.jpg', $binary);
    $zip->finish();

    $bytes = (string)file_get_contents($path);
    TestHarness::assertTrue(str_starts_with($bytes, "\x50\x4b\x03\x04"), 'el archivo abre con la firma de un ZIP');
    TestHarness::assertTrue(str_contains($bytes, "\x50\x4b\x01\x02"), 'el ZIP incluye su directorio central');
    TestHarness::assertTrue(str_contains($bytes, "\x50\x4b\x05\x06"), 'el ZIP cierra con el registro final del directorio');
    TestHarness::assertTrue(str_contains($bytes, 'saatchi.txt') && str_contains($bytes, '01-obra.jpg'), 'los nombres de archivo viajan en el paquete');

    // El directorio central declara dos entradas y apunta a un offset dentro del archivo.
    $endOffset = strrpos($bytes, "\x50\x4b\x05\x06");
    TestHarness::assertTrue($endOffset !== false, 'el registro final es localizable');
    $end = unpack('vdisk/vcdDisk/ventries/vtotal/VcdSize/VcdOffset', substr($bytes, (int)$endOffset + 4, 18));
    TestHarness::assertSame(2, (int)$end['total'], 'el directorio central declara las dos entradas');
    TestHarness::assertTrue(
        (int)$end['cdOffset'] > 0 && (int)$end['cdOffset'] + (int)$end['cdSize'] <= strlen($bytes),
        'el directorio central cae dentro del archivo'
    );

    // Las entradas se guardan sin comprimir (las imágenes ya vienen comprimidas):
    // tamaño declarado == tamaño real, y el CRC corresponde al contenido.
    $local = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen', substr($bytes, 4, 26));
    TestHarness::assertSame(0, (int)$local['method'], 'las entradas se almacenan sin comprimir');
    TestHarness::assertSame(strlen($text), (int)$local['size'], 'el tamaño declarado es el real');
    TestHarness::assertSame(crc32($text), (int)$local['crc'], 'el CRC corresponde al contenido');

    @unlink($path);
}
