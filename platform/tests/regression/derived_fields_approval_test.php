<?php
declare(strict_types=1);

/**
 * Aprobar los campos derivados es un acto CHICO, y publicar una obra es un acto
 * GRANDE. La diferencia importa y por eso esta escrita como test.
 *
 * "Publicar Obra" publica la pagina, aprueba la lectura y regenera el texto de
 * todos los mockups de la obra — una llamada al modelo por cada uno. Revisar
 * unas keywords no puede costar eso: los campos derivados se calculan a partir
 * de la lectura y no la cambian, asi que se aprueban solos.
 */
function run_derived_fields_approval_tests(): void
{
    TestHarness::group('Campos derivados: aprobacion propia, sin cascada');

    $platformRoot = dirname(__DIR__, 2);
    $servicio = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php');
    $publicacion = (string)file_get_contents($platformRoot . '/publication.php');

    // ————— Que campos son derivados —————
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_caption', 'saatchi_keywords', 'discovery_keywords'] as $campo) {
        TestHarness::assertContains(
            "'{$campo}'",
            substr($servicio, (int)strpos($servicio, 'public const DERIVED_FIELDS'), 400),
            "{$campo} esta declarado como campo derivado"
        );
    }

    // ————— La aprobacion no arrastra la republicacion —————
    $aprobacion = substr($servicio, (int)strpos($servicio, 'public function approveDerivedFields'), 2600);
    TestHarness::assertContains(
        'UPDATE bilingual_editorial_content SET published_content_json=?,updated_at=?',
        $aprobacion,
        'aprobar copia los campos a la copia publicada'
    );
    TestHarness::assertTrue(
        !str_contains($aprobacion, 'is_published') && !str_contains($aprobacion, 'setSpanishPublished'),
        'aprobar no cambia el estado de publicacion de la obra'
    );
    TestHarness::assertTrue(
        !str_contains($aprobacion, 'Cascade') && !str_contains($aprobacion, 'cascade'),
        'aprobar no dispara la cascada de mockups: es lo que separa este acto de publicar la obra'
    );
    TestHarness::assertTrue(
        !str_contains($aprobacion, 'content_json=?') || str_contains($aprobacion, 'published_content_json=?'),
        'aprobar no reescribe el borrador'
    );

    // ————— Solo se aprueba lo derivado, nunca la lectura —————
    TestHarness::assertTrue(
        !str_contains($aprobacion, "'description'") && !str_contains($aprobacion, "'subtitle'"),
        'la lectura de la obra no se toca al aprobar campos derivados'
    );

    // ————— La accion existe y la ruta no dispara cascada —————
    TestHarness::assertContains("=== 'approve_derived'", $publicacion, 'la seccion Publicacion tiene su propia accion de aprobar');
    $accion = substr($publicacion, (int)strpos($publicacion, "=== 'approve_derived'"), 1100);
    TestHarness::assertContains('requireValidCsrf', $accion, 'la accion valida el token, como toda escritura');
    TestHarness::assertContains('approveDerivedFields(', $accion, 'y llama a la aprobacion acotada');
    TestHarness::assertTrue(
        !str_contains($accion, 'queueMockupCascade') && !str_contains($accion, 'website_intent'),
        'la accion no publica la obra ni encola la cascada'
    );

    // ————— Cada boton dice que hace —————
    TestHarness::assertContains(
        'No escribe contenido.',
        $publicacion,
        'el boton de publicar declara que no escribe contenido: esta seccion lee y distribuye'
    );
    TestHarness::assertContains(
        'marca los textos de mockup que quedaron atrás',
        $publicacion,
        'y avisa que deja marcados los que hay que regenerar desde la ficha'
    );
    TestHarness::assertContains(
        'No publica ni aprueba nada.',
        $publicacion,
        'guardar dice explicitamente que no publica'
    );
    TestHarness::assertContains(
        'No toca el texto de la obra ni regenera ningún mockup.',
        $publicacion,
        'aprobar dice explicitamente lo que NO hace'
    );

    // ————— Lo pendiente se muestra antes de aprobar —————
    TestHarness::assertContains('pendingDerivedFields(', $publicacion, 'la seccion muestra que hay para revisar');
    $pendientes = substr($servicio, (int)strpos($servicio, 'public function pendingDerivedFields'), 1600);
    TestHarness::assertContains(
        "if (!(int)(\$row['is_published'] ?? 0)) {",
        $pendientes,
        'un idioma que se sirve del borrador no espera aprobacion: no tiene copia publicada contra la cual comparar'
    );

    // ————— Generar es de la ficha; Publicacion lee y distribuye —————
    $paquete = (string)file_get_contents($platformRoot . '/app/Services/ArtworkEditorialPackageService.php');
    TestHarness::assertContains(
        "\$this->scopeItem('artwork', \$artworkId, 40, 'channel')",
        $paquete,
        'los textos de canal son un item mas del paquete editorial de la obra, no un boton aparte'
    );
    $condicion = substr($paquete, (int)strpos($paquete, 'private function channelTextsPending'), 1200);
    TestHarness::assertContains(
        "if (empty(\$master['is_published']))",
        $condicion,
        'solo se ofrecen cuando la lectura esta APROBADA: derivar de un borrador fue el error corregido el 2026-08-04'
    );

    $worker = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialGenerationWorker.php');
    TestHarness::assertContains(
        "\$action === 'channel' && \$entityType === 'artwork'",
        $worker,
        'los textos de canal se generan en un job, como todo lo que llama al modelo: nunca dentro de una peticion'
    );
    TestHarness::assertContains(
        "if (\$estadoListado !== 'ok' || \$estadoVocabulario !== 'ok')",
        $worker,
        'un texto que no paso la validacion falla el job con su motivo, no se guarda a medias'
    );
    TestHarness::assertTrue(
        !str_contains($worker, "->save(\$userId, \$entityId, \$listado);\n                (new SaatchiListingService"),
        'no se guarda dos veces'
    );

    // Ningun cartel puede seguir prometiendo la regeneracion automatica.
    $ficha0 = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertTrue(
        !str_contains($ficha0, 'y actualiza los mockups') && !str_contains($ficha0, 'refreshes the mockups'),
        'la ficha ya no anuncia que publicar actualiza los mockups: ahora los marca y se regeneran desde el paquete editorial'
    );

    // El panel tiene que MOSTRAR lo que cuenta. Sin esta fila, el contador decia
    // "1 pendiente" y el desglose mostraba ceros: el artista veia que habia algo
    // sin poder saber que era.
    $panel = (string)file_get_contents($platformRoot . '/artwork-editorial-package.js');
    TestHarness::assertContains(
        "addScopeItem('Saatchi + site vocabulary', pending.channel || 0)",
        $panel,
        'los textos de canal tienen su propia fila en el panel, no solo su numero en el total'
    );
    foreach (['series', 'artwork', 'mockups', 'channel'] as $clave) {
        TestHarness::assertContains(
            "pending.{$clave}",
            $panel,
            "el panel muestra el pendiente de {$clave}: lo que se cuenta se ve"
        );
    }

    $jobs = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialJobService.php');
    TestHarness::assertContains(
        "'prepare', 'adapt', 'publish', 'channel'",
        $jobs,
        'la accion existe en el contrato de jobs'
    );
    TestHarness::assertTrue(
        !str_contains($publicacion, 'SaatchiListingService') && !str_contains($publicacion, 'DiscoveryKeywordsService'),
        'la seccion Publicacion no genera contenido: escribir es de la ficha de la obra'
    );

    // ————— La grilla del espacio editorial no vuelve a encimarse —————
    $ficha = (string)file_get_contents($platformRoot . '/artwork.php');
    TestHarness::assertContains(
        'repeat(var(--editorial-rows,9),auto)',
        $ficha,
        'las filas salen de la cantidad real de campos y no de un numero escrito a mano'
    );
    TestHarness::assertContains(
        'grid-row:1 / -1',
        $ficha,
        'cada columna abarca todas las filas que existan: con span fijo, los campos sobrantes se apilaban en la misma celda'
    );
    TestHarness::assertContains(
        '--editorial-rows:<?= count($bilingualEditorialFields) ?>',
        $ficha,
        'el HTML publica cuantos campos hay, asi que agregar uno no vuelve a romper la grilla'
    );
}
