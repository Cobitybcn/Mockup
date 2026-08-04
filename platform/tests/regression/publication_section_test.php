<?php
declare(strict_types=1);

/**
 * Custodia estructural de la sección Publicación (docs/PUBLICACION_DISENO.md,
 * VIGENTE 2026-07-31) y de la ficha de obra limpia.
 *
 * Invariantes: una sola puerta de publicación (la sección), la ficha termina en
 * «obra resuelta», la selección de media del sitio es explícita (no el acople
 * silencioso de favoritos), y el sitio público respeta esa selección.
 */
function run_publication_section_tests(): void
{
    TestHarness::group('Publicación: sección única y ficha limpia');

    $platformRoot = dirname(__DIR__, 2);
    $repoRoot = dirname($platformRoot);

    // La sección se verifica sobre su código, no sobre el texto de un documento.

    // ————— La sección existe con sus tres paneles y la compuerta —————
    $section = (string)file_get_contents($platformRoot . '/publication.php');
    TestHarness::assertContains('FeatureAccess::WEBSITE_MANAGE', $section, 'Publicación usa la capacidad Website existente');
    TestHarness::assertContains('save_website', $section, 'Publicación maneja el guardado/publicación de la página');
    TestHarness::assertContains('selected_mockups', $section, 'La composición de media es una selección explícita y ordenada');
    TestHarness::assertContains('publishFinalVideo', $section, 'El video de la página se adjunta desde Publicación');
    TestHarness::assertContains('unpublishFinalVideo', $section, 'El video de la página se quita desde Publicación');
    TestHarness::assertContains('pub-panel--inert', $section, 'Los paneles no habilitados quedan visibles e inertes, nunca ocultos');

    // ————— La grilla se lee en el orden que tendrá la página —————
    TestHarness::assertContains('$mediaGrid', $section, 'La grilla se arma en orden de página: primero lo incluido, después lo que quedó afuera');
    TestHarness::assertTrue(
        !str_contains($section, "foreach (\$doc['mockupCards'] as \$card)"),
        'La grilla ya no se dibuja en orden de creación del mockup'
    );
    TestHarness::assertContains('dragstart', $section, 'El orden se corrige arrastrando, no deseleccionando todo');
    TestHarness::assertContains('card.draggable = selected', $section, 'Solo lo incluido se arrastra: lo que está afuera no tiene orden');
    TestHarness::assertContains(
        'position === 0 || position === 3 || position === 6',
        $section,
        'Las cabeceras de publicación son las posiciones 1, 4 y 7 — el reparto en posts se ve sin contar de a tres'
    );
    TestHarness::assertContains('is-lead', $section, 'Las cabeceras de publicación se distinguen con su propio fondo');

    // ————— El producto es MOTOR, no paso (enmienda 2026-07-31) —————
    TestHarness::assertTrue(
        !str_contains($section, 'generate_product'),
        'El producto no es una decisión del artista: no existe el acto «Generar Producto»'
    );
    TestHarness::assertContains('ensureCurrentProduct', $section, 'El motor regenera la proyección solo, al cargar el documento');
    $productService = (string)file_get_contents($platformRoot . '/app/Services/PublicationProductService.php');
    TestHarness::assertContains('source_fingerprint', $productService, 'El producto congelado detecta fuentes cambiadas por fingerprint');
    TestHarness::assertTrue(
        !str_contains($productService, 'channel_variants'),
        'El producto NO se construye sobre la tabla legacy channel_variants'
    );
    TestHarness::assertContains('published_content_json', $productService, 'El locale de trabajo proyecta el snapshot publicado (publicar = aprobar)');

    // ————— Panel 3: distribución (Etapa 3) —————
    TestHarness::assertContains("'distribute'", $section, 'La distribución se dispara desde la sección — única puerta');
    TestHarness::assertContains('PublicationDistributionService', $section, 'El panel usa el servicio de distribución con el producto congelado');
    TestHarness::assertContains("confirm'] ?? '') !== 'yes'", $section, 'Cada envío exige confirmación explícita antes de publicar');
    TestHarness::assertContains('PINS PUBLICADOS', $section, 'Pinterest habla con su propio vocabulario de estado (serie de pins)');
    TestHarness::assertContains('data-copy-text', $section, 'El paquete Saatchi vive al lado de la acción, con copiar por campo');
    TestHarness::assertContains('name="pin_boards[', $section, 'Cada Pin muestra su propio selector de tablero antes del envío');
    TestHarness::assertContains('name="pin_link"', $section, 'Pinterest muestra un único enlace de destino para toda la serie');
    TestHarness::assertTrue(!str_contains($section, 'name="force_republish"'), 'El flujo normal no ofrece una republicación completa capaz de duplicar Pins');
    TestHarness::assertContains('pinterest_publish_token', $section, 'Cada envío Pinterest usa un token de un solo uso contra dobles POST');
    TestHarness::assertContains('$pinSeriesComplete', $section, 'Una serie completa se bloquea y pierde su CTA de publicación');
    TestHarness::assertContains('published_item_keys', $section, 'Los Pines exitosos quedan bloqueados individualmente en un reintento parcial');
    TestHarness::assertContains('pub-pin-card--error', $section, 'Pinterest muestra el fallo junto a la tarjeta exacta');
    TestHarness::assertContains('rejected_board_ids', $section, 'Los tableros Sandbox rechazados dejan de aparecer en los selectores Production');
    TestHarness::assertContains('publicationBoards', $section, 'Production ofrece su catálogo actual sin exigir Pins anteriores');
    TestHarness::assertContains('allowed_board_ids', $section, 'El backend valida cada selector contra el mismo catálogo Production visible');
    TestHarness::assertContains('GET_LOCK', (string)file_get_contents($platformRoot . '/app/Services/PublicationDistributionService.php'), 'Dos sesiones concurrentes serializan el envío antes de llamar a Pinterest');
    TestHarness::assertContains('form="pinterestPublishForm"', $section, 'Los selectores de cada tarjeta pertenecen al acto confirmado de Pinterest');
    TestHarness::assertContains('requires_republish', $section, 'Un resultado Sandbox no cierra el envío pendiente de Production');
    TestHarness::assertContains("'api_environment' => \$apiEnvironment", (string)file_get_contents($platformRoot . '/app/Services/PublicationDistributionService.php'), 'Cada envío de Pinterest queda ligado a su entorno API');

    // ————— Cinco pasos por importancia (enmienda 2026-07-31) —————
    $stepOrder = [
        strpos($section, 'pub-panel-website'),
        strpos($section, 'pub-step-saatchi'),
        strpos($section, 'pub-step-pinterest'),
        strpos($section, 'pub-step-social'),
        strpos($section, 'pub-step-tiktok'),
    ];
    TestHarness::assertTrue(
        !in_array(false, $stepOrder, true) && $stepOrder === array_values($stepOrder) && $stepOrder[0] < $stepOrder[1] && $stepOrder[1] < $stepOrder[2] && $stepOrder[2] < $stepOrder[3] && $stepOrder[3] < $stepOrder[4],
        'El documento respeta la jerarquía del artista: Sitio web → Saatchi → Pinterest → Social → TikTok'
    );
    TestHarness::assertContains('pub-pin-strip', $section, 'Pinterest muestra la serie COMPLETA de pins, no solo el de la portada');
    TestHarness::assertContains('Publicar serie espaciada', $section, 'Social publica la serie 3×3 con un solo acto');
    TestHarness::assertContains('gap_hours', $section, 'El lapso de la cadencia se ajusta una vez por usuario');
    TestHarness::assertContains('series_part_now', $section, 'Una parte programada o fallida puede dispararse ya');
    // ————— Paso 5: video y carrusel conviven (enmienda 2026-07-31) —————
    TestHarness::assertContains('TE ESPERA EN TIKTOK', $section, 'el carrusel dice la verdad: te espera en TikTok, no "publicado"');
    TestHarness::assertContains("name=\"medium\"", $section, 'el Paso 5 distingue el medio (video o carrusel) en el envío');
    TestHarness::assertContains('Enviar carrusel', $section, 'el Paso 5 ofrece enviar el carrusel');
    TestHarness::assertContains('El carrusel sigue disponible', $section, 'sin video, TikTok no es un callejón sin salida');
    // ————— El material se compone y se lleva sin salir del paso —————
    TestHarness::assertContains('upload_final_video', $section, 'el video terminado se adjunta desde el paso Sitio web, sin ir a Videos');
    TestHarness::assertContains('publication_saatchi_package.php', $section, 'el paquete de Saatchi se descarga con sus imágenes');
    $saatchiPackage = (string)@file_get_contents($platformRoot . '/publication_saatchi_package.php');
    TestHarness::assertContains('ZipPackage', $saatchiPackage, 'la descarga arma un ZIP sin depender de ext-zip');
    TestHarness::assertContains('saatchi.txt', $saatchiPackage, 'el ZIP incluye las keywords, la descripción y los pies');
    TestHarness::assertContains('1200', $saatchiPackage, 'el paquete avisa si una imagen no llega al mínimo que pide Saatchi');
    $uploadService = (string)file_get_contents($platformRoot . '/app/Video/VideoFinalUploadService.php');
    TestHarness::assertContains('uploadForArtwork', $uploadService, 'adjuntar un video no obliga al artista a pensar en proyectos');

    $publisher = (string)file_get_contents($platformRoot . '/app/Services/TikTokPublisher.php');
    $videoTikTokService = (string)file_get_contents($platformRoot . '/app/Services/VideoTikTokPublicationService.php');
    TestHarness::assertContains("require_once dirname(__DIR__) . '/Video/VideoMediaStorage.php'", $videoTikTokService, 'el envío de video TikTok carga su almacenamiento antes de utilizarlo');
    TestHarness::assertContains("if (!in_array('SELF_ONLY', \$creator['privacyOptions'], true))", $publisher, 'el video TikTok exige que la cuenta permita publicación privada');
    TestHarness::assertTrue(!str_contains($publisher, "\$creator['privacyOptions'][0]"), 'TikTok nunca reemplaza SELF_ONLY por una visibilidad más amplia');
    TestHarness::assertContains('MEDIA_UPLOAD', $publisher, 'el carrusel usa Creator\'s Draft para que el artista elija la música');
    TestHarness::assertContains('PULL_FROM_URL', $publisher, 'TikTok descarga las fotos: la única vía documentada para carrusel');
    TestHarness::assertContains("'video.upload'", $publisher, 'el carrusel pide su propio scope, distinto del de video');
    $worker = (string)@file_get_contents($platformRoot . '/publication_distribution_worker.php');
    TestHarness::assertContains('GCP_WORKER_URL', $worker, 'El worker de cadencia valida el host como el worker social');
    TestHarness::assertContains('runScheduledSend', $worker, 'El worker dispara los envíos programados de forma idempotente');
    TestHarness::assertContains('CARGADO A MANO', $section, 'Saatchi habla con su vocabulario manual');
    TestHarness::assertTrue(
        !str_contains($section, 'Próxima etapa: Pinterest'),
        'El placeholder inerte de Distribución fue reemplazado por el panel real'
    );
    $distributionService = (string)file_get_contents($platformRoot . '/app/Services/PublicationDistributionService.php');
    TestHarness::assertContains('isStale', $distributionService, 'La distribución bloquea cuando el producto está desactualizado');
    TestHarness::assertContains('LIVE_PUBLISH_ENABLED', $distributionService, 'Los gates de entorno viven dentro de los transportes reales');
    TestHarness::assertTrue(
        !str_contains($distributionService, 'channel_variants') && !str_contains($distributionService, 'social_channel_drafts'),
        'La distribución NO se apoya en la infraestructura legacy de drafts/variants'
    );

    // ————— Ficha limpia: cero decisiones de publicación —————
    $ficha = (string)file_get_contents($platformRoot . '/artwork.php');
    foreach (['save_artwork_website', 'website_intent', 'artwork-website-panel', 'sync_artwork_website_v2', 'saatchi_url', 'sale_price'] as $forbidden) {
        TestHarness::assertTrue(
            !str_contains($ficha, $forbidden),
            "Ficha limpia: artwork.php no contiene '{$forbidden}' — website/e-commerce viven en Publicación"
        );
    }

    // ————— Moneda: una sola, la de la tienda —————
    // El precio de la obra no elige moneda. Cuando podía, una obra quedaba en
    // EUR contra una tienda en USD y la ficha pública escondía precio y botón
    // de compra sin explicar nada (AppStore::offerForArtwork, is_purchasable).
    TestHarness::assertTrue(
        !str_contains($section, 'sale_currency'),
        'La moneda no se decide obra por obra: el formulario ya no la envía'
    );
    TestHarness::assertContains('pub_store_currency', $section, 'El precio guardado hereda la moneda de la tienda');
    // site-admin vive al lado de platform/ en el repo y DENTRO de la raíz en la
    // imagen de producción (Dockerfile.web lo copia a html/site-admin). Buscar
    // en una sola ruta pasa en local y falla en el build, que es donde la suite
    // decide si se despliega.
    $siteManagerPath = is_file($repoRoot . '/site-admin/app/SiteManagerService.php')
        ? $repoRoot . '/site-admin/app/SiteManagerService.php'
        : $platformRoot . '/site-admin/app/SiteManagerService.php';
    TestHarness::assertTrue(is_file($siteManagerPath), 'la suite encuentra Site Manager en el repo y dentro de la imagen');
    $siteManager = (string)file_get_contents($siteManagerPath);
    TestHarness::assertContains('$currency = $this->storeCurrency($userId);', $siteManager, 'El alta de stock también hereda la moneda de la tienda');
    TestHarness::assertContains(
        'UPDATE artist_site_print_variants SET currency=?',
        $siteManager,
        'Cambiar la moneda de la tienda re-etiqueta los precios existentes: ninguna obra queda en la moneda vieja'
    );

    // ————— Puerta única: Videos ya no publica al sitio —————
    $videos = (string)file_get_contents($platformRoot . '/videos.php');
    TestHarness::assertTrue(
        !str_contains($videos, 'data-final-publish-form'),
        'Puerta única: la tarjeta de video no publica al sitio'
    );
    TestHarness::assertContains('publication.php', $videos, 'La tarjeta de video señala que se publica desde Publicación');
    $videosJs = (string)file_get_contents($platformRoot . '/videos.js');
    TestHarness::assertTrue(
        !str_contains($videosJs, 'video_final_publish.php'),
        'Puerta única: el endpoint suelto de publicar video no se usa'
    );
    TestHarness::assertTrue(
        !is_file($platformRoot . '/video_final_publish.php'),
        'Puerta única: el endpoint suelto de publicar video fue eliminado'
    );

    // ————— El gate global de mutaciones cubre la sección —————
    $requestSecurity = (string)file_get_contents($platformRoot . '/app/Support/RequestSecurity.php');
    TestHarness::assertContains('|publication|', $requestSecurity, 'RequestSecurity protege los POST de publication.php');

    // ————— La sección está registrada en el grupo Publicar del sidebar —————
    $sidebar = (string)file_get_contents($platformRoot . '/sidebar.php');
    TestHarness::assertContains('$sidebarPublicationUrl', $sidebar, 'El sidebar registra la sección Publicación');
    TestHarness::assertTrue(
        substr_count($sidebar, '$sidebarPublicationUrl,') >= 3 || substr_count($sidebar, 'sidebarPublicationUrl') >= 4,
        'La entrada Publicación existe en escritorio y en los dos paneles móviles'
    );

    // ————— Selección explícita en el servicio (los favoritos son solo sugerencia) —————
    $service = (string)file_get_contents($platformRoot . '/app/Services/PublicationService.php');
    TestHarness::assertContains('mapMockupIdsToSheetIds', $service, 'PublicationService resuelve la selección explícita de mockups');
    TestHarness::assertContains('$explicitItems', $service, 'saveWebsiteSettings acepta la selección explícita con caption/alt por ítem');

    // ————— El sitio público respeta la selección explícita —————
    // Dockerfile.web copia los archivos del artist-site uno por uno: leer aquí un
    // archivo que la imagen no lleve pasa en local y falla dentro del build. El
    // par queda atado — si se cae el COPY, esta suite lo canta antes de desplegar.
    $webDockerfile = (string)file_get_contents($platformRoot . '/Dockerfile.web');
    TestHarness::assertContains(
        'artist-site/inc/AppPublishedCatalog.php',
        $webDockerfile,
        'La imagen de producción lleva el catálogo público que esta suite audita'
    );
    $catalog = (string)file_get_contents($repoRoot . '/artist-site/inc/AppPublishedCatalog.php');
    TestHarness::assertContains(
        '$explicitFavoriteCount === 0',
        $catalog,
        'AppPublishedCatalog: con selección explícita la página muestra SOLO lo seleccionado; sin selección, fallback legacy'
    );

    // ————— El índice ordena igual que ArtWorks —————
    // La misma colección se veía en dos ordenes distintos: ArtWorks agrupa por
    // serie y Publicación ordenaba por updated_at, o sea "lo último tocado".
    $album = (string)file_get_contents($platformRoot . '/root_album.php');
    foreach ([
        'CASE WHEN a.series_id IS NULL THEN 1 ELSE 0 END ASC' => 'las obras sin serie van al final',
        'COALESCE(s.year_start, s.year_end) DESC' => 'la serie más reciente primero',
        'a.series_creation_number DESC' => 'dentro de la serie, por número de creación descendente',
    ] as $clausula => $porque) {
        TestHarness::assertContains($clausula, $album, "ArtWorks: {$porque}");
        TestHarness::assertContains($clausula, $section, "Publicación usa el mismo criterio que ArtWorks: {$porque}");
    }
    TestHarness::assertTrue(
        !str_contains($section, "ORDER BY a.updated_at DESC, a.created_at DESC"),
        'Publicación ya no ordena por lo último tocado: ese criterio no coincidía con ninguna otra pantalla'
    );
}
