<?php
declare(strict_types=1);

/**
 * El reparto entre las dos secciones, escrito como ley.
 *
 *   La ficha de la obra ESCRIBE. Genera la lectura, los textos de sus mockups y
 *   los de canal —el paquete de Saatchi—, todos como items del mismo paquete
 *   editorial, donde el artista ve cuantos son antes de apretar.
 *
 *   Publicacion DECIDE QUE SALE. Publica, despublica, distribuye, muestra y deja
 *   copiar. No escribe contenido.
 *
 * "Publicar Obra" publica la pagina y aprueba el texto. No regenera los mockups:
 * los marca, y se regeneran desde la ficha. Y encola los textos de canal, porque
 * publicar es el momento en que la lectura queda aprobada, que es la condicion
 * que esos textos necesitan.
 */
function run_derived_fields_approval_tests(): void
{
    TestHarness::group('Reparto: la ficha escribe, Publicacion decide que sale');

    $platformRoot = dirname(__DIR__, 2);
    $servicio = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialService.php');
    $publicacion = (string)file_get_contents($platformRoot . '/publication.php');
    $ficha = (string)file_get_contents($platformRoot . '/artwork.php');

    // ————— Que campos son derivados —————
    foreach (['saatchi_title', 'saatchi_description', 'saatchi_caption', 'saatchi_keywords', 'discovery_keywords'] as $campo) {
        TestHarness::assertContains(
            "'{$campo}'",
            substr($servicio, (int)strpos($servicio, 'public const DERIVED_FIELDS'), 400),
            "{$campo} esta declarado como campo derivado"
        );
    }

    // ————— La escritura pasa por el dueño de la tabla y toca solo el borrador —————
    $merge = substr($servicio, (int)strpos($servicio, 'public function mergeDerivedFields'), 2200);
    TestHarness::assertTrue(
        !str_contains($merge, 'published_content_json'),
        'mergeDerivedFields() no escribe en la copia publicada: publicar es aprobar, y eso lo hace el artista'
    );
    TestHarness::assertContains(
        "(string)\$row['status'],",
        $merge,
        'conserva el estado tal cual estaba, para no mentirle al mecanismo que compara la adaptacion con su fuente'
    );

    // ————— Ya no hay un segundo tramite de aprobacion —————
    // Existio para que el vocabulario del listing llegara al sitio. Ese
    // vocabulario dejo de ir al sitio —es lenguaje de obra y alli era ruido— asi
    // que no queda nada que aprobar, y el paquete de Saatchi nunca lo necesito:
    // se copia a mano.
    TestHarness::assertTrue(
        !str_contains($publicacion, 'approve_derived') && !str_contains($publicacion, 'approveDerivedFields'),
        'la seccion Publicacion no tiene boton de aprobar campos derivados: sin destino publico no hay nada que aprobar'
    );
    TestHarness::assertTrue(
        !str_contains($servicio, 'function approveDerivedFields') && !str_contains($servicio, 'function pendingDerivedFields'),
        'y los metodos que lo sostenian se retiraron en vez de quedar como codigo muerto'
    );

    // ————— Publicar no escribe contenido —————
    TestHarness::assertContains(
        'markMockupsOutdated',
        $publicacion,
        'EDITORIAL_CORE VI.4 (enmienda 2026-08-04): publicar marca los mockups que quedaron de una lectura anterior'
    );
    TestHarness::assertTrue(
        !str_contains($publicacion, 'queueMockupCascadeForArtwork'),
        'publicar no regenera el texto de ningun mockup: esa escritura pertenece a la ficha'
    );
    TestHarness::assertContains(
        'readingChangedSincePublish',
        $publicacion,
        'y solo marca cuando hay una version nueva de la lectura que propagar'
    );
    TestHarness::assertTrue(
        !str_contains($publicacion, 'SaatchiListingService') && !str_contains($publicacion, 'DiscoveryKeywordsService'),
        'Publicacion no genera contenido por su cuenta: encola un job y el worker escribe'
    );

    // ————— Pero si encola los textos de canal —————
    TestHarness::assertContains(
        "'channel',",
        $publicacion,
        'publicar encola los textos de canal: es el momento exacto en que la lectura queda aprobada'
    );
    TestHarness::assertContains(
        'if (isset($channelJob) && is_array($channelJob))',
        $publicacion,
        'y se despacha tras el commit, nunca contra un job sin confirmar'
    );

    // ————— La ficha ofrece el paso, y solo cuando puede —————
    $paquete = (string)file_get_contents($platformRoot . '/app/Services/ArtworkEditorialPackageService.php');
    TestHarness::assertContains(
        "\$this->scopeItem('artwork', \$artworkId, 40, 'channel')",
        $paquete,
        'los textos de canal son un item mas del paquete editorial, no un boton aparte'
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
    TestHarness::assertTrue(
        !str_contains($worker, 'DiscoveryKeywordsService'),
        'el job ya no genera el vocabulario del sitio: sin destino, era una llamada al modelo por obra tirada'
    );

    $jobs = (string)file_get_contents($platformRoot . '/app/Services/BilingualEditorialJobService.php');
    TestHarness::assertContains("'prepare', 'adapt', 'publish', 'channel'", $jobs, 'la accion existe en el contrato de jobs');

    // ————— El panel muestra lo que cuenta —————
    $panel = (string)file_get_contents($platformRoot . '/artwork-editorial-package.js');
    TestHarness::assertContains(
        "addScopeItem('Saatchi + site vocabulary', pending.channel || 0)",
        $panel,
        'los textos de canal tienen su propia fila: sin ella el contador decia "1 pendiente" y el desglose mostraba ceros'
    );

    // ————— Cada boton dice que hace —————
    TestHarness::assertContains('No escribe contenido.', $publicacion, 'el boton de publicar declara que no escribe contenido');
    TestHarness::assertContains(
        'marca los textos de mockup que quedaron atrás',
        $publicacion,
        'y avisa que deja marcados los que hay que regenerar desde la ficha'
    );
    TestHarness::assertTrue(
        !str_contains($ficha, 'y actualiza los mockups') && !str_contains($ficha, 'refreshes the mockups'),
        'la ficha ya no anuncia que publicar actualiza los mockups'
    );

    // ————— La grilla del espacio editorial no vuelve a encimarse —————
    TestHarness::assertContains('repeat(var(--editorial-rows,9),auto)', $ficha, 'las filas salen de la cantidad real de campos');
    TestHarness::assertContains('grid-row:1 / -1', $ficha, 'cada columna abarca todas las filas existentes');
    TestHarness::assertContains('--editorial-rows:<?= count($bilingualEditorialFields) ?>', $ficha, 'agregar un campo no vuelve a romper la grilla');
}
