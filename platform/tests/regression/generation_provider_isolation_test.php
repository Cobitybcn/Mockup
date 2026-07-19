<?php
declare(strict_types=1);

function run_generation_provider_isolation_tests(): void
{
    TestHarness::group('Proveedor de generación por lote');

    TestHarness::assertSame('gemini', ServiceFactory::generationProvider('gemini'), 'Vertex se selecciona explícitamente por lote');
    TestHarness::assertSame('openai', ServiceFactory::generationProvider('openai'), 'OpenAI se selecciona explícitamente por lote');
    TestHarness::assertTrue(
        ProviderSettings::canSelectGenerationProvider(false, 'localhost:80'),
        'Una sesion local puede probar proveedores sin cambiar permisos de usuario'
    );
    TestHarness::assertTrue(
        !ProviderSettings::canSelectGenerationProvider(false, 'artworkmockups.example'),
        'Un usuario normal no recibe el selector tecnico fuera del entorno local'
    );

    ProviderSettings::set([
        'app_mode' => 'gemini',
        'allow_real_api' => '1',
        'openai_api_key' => 'test-only-key',
    ]);

    TestHarness::assertTrue(
        ServiceFactory::mockupGenerator('gemini') instanceof GeminiMockupGenerator,
        'Vertex conserva el generador directo sin una segunda llamada de validacion'
    );

    $openAiDecorator = ServiceFactory::mockupGenerator('openai');
    TestHarness::assertTrue(
        $openAiDecorator instanceof FidelityValidatingMockupGenerator,
        'OpenAI mantiene su control de fidelidad aislado'
    );

    $wrappedGeneratorProperty = new ReflectionProperty(FidelityValidatingMockupGenerator::class, 'generator');
    $wrappedGeneratorProperty->setAccessible(true);
    $openAiGenerator = $wrappedGeneratorProperty->getValue($openAiDecorator);
    TestHarness::assertTrue(
        $openAiGenerator instanceof OpenAIMockupGenerator,
        'El lote OpenAI no cambia el generador global de Vertex'
    );

    $openAiArtworkProcessor = ServiceFactory::artworkProcessor('openai');
    TestHarness::assertTrue(
        $openAiArtworkProcessor instanceof OpenAIArtworkProcessor,
        'OpenAI tambien prepara la obra raiz dentro del flujo completo'
    );

    foreach ([
        'model' => 'gpt-image-2',
        'size' => '1024x1280',
        'quality' => 'medium',
    ] as $propertyName => $expectedValue) {
        $property = new ReflectionProperty(OpenAIMockupGenerator::class, $propertyName);
        $property->setAccessible(true);
        TestHarness::assertSame(
            $expectedValue,
            $property->getValue($openAiGenerator),
            "OpenAI usa {$propertyName} compatible con el flujo de escenas"
        );
    }

    $referenceContract = new ReflectionMethod(OpenAIMockupGenerator::class, 'referenceContract');
    $referenceContract->setAccessible(true);
    $contract = (string)$referenceContract->invoke($openAiGenerator, 'CAMERA PROMPT', [
        ['path' => 'root.png', 'role' => 'root_artwork'],
        ['path' => 'world.jpg', 'role' => 'world_mother'],
    ]);
    TestHarness::assertContains('IMAGE 1', $contract, 'La obra raíz conserva la primera autoridad visual');
    TestHarness::assertContains('IMAGE 2 is the WORLD MOTHER: environmental inspiration only', $contract, 'La escena se limita a inspiración ambiental');
    TestHarness::assertContains('exactly a 4:5 portrait image', $contract, 'OpenAI conserva el formato visual 4:5');

    $multipartBody = new ReflectionMethod(OpenAIMockupGenerator::class, 'multipartBody');
    $multipartBody->setAccessible(true);
    $multipartFixture = tempnam(sys_get_temp_dir(), 'provider-multipart-');
    file_put_contents($multipartFixture, 'fixture');
    try {
        [$multipart] = $multipartBody->invoke($openAiGenerator, ['model' => 'gpt-image-2'], [
            ['path' => $multipartFixture, 'role' => 'root_artwork'],
            ['path' => $multipartFixture, 'role' => 'world_mother'],
        ]);
    } finally {
        @unlink($multipartFixture);
    }
    TestHarness::assertSame(
        2,
        substr_count((string)$multipart, 'name="image[]"'),
        'El multipart usa campos image[] repetidos como documenta OpenAI'
    );

    $reviewSource = (string)file_get_contents(__DIR__ . '/../../mockup_combinations_review.php');
    $endpointSource = (string)file_get_contents(__DIR__ . '/../../generate_mockup_combination.php');
    $workerSource = (string)file_get_contents(__DIR__ . '/../../worker.php');
    $workerServiceSource = (string)file_get_contents(__DIR__ . '/../../app/Services/MockupGenerationWorker.php');
    $dispatcherSource = (string)file_get_contents(__DIR__ . '/../../app/Services/MockupGenerationDispatcher.php');
    $queueWorkerSource = (string)file_get_contents(__DIR__ . '/../../mockup_queue_worker.php');
    $resultsSource = (string)file_get_contents(__DIR__ . '/../../mockup_combination_results.php');
    $activitySource = (string)file_get_contents(__DIR__ . '/../../mockup_generation_activity.php');
    $createScenesSource = (string)file_get_contents(__DIR__ . '/../../create_scenes.php');
    $startGenerateSource = (string)file_get_contents(__DIR__ . '/../../start_generate.php');
    $jobStatusSource = (string)file_get_contents(__DIR__ . '/../../job_status.php');
    $sceneWaitSource = (string)file_get_contents(__DIR__ . '/../../create_scenes_wait.php');
    $databaseSource = (string)file_get_contents(__DIR__ . '/../../app/Support/Database.php');
    TestHarness::assertTrue(
        !str_contains($reviewSource, 'id="generation-provider-select"'),
        'El selector tecnico no aparece en la revision de escenas'
    );
    TestHarness::assertContains("formData.append('generation_provider', GENERATION_PROVIDER)", $reviewSource, 'La selección viaja desde la pantalla de escenas');
    TestHarness::assertContains("'generation_provider' => \$generationProvider", $endpointSource, 'El proveedor queda guardado dentro del trabajo');
    TestHarness::assertTrue(
        !str_contains($endpointSource, 'Database::deductCredit('),
        'Cada escena descuenta el credito una sola vez dentro de la transaccion del trabajo'
    );
    TestHarness::assertContains('new MockupGenerationDispatcher()', $endpointSource, 'El endpoint devuelve el control despues de despachar el trabajo');
    TestHarness::assertTrue(!str_contains($endpointSource, '$generator->generate('), 'La peticion web ya no ejecuta la generacion pesada');
    TestHarness::assertContains('mockupGenerator($generationProvider)', $workerServiceSource, 'El worker usa el proveedor del trabajo y no el global');
    TestHarness::assertContains("['pending_enqueue', 'queued']", $workerServiceSource, 'El reclamo atomico impide volver a ejecutar un trabajo ya tomado');
    TestHarness::assertContains('Database::failGenerationAndRefund', $workerServiceSource, 'Un fallo terminal devuelve el credito desde el worker');
    TestHarness::assertContains('CloudTasksService::isAvailable()', $dispatcherSource, 'El despacho usa Cloud Tasks cuando la infraestructura esta disponible');
    TestHarness::assertContains("PHP_OS_FAMILY === 'Windows'", $dispatcherSource, 'El entorno local dispone de un worker desacoplado en Windows');
    TestHarness::assertContains('LOCK_EX | LOCK_NB', $queueWorkerSource, 'Cada carril local tiene un unico supervisor activo');
    TestHarness::assertContains("data-generation-provider", $resultsSource, 'Las regeneraciones conservan el proveedor del resultado');
    TestHarness::assertContains('name="generation_provider" value="gemini"', $createScenesSource, 'Create Scenes envia Vertex de forma explicita');
    TestHarness::assertTrue(
        !str_contains($createScenesSource, 'id="generationProviderSelect"'),
        'Create Scenes no muestra el menu tecnico de proveedores'
    );
    TestHarness::assertContains('!in_array($requestedGenerationProvider, $allowedGenerationProviders, true)', $startGenerateSource, 'El servidor bloquea trabajos sin proveedor explicito');
    TestHarness::assertContains("'generation_provider' => \$generationProvider", $startGenerateSource, 'La preparacion de la obra conserva el proveedor elegido');
    TestHarness::assertContains("\$status['scene_redirect']", $jobStatusSource, 'El estado redirige al orquestador estable de escenas');
    TestHarness::assertContains("'&generation_provider='", $jobStatusSource, 'La redireccion conserva Vertex de forma explicita');
    TestHarness::assertTrue(!str_contains($jobStatusSource, "\$status['scene_generation']"), 'El estado no entrega workers directos a la pantalla de espera');
    TestHarness::assertContains('data.scene_redirect', $sceneWaitSource, 'La espera abre el orquestador estable al terminar la obra raiz');
    TestHarness::assertTrue(!str_contains($sceneWaitSource, "fetch('generate_mockup_combination.php'"), 'La espera no ejecuta escenas directamente');
    TestHarness::assertContains('Promise.all(Array.from({ length: workerCount }, () => runNext()))', $reviewSource, 'El orquestador estable mantiene cuatro workers paralelos');
    TestHarness::assertContains("'&scene_flow_job='", $jobStatusSource, 'La primera tanda conserva la identidad del trabajo raiz');
    TestHarness::assertContains("formData.append('scene_flow_job', SCENE_FLOW_JOB)", $reviewSource, 'Cada escena automatica envia la identidad de su tanda');
    TestHarness::assertContains("\$selectorState['idempotency_key'] = \$idempotencyKey", $endpointSource, 'El endpoint identifica de forma estable cada escena automatica');
    TestHarness::assertContains('status IN ("pending_enqueue", "queued", "processing", "done")', $databaseSource, 'Una recarga reutiliza el trabajo activo o terminado sin descontar otro credito');
    TestHarness::assertContains("formData.append('generation_run_id', generationRunId)", $reviewSource, 'Las cuatro escenas comparten la identidad de la ultima generacion');
    TestHarness::assertContains("\$selectorState['generation_run_id'] = \$generationRunId", $endpointSource, 'La identidad de la generacion queda guardada con cada resultado');
    TestHarness::assertContains("'&generation_run='", $activitySource, 'El aviso de finalizacion conserva la tanda recien creada');
    TestHarness::assertContains('Latest generation (', $resultsSource, 'Resultados abre un filtro explicito para la ultima generacion');
    TestHarness::assertContains('data-result-filter="all"', $resultsSource, 'El usuario puede volver a todos los resultados sin salir de la pantalla');
}
