<?php
declare(strict_types=1);

/**
 * Las medidas viven en su campo, no en la prosa: citarlas la duplica y la deja
 * vencida al primer cambio. Este test fija como se quitan de los textos ya
 * generados, sin regenerar analisis.
 *
 * Los casos salen de textos reales medidos en produccion el 2026-08-04.
 */
function run_measurement_citations_tests(): void
{
    TestHarness::group('Citas de medidas: se quitan, no se reemplazan');

    // ————— Patron 1: segmento entre coma y punto (el mas frecuente) —————
    $caso = MeasurementCitations::strip('Maurizio Valch, CLIVUS. Acrylic and oil on canvas, 80 x 130 cm. From the SITUS series, this painting explores balance.');
    TestHarness::assertSame(
        'Maurizio Valch, CLIVUS. Acrylic and oil on canvas. From the SITUS series, this painting explores balance.',
        $caso['text'],
        'la medida se va entera y la tecnica queda intacta'
    );
    TestHarness::assertSame(', 80 x 130 cm', $caso['removed'][0] ?? '', 'se informa exactamente que se quito');

    // ————— Patron 2: adjetivo dentro de la frase —————
    $caso = MeasurementCitations::strip('The upper left edge of the 3 cm deep canvas is visible, showing crimson red paint.');
    TestHarness::assertSame(
        'The upper left edge of the canvas is visible, showing crimson red paint.',
        $caso['text'],
        'la frase sigue corriendo sin la medida'
    );

    // ————— Patron 3: medida entre comas, sin punto de cierre —————
    $caso = MeasurementCitations::strip('Vertical abstract painting, 80 x 120 cm, with a dark green background.');
    TestHarness::assertSame(
        'Vertical abstract painting, with a dark green background.',
        $caso['text'],
        'no queda coma doble ni espacio de mas'
    );

    // ————— Tres dimensiones y castellano —————
    TestHarness::assertSame(
        'Oil and acrylic on canvas. A side view showing the stretcher.',
        MeasurementCitations::strip('Oil and acrylic on canvas, 120 x 160 x 3 cm. A side view showing the stretcher.')['text'],
        'ancho por alto por profundidad tambien se va entero'
    );
    TestHarness::assertSame(
        'Pintura vertical. De la serie SITUS.',
        MeasurementCitations::strip('Pintura vertical, 80 x 120 cm. De la serie SITUS.')['text'],
        'el castellano se trata igual'
    );

    // ————— Lo que NO se toca —————
    $intacto = 'Sin ninguna medida en este texto, solo prosa.';
    $caso = MeasurementCitations::strip($intacto);
    TestHarness::assertSame($intacto, $caso['text'], 'un texto sin medidas vuelve identico');
    TestHarness::assertSame([], $caso['removed'], 'y no reporta nada quitado');

    // El titulo de la obra es identidad: un numero romano no es una medida.
    $titulo = 'STRATA IV — DECLIVIS. Acrylic on canvas from the STRATA series.';
    TestHarness::assertSame($titulo, MeasurementCitations::strip($titulo)['text'], 'no confunde numeracion de serie con medidas');

    // ————— Lo que no supo resolver se reporta, no se inventa —————
    TestHarness::assertTrue(
        MeasurementCitations::leftovers('Acrylic on canvas. From the SITUS series.') === [],
        'un texto ya limpio no deja pendientes'
    );
    TestHarness::assertTrue(
        MeasurementCitations::leftovers('The work measures around 80 cm across its diagonal') !== [],
        'una cita que no calza con ningun patron se reporta para decidir a mano'
    );

    // ————— El script de limpieza cubre las tres capas —————
    // Limpiar solo las fichas las deja volver: al republicar una obra, la memoria
    // editorial las repuebla.
    $script = (string)file_get_contents(dirname(__DIR__, 2) . '/scripts/strip_measurement_citations.php');
    foreach (['artwork_sheets', 'mockup_sheets', 'bilingual_editorial_content'] as $tabla) {
        TestHarness::assertContains($tabla, $script, "el script limpia {$tabla}");
    }
    TestHarness::assertContains('--apply', $script, 'en seco por defecto: escribir es explicito');
    TestHarness::assertContains('measurement-citations-backup-', $script, 'guarda el original antes de escribir');
    TestHarness::assertContains('beginTransaction', $script, 'todo o nada: un fallo a mitad no deja textos a medio corregir');
}
