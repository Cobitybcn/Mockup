<?php
declare(strict_types=1);

function run_language_policy_tests(): void
{
    TestHarness::group('Politica linguistica por artista');

    run_translator_tests();

    $supported = LanguagePolicy::supportedLocales();
    TestHarness::assertTrue(
        array_key_exists('es', $supported) && array_key_exists('en', $supported),
        'la beta habilita espanol e ingles como idiomas de trabajo'
    );
    TestHarness::assertSame(
        false,
        LanguagePolicy::isSupportedLocale('de'),
        'un idioma no habilitado se rechaza en lugar de aceptarse en silencio'
    );

    $maurizio = ['working_locale' => 'es', 'publication_locales' => ['es', 'en']];
    TestHarness::assertSame(
        ['en'],
        LanguagePolicy::adaptationTargets($maurizio),
        'trabajar en espanol y publicar en espanol e ingles adapta solo al ingles'
    );

    $soloNativo = ['working_locale' => 'es', 'publication_locales' => ['es']];
    TestHarness::assertSame(
        [],
        LanguagePolicy::adaptationTargets($soloNativo),
        'publicar solo en el idioma de trabajo no crea ninguna adaptacion'
    );
    TestHarness::assertSame(
        false,
        LanguagePolicy::requiresAdaptation($soloNativo),
        'el artista que publica en su idioma no recibe una columna vacia de ingles'
    );

    $inglesDirecto = ['working_locale' => 'en', 'publication_locales' => ['en']];
    TestHarness::assertSame(
        [],
        LanguagePolicy::adaptationTargets($inglesDirecto),
        'trabajar y publicar en ingles no ejecuta un trabajo ingles a ingles'
    );

    $nativoAInternacional = ['working_locale' => 'es', 'publication_locales' => ['en']];
    TestHarness::assertSame(
        ['en'],
        LanguagePolicy::adaptationTargets($nativoAInternacional),
        'publicar solo en el mercado internacional mantiene la adaptacion'
    );

    TestHarness::assertSame(
        ['es', 'en'],
        LanguagePolicy::normalizePublicationLocales(['ES', 'en', 'es', 'de', '']),
        'la seleccion se normaliza, deduplica y descarta idiomas no habilitados'
    );

    $rejected = 0;
    foreach ([['de', ['de']], ['es', []]] as [$working, $publication]) {
        try {
            LanguagePolicy::save(1, $working, $publication);
        } catch (InvalidArgumentException $e) {
            $rejected++;
        }
    }
    TestHarness::assertSame(
        2,
        $rejected,
        'guardar exige un idioma de trabajo habilitado y al menos un idioma de publicacion'
    );

    $fallback = LanguagePolicy::forUser(0);
    TestHarness::assertSame(
        false,
        $fallback['is_explicit'],
        'una cuenta sin politica registrada queda marcada como no explicita'
    );
    TestHarness::assertSame(
        ['en'],
        $fallback['publication_locales'],
        'sin politica registrada el default es ingles, no el idioma historico de Maurizio'
    );

    Database::connection()->query('SELECT user_id, working_locale, publication_locales_json, interface_locale FROM user_language_policy WHERE 1 = 0');
    TestHarness::assertTrue(true, 'la politica linguistica pertenece al esquema versionado');

    $bootstrap = (string)file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
    TestHarness::assertContains(
        "Support/LanguagePolicy.php",
        $bootstrap,
        'la politica linguistica se carga en la composicion central'
    );

    $account = (string)file_get_contents(dirname(__DIR__, 2) . '/account.php');
    TestHarness::assertContains(
        'save_language_policy',
        $account,
        'el artista puede registrar su idioma de trabajo y sus idiomas de publicacion'
    );
    TestHarness::assertContains(
        "Auth::csrfToken('account_language')",
        $account,
        'el formulario de idiomas viaja con su token CSRF'
    );

    TestHarness::group('Separacion Standard/Pro: el contenido editorial es una capacidad');

    $editorialSurfaces = [
        'bilingual_editorial.php' => 'la preparacion y adaptacion bilingue exige la capacidad editorial',
        'artwork_editorial_package.php' => 'preparar el paquete editorial de una obra exige la capacidad editorial',
    ];
    foreach ($editorialSurfaces as $surface => $message) {
        TestHarness::assertContains(
            'FeatureAccess::EDITORIAL_MANAGE',
            (string)file_get_contents(dirname(__DIR__, 2) . '/' . $surface),
            $message
        );
    }

    $automaticSurfaces = [
        'app/Services/MockupGenerationWorker.php' => 'crear un mockup no genera su analisis editorial por su cuenta',
        'process_generate.php' => 'crear una obra no genera su paquete editorial por su cuenta',
        'artwork.php' => 'elegir la raiz no genera el paquete editorial por su cuenta',
        'app/Services/BilingualEditorialGenerationWorker.php' => 'un trabajo encolado deja de generar si la cuenta pierde la capacidad',
    ];
    foreach ($automaticSurfaces as $surface => $message) {
        TestHarness::assertContains(
            'FeatureAccess::allowsUserId',
            (string)file_get_contents(dirname(__DIR__, 2) . '/' . $surface),
            $message
        );
    }

    TestHarness::assertSame(
        false,
        FeatureAccess::allowsUserId(0, FeatureAccess::EDITORIAL_MANAGE),
        'una cuenta inexistente no recibe la capacidad editorial'
    );
    TestHarness::assertSame(
        false,
        FeatureAccess::planAllows(FeatureAccess::PLAN_ARTIST_STUDIO, FeatureAccess::EDITORIAL_MANAGE),
        'Artist Studio no produce contenido editorial'
    );
    TestHarness::assertTrue(
        FeatureAccess::planAllows(FeatureAccess::PLAN_ARTIST_PRO, FeatureAccess::EDITORIAL_MANAGE),
        'Artist Pro conserva la produccion editorial'
    );
    TestHarness::assertTrue(
        FeatureAccess::planAllows(FeatureAccess::PLAN_ARTIST_STUDIO, FeatureAccess::MOCKUPS_GENERATE),
        'el nucleo grafico sigue disponible para Artist Studio'
    );

    TestHarness::assertContains(
        'name="artwork_title"',
        (string)file_get_contents(dirname(__DIR__, 2) . '/create_scenes.php'),
        'el artista puede titular su obra al crearla, sin depender del analisis de IA'
    );
    foreach (['start_generate.php', 'upload_existing_root.php'] as $creationEndpoint) {
        $creationSource = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $creationEndpoint);
        TestHarness::assertTrue(
            str_contains($creationSource, "artwork_title") && str_contains($creationSource, 'final_title'),
            'la creacion por ' . $creationEndpoint . ' persiste el titulo elegido por el artista'
        );
    }

    run_language_policy_engine_cases();

    // Fase 4: el motor editorial dejo de asumir espanol como origen y ahora
    // resuelve sus idiomas desde la politica del artista. Este contrato se
    // volteo de forma consciente al cruzar esa linea.
    $bilingualService = (string)file_get_contents(dirname(__DIR__, 2) . '/app/Services/BilingualEditorialService.php');
    TestHarness::assertContains(
        'LanguagePolicy::forUser',
        $bilingualService,
        'el motor editorial resuelve sus idiomas desde la politica del artista'
    );
    TestHarness::assertSame(
        false,
        str_contains($bilingualService, "return 'es';"),
        'el idioma de trabajo ya no esta fijado en el motor'
    );
}

