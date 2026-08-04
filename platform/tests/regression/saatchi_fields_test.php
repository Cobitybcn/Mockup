<?php
declare(strict_types=1);

/**
 * Saatchi Art tiene campos propios con topes DUROS medidos en caracteres.
 * Un texto que los pase no se puede publicar en su formulario, asi que se
 * rechaza al generarlo en vez de descubrirlo al pegar.
 *
 * EDITORIAL_CORE, enmienda 2026-08-01.
 */
function run_saatchi_fields_tests(): void
{
    TestHarness::group('Saatchi: campos propios con topes en caracteres');

    $platformRoot = dirname(__DIR__, 2);

    $base = [
        'analysis_language' => 'es',
        'confirmed_facts' => ['title' => 'STRATA IV — DECLIVIS'],
        'evidence_sources' => [], 'editorial_strategy' => [], 'visual_analysis' => [],
        'interpretation' => [], 'search_metadata' => [], 'originality_check' => [], 'review' => [],
        'canonical_editorial' => [
            'subtitle' => 'x', 'short_description' => 'Campos rojos.', 'master_description' => 'Campos rojos.',
            'artist_vocabulary' => [], 'alt_text' => 'x', 'caption' => 'x',
        ],
    ];
    $validar = static function (array $extra) use ($base): array {
        $data = $base;
        $data['canonical_editorial'] = array_merge($data['canonical_editorial'], $extra);
        return array_values(array_filter(
            ArtworkAnalysisV2::validate($data),
            static fn (string $error): bool => str_contains($error, 'saatchi')
        ));
    };

    // ————— Retrocompatible: el catalogo existente no tiene estos campos —————
    TestHarness::assertSame(
        [],
        $validar([]),
        'una obra anterior a la enmienda sigue siendo valida: exigirlos invalidaria todo el catalogo'
    );

    // ————— Topes duros —————
    TestHarness::assertSame(
        [],
        $validar(['saatchi_title' => 'STRATA IV — DECLIVIS - Large Abstract Textured Painting']),
        'titulo con cola dentro de 65 caracteres: aceptado'
    );
    TestHarness::assertTrue(
        $validar(['saatchi_title' => 'STRATA IV — DECLIVIS - Large Abstract Textured Painting On Canvas Extra']) !== [],
        'un titulo de 71 caracteres se rechaza: no entra en el formulario de Saatchi'
    );
    TestHarness::assertTrue(
        $validar(['saatchi_description' => str_repeat('a', 1200)]) !== [],
        'una descripcion de 1200 caracteres se rechaza'
    );
    TestHarness::assertSame(
        [],
        $validar(['saatchi_description' => str_repeat('a', 1000)]),
        'exactamente 1000 caracteres entra'
    );
    TestHarness::assertTrue(
        $validar(['saatchi_caption' => str_repeat('a', 60)]) !== [],
        'un pie de 60 caracteres se rechaza: el tope es menor a 50'
    );

    // ————— El titulo de la obra es identidad (Libro I) —————
    TestHarness::assertTrue(
        $validar(['saatchi_title' => 'STRATA IV - Large Abstract Painting']) !== [],
        'prohibido abreviar el titulo de la obra para hacerle lugar a la cola SEO'
    );

    // ————— La regla viaja al prompt —————
    $rules = EditorialIntegrityPolicy::promptRules('artwork');
    TestHarness::assertContains('saatchi_title at 65 characters or fewer', $rules, 'el prompt declara el tope del titulo');
    TestHarness::assertContains('saatchi_description at 1000 characters or fewer', $rules, 'el prompt declara el tope de la descripcion');
    TestHarness::assertContains('never a cut of the long description', $rules, 'la descripcion de Saatchi se escribe, no se recorta');
    TestHarness::assertContains('if an honest tail does not fit, the title travels alone', $rules, 'sin cola honesta, el titulo viaja solo');

    // ————— Ninguna descripcion cita medidas —————
    foreach (['artwork', 'mockup', 'studio_note'] as $entityType) {
        TestHarness::assertContains(
            "Never state the artwork's measurements in prose",
            EditorialIntegrityPolicy::promptRules($entityType),
            "la prohibicion de citar medidas aplica tambien a {$entityType}"
        );
    }

    // ————— El producto congelado proyecta los campos propios —————
    $product = (string)file_get_contents($platformRoot . '/app/Services/PublicationProductService.php');
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_caption'] as $campo) {
        TestHarness::assertContains($campo, $product, "el producto congelado lleva {$campo}");
    }
    TestHarness::assertContains(
        "\$propia !== '' ? \$propia :",
        $product,
        'si la obra todavia no tiene descripcion propia se cae al texto del sitio en vez de romper'
    );

    // ————— El paquete descargable informa cuanto ocupa cada campo —————
    $package = (string)file_get_contents($platformRoot . '/publication_saatchi_package.php');
    TestHarness::assertContains('/65', $package, 'el paquete muestra el largo del titulo contra su tope');
    TestHarness::assertContains('/1000', $package, 'y el de la descripcion');
    TestHarness::assertContains('EXCEDE', $package, 'avisa cuando hay que acortar antes de pegar');

    // ————— El artista puede corregirlos a mano —————
    $ficha = (string)file_get_contents($platformRoot . '/artwork.php');
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_caption'] as $campo) {
        TestHarness::assertContains("'key' => '{$campo}'", $ficha, "la ficha permite editar {$campo}");
    }

    // ————— La adaptacion al ingles los lleva —————
    $adapter = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertContains("'saatchi_title' => ''", $adapter, 'el listing en ingles tambien necesita sus campos');
}
