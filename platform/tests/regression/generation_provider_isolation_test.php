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
    $resultsSource = (string)file_get_contents(__DIR__ . '/../../mockup_combination_results.php');
    $createScenesSource = (string)file_get_contents(__DIR__ . '/../../create_scenes.php');
    $startGenerateSource = (string)file_get_contents(__DIR__ . '/../../start_generate.php');
    $jobStatusSource = (string)file_get_contents(__DIR__ . '/../../job_status.php');
    $sceneWaitSource = (string)file_get_contents(__DIR__ . '/../../create_scenes_wait.php');
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
    TestHarness::assertContains('mockupGenerator($generationProvider)', $workerSource, 'El worker usa el proveedor del trabajo y no el global');
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
}
