<?php
declare(strict_types=1);

/**
 * El vocabulario de descubrimiento por idioma: se DERIVA de la lectura aprobada
 * en ese idioma, nunca se traduce. Una keyword traducida deja de ser una
 * busqueda: nadie tipea la version literal de una frase pensada en otro idioma.
 *
 * Logica pura, sin red.
 */

require_once dirname(__DIR__, 2) . '/app/Services/GeminiImageClient.php';
require_once dirname(__DIR__, 2) . '/app/Support/ArtistReferences.php';
require_once dirname(__DIR__, 2) . '/app/Services/DiscoveryKeywordsService.php';

function run_discovery_keywords_tests(): void
{
    TestHarness::group('Vocabulario de descubrimiento por idioma');

    $platformRoot = dirname(__DIR__, 2);

    // ————— El modelo no se autovalida —————
    $parsed = DiscoveryKeywordsService::parseModelOutput([
        'keywords' => [' campo de color rojo ', 'Campo de Color Rojo', 'líneas blancas finas'],
        'keyword_count' => 99,
    ]);
    TestHarness::assertSame(
        ['campo de color rojo', 'líneas blancas finas'],
        $parsed,
        'las keywords llegan limpias y deduplicadas sin distinguir mayusculas'
    );

    // ————— El lenguaje de venta no entra, en ninguno de los dos idiomas —————
    foreach (['Buy original art', 'Art for modern collectors', 'Comprar cuadro abstracto', 'Arte para coleccionistas', 'pintura en venta'] as $venta) {
        TestHarness::assertTrue(
            DiscoveryKeywordsService::isSalesLanguage($venta),
            "\"{$venta}\" es lenguaje de venta: describe a quien vendersela, no que es la obra"
        );
    }
    foreach (['campo de color rojo profundo', 'atmósfera contemplativa', 'Deep Red Field', 'abstracción matérica'] as $descriptivo) {
        TestHarness::assertTrue(
            !DiscoveryKeywordsService::isSalesLanguage($descriptivo),
            "\"{$descriptivo}\" describe la obra y sobrevive"
        );
    }

    // ————— La validacion determinista —————
    $titulo = 'DECLIVIS';
    $artista = 'Maurizio Valch';
    $afinidades = ['Mark Rothko', 'Barnett Newman'];
    $validas = [
        'campo de color rojo profundo', 'líneas blancas finas', 'bloques de rojo carmesí',
        'abstracción matérica', 'composición vertical', 'atmósfera contemplativa',
        'reflexión silenciosa', 'tensión entre quietud y movimiento', 'textura de suelo primigenio',
        'Mark Rothko', 'Barnett Newman', 'gravedad serena',
    ];
    TestHarness::assertSame(
        [],
        DiscoveryKeywordsService::validate($validas, $titulo, $artista, $afinidades),
        'un conjunto en castellano con dos afinidades pasa entero'
    );
    TestHarness::assertTrue(
        DiscoveryKeywordsService::validate(array_slice($validas, 0, 3), $titulo, $artista, $afinidades) !== [],
        'tres keywords no alcanzan'
    );
    TestHarness::assertTrue(
        DiscoveryKeywordsService::validate(
            array_merge(array_slice($validas, 0, 9), ['Mark Rothko', 'Barnett Newman', 'Paul Klee']),
            $titulo,
            $artista,
            ['Mark Rothko', 'Barnett Newman', 'Paul Klee']
        ) !== [],
        'tres nombres de artista se rechazan: el maximo por obra es dos'
    );
    TestHarness::assertTrue(
        DiscoveryKeywordsService::validate(
            array_merge(array_slice($validas, 0, 11), ['pintura de Maurizio Valch']),
            $titulo,
            $artista,
            $afinidades
        ) !== [],
        'una keyword que repite el nombre del artista se rechaza: ya viaja en creator'
    );
    TestHarness::assertTrue(
        DiscoveryKeywordsService::validate(
            array_merge(array_slice($validas, 0, 11), ['comprar arte contemporáneo']),
            $titulo,
            $artista,
            $afinidades
        ) !== [],
        'una keyword de venta se rechaza aunque el resto este bien'
    );
    TestHarness::assertTrue(
        DiscoveryKeywordsService::validate(
            array_merge(array_slice($validas, 0, 11), [str_repeat('a', 45)]),
            $titulo,
            $artista,
            $afinidades
        ) !== [],
        'una frase de 45 caracteres es demasiado larga para una busqueda'
    );

    // ————— Deriva, no traduce —————
    $reglas = (string)file_get_contents($platformRoot . '/app/Services/discovery_keywords_rules.txt');
    TestHarness::assertContains(
        'You are NOT translating an existing keyword list',
        $reglas,
        'el prompt prohibe traducir: una keyword traducida deja de ser una busqueda'
    );
    TestHarness::assertContains(
        'never translate or localise a proper name',
        $reglas,
        'los nombres de artista se escriben igual en todos los idiomas'
    );
    TestHarness::assertContains(
        'TARGET LANGUAGE',
        $reglas,
        'el idioma de destino es explicito'
    );

    $servicio = (string)file_get_contents($platformRoot . '/app/Services/DiscoveryKeywordsService.php');
    TestHarness::assertContains(
        "AND locale = ? LIMIT 1",
        $servicio,
        'cada idioma se lee de su propia fila'
    );
    TestHarness::assertContains(
        "'discovery_keywords' => implode(', ', \$result['keywords'])",
        $servicio,
        'la escritura es dirigida: toca discovery_keywords y nada mas'
    );
    TestHarness::assertContains(
        'mergeDerivedFields(',
        $servicio,
        'la escritura pasa por el dueño de la tabla, no por SQL crudo'
    );
    // Leerla esta bien: ahi vive el texto aprobado del que se deriva. Escribirla, no.
    TestHarness::assertTrue(
        !str_contains($servicio, 'published_content_json =') && !str_contains($servicio, 'published_content_json='),
        'lee la copia publicada pero nunca la escribe: al sitio no llega nada sin que el artista lo apruebe'
    );

    // ————— Y los campos derivados sobreviven a una regeneracion —————
    $adaptador = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    foreach (['saatchi_keywords', 'discovery_keywords'] as $campo) {
        TestHarness::assertContains(
            "'{$campo}' => ''",
            $adaptador,
            "{$campo} esta declarado en la adaptacion: sin eso, la primera regeneracion de una obra lo borraria"
        );
    }

    // ————— Un resultado que no quedo en ok no se guarda —————
    $service = new DiscoveryKeywordsService(new PDO('sqlite::memory:'));
    $rechazado = false;
    try {
        $service->save(1, 1, ['keywords' => [], 'locale' => 'es', 'validation' => ['status' => 'requires_review']]);
    } catch (RuntimeException) {
        $rechazado = true;
    }
    TestHarness::assertTrue($rechazado, 'un vocabulario en requires_review no se publica solo');

    // ————— El sitio lo lee, y cae al ingles del listing si no hay propio —————
    $catalogo = (string)file_get_contents(dirname($platformRoot) . '/artist-site/inc/AppPublishedCatalog.php');
    TestHarness::assertContains(
        "\$localized['discovery_keywords'] ?? \$localized['saatchi_keywords'] ?? ''",
        $catalogo,
        'el sitio prefiere el vocabulario propio del idioma y cae al del listing cuando no existe'
    );
}
