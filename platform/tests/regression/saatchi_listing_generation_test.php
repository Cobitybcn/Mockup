<?php
declare(strict_types=1);

/**
 * El listing de Saatchi DERIVA de la lectura editorial ya aprobada: una sola voz
 * entre el sitio y el listing (docs/SAATCHI_GENERACION_ESPECIFICACION.md). El
 * modelo reescribe esa lectura en los recipientes de Saatchi; el CODIGO cuenta,
 * compara, valida y decide el estado final.
 *
 * Logica pura, sin red y sin base de produccion.
 */

require_once dirname(__DIR__, 2) . '/app/Services/GeminiImageClient.php';
require_once dirname(__DIR__, 2) . '/app/Support/ArtistReferences.php';
require_once dirname(__DIR__, 2) . '/app/Services/SaatchiListingService.php';

function run_saatchi_listing_generation_tests(): void
{
    TestHarness::group('Saatchi: listing derivado de la lectura aprobada');

    $platformRoot = dirname(__DIR__, 2);

    // ————— El modelo no valida: lo que devuelva como conteo o estado se ignora —————
    $parsed = SaatchiListingService::parseModelOutput([
        'saatchi_title' => ' DECLIVIS · Four presences across an uncertain ground ',
        'saatchi_description' => ' texto ',
        'saatchi_keywords' => ['Burnt Sienna', 'burnt sienna', 'Umber'],
        'validation' => ['status' => 'ok'],
        'character_count' => 999,
    ]);
    TestHarness::assertSame('DECLIVIS · Four presences across an uncertain ground', $parsed['title'], 'el titulo llega limpio de espacios');
    TestHarness::assertSame(['Burnt Sienna', 'Umber'], $parsed['keywords'], 'las keywords duplicadas se deduplican sin distinguir mayusculas');
    TestHarness::assertTrue(
        !array_key_exists('validation', $parsed) && !array_key_exists('character_count', $parsed),
        'el estado y los conteos que declara el modelo no entran al resultado: los decide la aplicacion'
    );

    // ————— La validacion determinista, regla por regla —————
    $titulo = 'DECLIVIS';
    $artista = 'Maurizio Valch';
    $afinidades = ['Barnett Newman', 'Nicolas de Stael'];
    $valido = [
        'title' => 'DECLIVIS · Four halts along a descent',
        'description' => 'Ochre bands settle low against a pale ground, ' . str_repeat('a', 880),
        'keywords' => [
            'Burgundy Tones', 'Fine Incisions', 'Isolated Forms', 'Divided Dark Field',
            'Quiet Solemnity', 'Muted Mystery', 'Distant Presences', 'Restrained Force',
            'Weathered Skin', 'Sloping Marks', 'Barnett Newman', 'Nicolas de Stael',
        ],
    ];
    $validar = static fn (array $cambios): array => SaatchiListingService::validateListing(
        array_merge($valido, $cambios),
        $titulo,
        $artista,
        $afinidades
    );

    TestHarness::assertSame([], $validar([]), 'un paquete que cumple todas las reglas pasa sin errores');

    // Titulo
    TestHarness::assertTrue(
        $validar(['title' => 'DECLIVIS · ' . str_repeat('x', 60)]) !== [],
        'un titulo de mas de 65 caracteres se rechaza: no entra en el formulario'
    );
    TestHarness::assertTrue(
        $validar(['title' => 'DECLIV · Four presences across a ground']) !== [],
        'prohibido abreviar el titulo de la obra para hacerle lugar a la cola'
    );

    // Descripcion
    TestHarness::assertSame(
        [],
        $validar(['description' => 'Ochre bands settle low, ' . str_repeat('a', 780)]),
        'una descripcion de 800 no es un error: el objetivo de 850 avisa, y rellenar para llegar es el vicio que se quiso evitar'
    );
    TestHarness::assertTrue(
        $validar(['description' => 'Ochre bands settle low.']) !== [],
        'una descripcion muy corta si falla: por debajo de 600 no hay estilo, hay generacion incompleta'
    );
    TestHarness::assertTrue(
        $validar(['description' => 'Ochre bands settle low, ' . str_repeat('a', 1100)]) !== [],
        'una descripcion de mas de 1000 falla: es el techo de EDITORIAL_CORE'
    );
    foreach (['This work opens ', 'The field extends ', 'Discover a field '] as $apertura) {
        TestHarness::assertTrue(
            $validar(['description' => $apertura . str_repeat('a', 880)]) !== [],
            "la apertura generica \"{$apertura}\" se rechaza, igual que en el prompt del sistema"
        );
    }

    // Keywords
    $conKeyword = static function (string $reemplazo) use ($valido): array {
        $keywords = $valido['keywords'];
        $keywords[0] = $reemplazo;
        return $keywords;
    };
    TestHarness::assertTrue(
        $validar(['keywords' => $conKeyword('Steep Descent')]) !== [],
        'una keyword que repite una palabra del titulo del listing se rechaza'
    );
    TestHarness::assertTrue(
        $validar(['keywords' => $conKeyword('Contemplative Mindset')]) !== [],
        'una keyword de 21 caracteres se rechaza: el formulario corta en 20'
    );
    TestHarness::assertTrue(
        $validar(['keywords' => array_slice($valido['keywords'], 0, 4)]) !== [],
        'cuatro keywords se rechazan: el formulario pide al menos 5'
    );
    TestHarness::assertSame(
        [],
        $validar(['keywords' => array_slice($valido['keywords'], 0, 9)]),
        'nueve keywords no son un error: 12 es el objetivo y avisa, no bloquea'
    );
    TestHarness::assertSame(
        [],
        $validar(['keywords' => [
            'Umber', 'Sienna', 'Muted Mystery', 'Distant Presences', 'Quiet Solemnity',
            'Fine Incisions', 'Restrained Force', 'Weathered Skin', 'Earthy Tones',
            'Sloping Marks', 'Barnett Newman', 'Nicolas de Stael',
        ]]),
        'no hay cuotas de forma: el reparto lo gobierna el sentido, no la silueta'
    );

    // Afinidades: solo como keyword, y como maximo dos
    TestHarness::assertTrue(
        SaatchiListingService::validateListing(
            array_merge($valido, ['keywords' => array_merge(array_slice($valido['keywords'], 0, 9), ['Newman', 'Rothko', 'Nicolas de Stael'])]),
            $titulo,
            $artista,
            ['Barnett Newman', 'Nicolas de Stael', 'Mark Rothko']
        ) !== [],
        'tres keywords con nombre de artista se rechazan cuando ya hay dos: el maximo por obra es 2'
    );
    TestHarness::assertTrue(
        $validar(['description' => 'Ochre bands recall Barnett Newman across ' . str_repeat('a', 860)]) !== [],
        'la descripcion no puede nombrar a un artista de la lista: la afinidad va solo como keyword'
    );
    TestHarness::assertTrue(
        $validar(['title' => 'DECLIVIS · After Barnett Newman']) !== [],
        'el titulo tampoco puede nombrar al artista de afinidad'
    );

    // ————— Las afinidades: nombre y fundamento, una linea por artista —————
    // El mismo ejemplo se verifica en artist-site/tests/artist_references_test.php.
    $declaradas = "Mark Rothko: los grandes campos cromáticos crean una atmósfera envolvente, mientras que las formas reducidas intensifican el peso emocional del color y el espacio.\n"
        . "Barnett Newman: intervenciones lineales mínimas organizan amplios campos de color, convirtiendo divisiones simples en acontecimientos espaciales y perceptivos.\n"
        . "Nicolas de Staël: bloques compactos de color conservan rastros de paisaje y arquitectura, equilibrando abstracción y tensión espacial reconocible.";

    TestHarness::assertSame(
        ['Mark Rothko', 'Barnett Newman', 'Nicolas de Staël'],
        ArtistReferences::names($declaradas),
        'a un campo de keywords viaja solo el nombre: en Saatchi el tope son 20 caracteres y ningun fundamento entra'
    );
    TestHarness::assertTrue(
        count(ArtistReferences::parse($declaradas)) === 3,
        'el fundamento conserva sus propias comas en vez de partirse por ellas, que es lo que rompia antes'
    );
    TestHarness::assertContains(
        'intervenciones lineales mínimas organizan amplios campos',
        ArtistReferences::forPrompt($declaradas),
        'el fundamento viaja al modelo: es el criterio para decidir a que obra aplica cada afinidad'
    );
    TestHarness::assertSame([], ArtistReferences::names(''), 'sin campo no se nombra a nadie');
    TestHarness::assertTrue(
        !str_contains($servicioFuente = (string)file_get_contents($platformRoot . '/app/Services/SaatchiListingService.php'), "explode(',', (string)(\$profile['reference_artists']"),
        'el servicio ya no parte las afinidades por comas'
    );

    // ————— La normalizacion compara red/reds, line/lines, layer/layered —————
    foreach ([['Reds', 'red'], ['lines', 'line'], ['layered', 'layer'], ['red', 'red']] as [$palabra, $esperado]) {
        TestHarness::assertSame($esperado, SaatchiListingService::normalizeWord($palabra), "\"{$palabra}\" normaliza a \"{$esperado}\"");
    }

    // ————— Los pies: segunda pasada, con las imagenes adjuntas —————
    $parsedPies = SaatchiListingService::parseCaptionOutput([
        'image_captions' => [
            ['file' => 'm1.jpg', 'caption' => ' Detail of the incised lines '],
            ['file' => 'ajena.jpg', 'caption' => 'no es de esta obra'],
        ],
    ], ['m1.jpg', 'm2.jpg']);
    TestHarness::assertSame(['m1.jpg' => 'Detail of the incised lines'], $parsedPies['captions'], 'el pie llega limpio y solo para las imagenes de la obra');
    TestHarness::assertSame(['ajena.jpg'], $parsedPies['unknown'], 'un pie para una imagen ajena se aparta en vez de guardarse');

    $pies = ['a.jpg' => 'Detail of the incised white lines', 'b.jpg' => ''];
    TestHarness::assertSame(
        [],
        SaatchiListingService::validateCaptions($pies, ['a.jpg'], ['b.jpg']),
        'un pie informativo pasa, y la imagen no inspeccionable debe quedar sin pie'
    );
    TestHarness::assertTrue(
        SaatchiListingService::validateCaptions(['a.jpg' => 'Three-quarter view of the artwork'], ['a.jpg']) !== [],
        'un pie que solo describe la posicion de camara se rechaza: no dice que deja inspeccionar'
    );
    TestHarness::assertTrue(
        SaatchiListingService::validateCaptions(['a.jpg' => 'Angled view showing the full painted composition from the right'], ['a.jpg']) !== [],
        'un pie que pasa las 7 palabras o los 50 caracteres se rechaza'
    );
    TestHarness::assertTrue(
        SaatchiListingService::validateCaptions(['a.jpg' => 'Side view of the edge', 'b.jpg' => 'Detail of the weave'], ['a.jpg'], ['b.jpg']) !== [],
        'una imagen que no se pudo inspeccionar no lleva pie: un pie sin mirar es texto inventado'
    );

    // ————— Un paquete que no quedo en ok no se guarda solo —————
    $service = new SaatchiListingService(new PDO('sqlite::memory:'));
    $rechazado = false;
    try {
        $service->save(1, 1, ['title' => 'X', 'description' => '', 'keywords' => [], 'locale' => 'en',
            'validation' => ['status' => 'requires_review']]);
    } catch (RuntimeException) {
        $rechazado = true;
    }
    TestHarness::assertTrue($rechazado, 'save() rechaza un paquete en requires_review: nunca se publica solo');

    $sinIdioma = false;
    try {
        $service->save(1, 1, ['title' => 'X', 'description' => '', 'keywords' => [], 'locale' => '',
            'validation' => ['status' => 'ok']]);
    } catch (RuntimeException) {
        $sinIdioma = true;
    }
    TestHarness::assertTrue(
        $sinIdioma,
        'save() exige el idioma: escribir el listing en ingles dentro de la fila en espanol corrompe el contenido del sitio'
    );

    // ————— Dos pasadas separadas a proposito —————
    $servicio = (string)file_get_contents($platformRoot . '/app/Services/SaatchiListingService.php');
    TestHarness::assertContains(
        'imagePart(',
        $servicio,
        'los pies si adjuntan cada imagen: un pie describe una imagen concreta y no se deriva de un texto'
    );
    TestHarness::assertContains(
        'generateText([$this->client->textPart($prompt)])',
        $servicio,
        'la derivacion del texto viaja sin imagenes: si viajaran, el modelo agregaria a la descripcion hechos visuales que la lectura aprobada no dice'
    );
    // ————— La escritura pasa por el dueño de la tabla, y solo el borrador —————
    TestHarness::assertContains(
        'mergeDerivedFields(',
        $servicio,
        'la escritura pasa por BilingualEditorialService: escribir SQL crudo dejaba status, source_hash e is_published incoherentes'
    );
    // Leer la copia publicada esta bien —es donde vive el texto aprobado—; lo
    // que no puede hacer es escribirla.
    TestHarness::assertTrue(
        !str_contains($servicio, 'published_content_json =') && !str_contains($servicio, 'published_content_json='),
        'el servicio lee la copia publicada pero nunca la escribe: publicar es aprobar, y eso lo hace el artista'
    );
    $duenio = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php');
    TestHarness::assertTrue(
        str_contains($duenio, 'public function mergeDerivedFields')
            && !str_contains(substr($duenio, (int)strpos($duenio, 'public function mergeDerivedFields'), 2200), 'published_content_json'),
        'mergeDerivedFields() no escribe en la copia publicada bajo ninguna circunstancia'
    );

    // ————— El prompt es copia del sistema, no una voz nueva —————
    $reglas = (string)file_get_contents($platformRoot . '/app/Services/saatchi_listing_rules.txt');
    TestHarness::assertContains('{editorial_integrity_rules}', $reglas, 'el prompt compone las reglas de integridad del sistema en vez de reescribirlas');
    TestHarness::assertContains(
        'not analysing the work again',
        $reglas,
        'el prompt declara que no ve la obra: sin eso inventaria detalles visuales que contradicen la pagina publicada'
    );
    TestHarness::assertContains('Synthesize relationships between visual elements instead of', $reglas, 'la regla anti-inventario viaja desde el prompt del sistema');
    foreach (['WHAT THEY SEE', 'WHAT THEY FEEL', 'A UNIVERSE THEY'] as $puerta) {
        TestHarness::assertContains($puerta, $reglas, "las keywords reparten por sentido: puerta {$puerta}");
    }
    TestHarness::assertContains(
        '"saatchi_keywords": []',
        $reglas,
        'el contrato pide el campo que hoy nadie genera y que la proyeccion ya consume'
    );
    TestHarness::assertTrue(
        !str_contains($reglas, 'at least 8 keywords') && !str_contains($reglas, 'at least 6 should contain'),
        'las cuotas de forma salieron del prompt: optimizaban una silueta, no un sentido'
    );

    // ————— El prompt del sistema no se toca —————
    $v2 = (string)file_get_contents($platformRoot . '/app/Support/ArtworkAnalysisV2.php');
    TestHarness::assertContains('"saatchi_title": ""', $v2, 'el contrato del sistema sigue emitiendo sus campos de Saatchi');
    TestHarness::assertTrue(
        !str_contains($v2, 'saatchi_keywords'),
        'el laboratorio todavia no se graduo: si esto cambia, es que saatchi_keywords entro al contrato del sistema y esta pasada debe retirarse'
    );
}