/**
 * Ejercita el motor con politicas reales sobre una base aislada: artista de
 * ingles directo, artista de un solo idioma y el par historico es/en.
 */
function run_language_policy_engine_cases(): void
{
    TestHarness::group('Motor editorial dirigido por la politica de idiomas');

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active', is_admin INTEGER NOT NULL DEFAULT 0, plan_code TEXT NOT NULL DEFAULT 'artist_pro')");
    $pdo->exec("CREATE TABLE user_language_policy (user_id INTEGER PRIMARY KEY, working_locale TEXT NOT NULL, publication_locales_json TEXT NOT NULL, interface_locale TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE bilingual_editorial_settings (user_id INTEGER PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0, source_locale TEXT NOT NULL DEFAULT 'es', publication_locale TEXT NOT NULL DEFAULT 'en', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE bilingual_editorial_content (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, locale TEXT NOT NULL, content_json TEXT NOT NULL DEFAULT '{}', private_memo TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft', source_hash TEXT NOT NULL DEFAULT '', is_published INTEGER NOT NULL DEFAULT 0, published_content_json TEXT NOT NULL DEFAULT '', published_at TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (user_id, entity_type, entity_id, locale))");
    $pdo->exec("CREATE TABLE bilingual_editorial_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, action TEXT NOT NULL, status TEXT NOT NULL, source_locale TEXT NOT NULL DEFAULT 'es', target_locale TEXT NOT NULL DEFAULT 'en', payload_json TEXT NOT NULL DEFAULT '{}', result_json TEXT NOT NULL DEFAULT '{}', error TEXT NOT NULL DEFAULT '', task_name TEXT NOT NULL DEFAULT '', attempts INTEGER NOT NULL DEFAULT 0, started_at TEXT, completed_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE artwork_series (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL DEFAULT '', subtitle TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', long_description TEXT NOT NULL DEFAULT '', tags TEXT NOT NULL DEFAULT '', keywords TEXT NOT NULL DEFAULT '', seo_description TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL DEFAULT '')");

    $now = date(DATE_ATOM);
    $pdo->exec("INSERT INTO users (id,email) VALUES (101,'english@example.test'),(102,'nativo@example.test'),(103,'bilingue@example.test')");
    $insertPolicy = $pdo->prepare('INSERT INTO user_language_policy (user_id,working_locale,publication_locales_json,interface_locale,created_at,updated_at) VALUES (?,?,?,?,?,?)');
    $insertPolicy->execute([101, 'en', '["en"]', '', $now, $now]);
    $insertPolicy->execute([102, 'es', '["es"]', '', $now, $now]);
    $insertPolicy->execute([103, 'es', '["es","en"]', '', $now, $now]);
    $pdo->exec("INSERT INTO artwork_series (id,user_id,title) VALUES (11,101,'Direct Series'),(12,102,'Serie Local'),(13,103,'Serie Puente')");

    $service = new BilingualEditorialService($pdo);
    $jobs = new BilingualEditorialJobService($pdo);

    // Artista internacional: trabaja y publica en ingles.
    TestHarness::assertSame('en', $service->sourceLocale(101), 'el idioma de trabajo del artista internacional es el ingles');
    TestHarness::assertSame([], $service->adaptationTargets(101), 'trabajar y publicar en ingles no deja adaptaciones pendientes');
    $saved = $service->save(101, 'series', 11, 'en', ['description' => 'Direct English master.']);
    TestHarness::assertSame('source', (string)$saved['status'], 'el master del artista internacional se guarda como fuente');
    TestHarness::assertSame('not_required', (string)$saved['english_status'], 'no queda ninguna columna vacia esperando una adaptacion');
    $legacyCopy = (string)$pdo->query('SELECT description FROM artwork_series WHERE id=11')->fetchColumn();
    TestHarness::assertSame('', $legacyCopy, 'la ficha heredada de series usa el campo largo, no la descripcion corta');
    $englishJob = $jobs->createOrReuse(101, 'series', 11, 'prepare');
    TestHarness::assertSame('en', (string)$englishJob['source_locale'], 'el trabajo encolado del artista internacional parte del ingles');
    TestHarness::assertSame('en', (string)$englishJob['target_locale'], 'sin idioma adicional no se fabrica un destino espanol fantasma');

    // Artista local: trabaja y publica solo en espanol.
    TestHarness::assertSame([], $service->adaptationTargets(102), 'publicar solo en espanol no crea adaptacion');
    $direction = $service->adaptationDirection(['description' => 'Texto'], [], 102);
    TestHarness::assertSame('', (string)$direction['direction'], 'el artista de un solo idioma no recibe la flecha de adaptacion');
    $rejected = false;
    try {
        $service->saveAdaptation(102, 'series', 12, 'es', 'en', ['description' => 'English text']);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    TestHarness::assertTrue($rejected, 'una adaptacion hacia un idioma no publicado se rechaza');

    // Par historico: espanol como trabajo, ingles como publicacion adicional.
    TestHarness::assertSame('es', $service->sourceLocale(103), 'el par historico conserva el espanol como idioma de trabajo');
    TestHarness::assertSame(['en'], $service->adaptationTargets(103), 'el par historico adapta unicamente al ingles');
    $bridgeDirection = $service->adaptationDirection(['description' => 'Texto'], [], 103);
    TestHarness::assertSame('es-en', (string)$bridgeDirection['direction'], 'la flecha del par historico sigue siendo es-en');
    $bridgeJob = $jobs->createOrReuse(103, 'series', 13, 'prepare');
    TestHarness::assertSame('es', (string)$bridgeJob['source_locale'], 'el trabajo del par historico parte del espanol');
    TestHarness::assertSame('en', (string)$bridgeJob['target_locale'], 'el trabajo del par historico apunta al ingles');
}

/**
 * Fase 5: la interfaz sigue al idioma de interfaz del artista. Ejercita
 * Translator directamente y fija el contrato de account.php como primera
 * pantalla convertida.
 */
function run_translator_tests(): void
{
    TestHarness::group('Fase 5: interfaz bilingue (Translator)');

    $pdo = Database::connection();
    $existingUserId = (int)$pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    TestHarness::assertTrue($existingUserId > 0, 'existe al menos una cuenta local para ejercitar el traductor');

    Translator::forgetResolvedLocale();
    TestHarness::assertSame(
        'Save',
        Translator::t('Save', 'Guardar', ['id' => 0]),
        'sin usuario, la interfaz cae a ingles'
    );

    $pdo->beginTransaction();
    try {
        LanguagePolicy::save($existingUserId, 'en', ['en'], 'en');
        LanguagePolicy::forgetCachedPolicy($existingUserId);
        Translator::forgetResolvedLocale();
        $user = ['id' => $existingUserId];
        TestHarness::assertSame('en', Translator::locale($user), 'la interfaz sigue el idioma de interfaz explicito en ingles');
        TestHarness::assertSame('Save', Translator::t('Save', 'Guardar', $user), 't() devuelve el texto en ingles cuando la interfaz es ingles');

        LanguagePolicy::save($existingUserId, 'es', ['es', 'en'], 'es');
        LanguagePolicy::forgetCachedPolicy($existingUserId);
        Translator::forgetResolvedLocale();
        TestHarness::assertSame('es', Translator::locale($user), 'la interfaz sigue el idioma de interfaz explicito en español');
        TestHarness::assertSame('Guardar', Translator::t('Save', 'Guardar', $user), 't() devuelve el texto en español cuando la interfaz es español');
    } finally {
        $pdo->rollBack();
        LanguagePolicy::forgetCachedPolicy($existingUserId);
        Translator::forgetResolvedLocale();
    }

    $bootstrap = (string)file_get_contents(dirname(__DIR__, 2) . '/app/bootstrap.php');
    TestHarness::assertContains(
        'Support/Translator.php',
        $bootstrap,
        'el traductor de interfaz se carga en la composicion central'
    );

    $accountSource = (string)file_get_contents(dirname(__DIR__, 2) . '/account.php');
    TestHarness::assertSame(
        false,
        (bool)preg_match('/<html\s+lang="en"/', $accountSource),
        'account.php ya no fija el idioma de la interfaz en ingles'
    );
    TestHarness::assertContains(
        'Translator::locale($user)',
        $accountSource,
        'account.php resuelve el idioma de interfaz por artista'
    );
    TestHarness::assertContains(
        "t('Save languages', 'Guardar idiomas')",
        $accountSource,
        'account.php traduce su texto visible con el patron t()'
    );
}
