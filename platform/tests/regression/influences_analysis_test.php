<?php
declare(strict_types=1);

/**
 * "Análisis Según Influencias del Artista": prosa por obra y por mockup que
 * dice cuáles de las afinidades DECLARADAS sostiene esa pieza concreta.
 *
 * Las reglas de sangre:
 *   - La lista declarada es cerrada: sin lista no hay campo, y un texto que no
 *     nombra a ninguno de los declarados no está anclado y se descarta.
 *   - Vacío es un resultado VÁLIDO: la selección es la regla. Una obra puede
 *     sostener una afinidad, dos, varias o ninguna.
 *   - El campo es OPCIONAL: no entra en los required paths y nada existente
 *     pasa a "pendiente" por no tenerlo.
 *   - Las referencias del perfil son insumo de ESTE campo y de ningún otro.
 *   - Lo que el artista escribió a mano no se pisa jamás.
 *
 * Logica pura, sin red.
 */

require_once dirname(__DIR__, 2) . '/app/Services/GeminiImageClient.php';
require_once dirname(__DIR__, 2) . '/app/Support/ArtistReferences.php';
require_once dirname(__DIR__, 2) . '/app/Services/InfluencesAnalysisService.php';

function run_influences_analysis_tests(): void
{
    TestHarness::group('Analisis segun influencias del artista');

    $platformRoot = dirname(__DIR__, 2);
    $declarados = ['Mark Rothko', 'Barnett Newman', 'Richard Diebenkorn'];

    // ————— Vacio es un resultado valido —————
    TestHarness::assertSame(
        [],
        InfluencesAnalysisService::validate('', $declarados),
        'un analisis vacio pasa siempre: la seleccion es la regla y ninguna afinidad sostenida es una respuesta correcta'
    );
    TestHarness::assertSame(
        [],
        InfluencesAnalysisService::validate('', []),
        'sin afinidades declaradas y sin texto no hay nada que objetar'
    );

    // ————— La lista declarada es el ancla —————
    TestHarness::assertSame(
        [],
        InfluencesAnalysisService::validate(
            'Mark Rothko\n\nLos campos sumergidos de esta obra concentran el peso emocional en la transición cromática.',
            $declarados
        ),
        'un analisis que nombra a un artista declarado y dice que hace ESTA obra con esa afinidad pasa'
    );
    TestHarness::assertTrue(
        InfluencesAnalysisService::validate('Un texto que habla de atmosferas sin nombrar a nadie.', $declarados) !== [],
        'un analisis que no nombra a ninguno de los declarados no esta anclado y se descarta'
    );
    TestHarness::assertTrue(
        InfluencesAnalysisService::validate('Esta obra dialoga con la tradicion del expresionismo.', []) !== [],
        'con texto pero sin lista declarada no hay ancla posible: el generador no nombra a nadie que el artista no haya nombrado primero'
    );
    TestHarness::assertTrue(
        InfluencesAnalysisService::validate(
            'Mark Rothko ' . str_repeat('a', InfluencesAnalysisService::TEXT_MAX + 10),
            $declarados
        ) !== [],
        'un analisis que pasa el tope de caracteres se descarta: el campo es prosa breve, no un ensayo'
    );

    // ————— Que nombres aparecen, sin falsos positivos por subcadena —————
    TestHarness::assertSame(
        ['Mark Rothko', 'Richard Diebenkorn'],
        InfluencesAnalysisService::namedArtists(
            'Mark Rothko organiza el campo; Richard Diebenkorn aporta el horizonte.',
            $declarados
        ),
        'detecta exactamente los declarados que el texto nombra'
    );
    TestHarness::assertSame(
        [],
        InfluencesAnalysisService::namedArtists('El rothkiano no cuenta como nombre.', ['Rothko']),
        'una subcadena dentro de otra palabra no es un nombre'
    );

    // ————— El modelo no se autovalida —————
    TestHarness::assertSame(
        'Texto limpio',
        InfluencesAnalysisService::parseModelOutput(['influences_analysis' => '  Texto limpio  ', 'status' => 'ok']),
        'el texto llega limpio y el estado que declare el modelo se ignora'
    );

    // ————— El campo es OPCIONAL: nada existente pasa a pendiente —————
    $paquete = (string)file_get_contents($platformRoot . '/app/Services/ArtworkEditorialPackageService.php');
    TestHarness::assertTrue(
        !str_contains($paquete, 'influences_analysis'),
        'influences_analysis no esta en los required paths del paquete: ninguna obra ni mockup existente se vuelve pendiente por no tenerlo'
    );
    $adaptador = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertTrue(
        !str_contains(
            substr($adaptador, (int)strpos($adaptador, 'private function mockupContentIssues'), 1600),
            'influences_analysis'
        ),
        'tampoco esta entre los campos obligatorios del mockup: un mockup sin analisis sigue estando completo'
    );

    // ————— Las referencias son insumo de ESTE campo y de ningun otro —————
    TestHarness::assertContains(
        'input ONLY for influences_analysis',
        $adaptador,
        'el bloque de afinidades del prompt declara su unico destino: el resto de la lectura sigue sin ver las referencias'
    );
    TestHarness::assertContains(
        'never name those artists in any other field',
        $adaptador,
        'la regla prohibe que los nombres declarados se derramen a otros campos: un nombre en una descripcion es validacion prestada'
    );
    TestHarness::assertContains(
        'private function constrainInfluencesAnalysis',
        $adaptador,
        'la ley del campo es deterministica y vive en codigo, no solo en el prompt'
    );

    // ————— Derivado: regenerarlo no dispara cascadas —————
    $servicio = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php');
    TestHarness::assertContains(
        "'influences_analysis'",
        substr($servicio, (int)strpos($servicio, 'public const DERIVED_FIELDS'), 700),
        'influences_analysis es campo derivado: cambiarlo no marca la lectura como cambiada ni regenera mockups'
    );

    // ————— El paso de canal lo deriva por idioma, sin pisar lo editado —————
    $worker = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertContains(
        'deriveIfEmpty',
        $worker,
        'el paso de canal deriva las influencias de la obra por idioma'
    );
    $servicioInfluencias = (string)file_get_contents($platformRoot . '/app/Services/InfluencesAnalysisService.php');
    TestHarness::assertContains(
        'conservadas (el campo ya tiene texto)',
        $servicioInfluencias,
        'lo que el artista escribio o edito a mano no se pisa jamas'
    );
    TestHarness::assertContains(
        'mergeDerivedFields',
        $servicioInfluencias,
        'la escritura pasa por el dueno de la tabla y toca solo el borrador'
    );

    // ————— La ficha lo muestra debajo de la descripcion, en obra y mockup —————
    $fichaObra = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertContains('Análisis Según Influencias del Artista', $fichaObra, 'la ficha de la obra muestra el campo con su titulo exacto');
    $fichaMockup = (string)file_get_contents($platformRoot . '/mockup_bilingual_experiment.php');
    TestHarness::assertContains('Análisis Según Influencias del Artista', $fichaMockup, 'la ficha del mockup muestra el mismo campo');
    foreach ([$fichaObra, $fichaMockup] as $ficha) {
        $posDescripcion = (int)strpos($ficha, "'description'");
        $posInfluencias = (int)strpos($ficha, "'influences_analysis'");
        TestHarness::assertTrue(
            $posInfluencias > $posDescripcion && $posInfluencias > 0,
            'el campo va debajo de la descripcion, como lo pidio el artista'
        );
    }

    // ————— La grilla del mockup sigue a la lista de campos —————
    // Congelar el numero de filas fue el defecto del 2026-08-04: 9 filas para
    // 12 campos encimaban el espanol con el ingles. La regla es dinamica.
    TestHarness::assertContains(
        'repeat(<?= count($editorialFields) ?>,auto)',
        $fichaMockup,
        'las filas de la grilla del mockup salen de la lista de campos, no de un numero congelado'
    );
    TestHarness::assertContains(
        'span <?= count($editorialFields) + 1 ?>',
        $fichaMockup,
        'y el alto de cada pagina tambien: agregar un campo no puede volver a encimar los idiomas'
    );

    // ————— Las reglas del prompt existen y dicen lo esencial —————
    $reglas = (string)file_get_contents($platformRoot . '/app/Services/influences_analysis_rules.txt');
    TestHarness::assertContains('Selection is the rule', $reglas, 'la seleccion es la regla, no la obligacion');
    TestHarness::assertContains('Empty is a valid, correct result', $reglas, 'vacio es un resultado valido y el prompt lo dice');
    TestHarness::assertContains('NEVER name an artist who is not in the declared list', $reglas, 'la lista cerrada esta en el contrato del modelo');
    TestHarness::assertContains('never external validation', $reglas, 'la voz es el linaje declarado del artista, nunca validacion externa');
}
