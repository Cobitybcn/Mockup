<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Video/VideoGenerationProvider.php';
require_once dirname(__DIR__, 2) . '/app/Video/VideoPromptComposer.php';
require_once dirname(__DIR__, 2) . '/app/Video/VideoReferencePolicy.php';
require_once dirname(__DIR__, 2) . '/app/Video/VertexVeoProvider.php';
require_once dirname(__DIR__, 2) . '/app/Video/VertexGeminiOmniProvider.php';
require_once dirname(__DIR__, 2) . '/app/Video/VideoProviderRegistry.php';

/**
 * Cobertura de no-regresión para los contratos puros del subsistema de Video Studio:
 * - VideoPromptComposer: composición de prompts, guardias de fidelidad y prioridades.
 * - VideoReferencePolicy: límites de referencias, roles únicos y ponderaciones.
 * - VideoProviderRegistry: selección de proveedores (Gemini Omni vs Veo), duraciones y modos.
 */
function run_video_studio_domain_regression_tests(): void
{
    TestHarness::group('Video Studio: compositor de prompts e integridad visual');

    $project = [
        'globalPrompt' => 'Atmósfera de galería de arte contemporáneo con iluminación cenital suave.',
    ];
    $scene = [
        'prompt' => 'La cámara realiza un travellin horizontal lento pasando frente a la obra expuesta en la pared principal.',
    ];

    $promptStandard = VideoPromptComposer::compose($project, $scene, false);

    TestHarness::assertContains(
        'ARTWORK FIDELITY',
        $promptStandard,
        'el prompt de video incluye el bloque inmutable de fidelidad de obra'
    );
    TestHarness::assertContains(
        'Preserve the artwork exactly',
        $promptStandard,
        'el prompt exige explícitamente no deformar ni alterar la obra original'
    );
    TestHarness::assertContains(
        'REFERENCE PRIORITY',
        $promptStandard,
        'el prompt declara el orden estricto de prioridades de referencia'
    );
    TestHarness::assertContains(
        'PROJECT DIRECTION',
        $promptStandard,
        'el prompt incluye la dirección global del proyecto'
    );
    TestHarness::assertContains(
        'SCENE PROMPT',
        $promptStandard,
        'el prompt incluye la instrucción específica de la escena'
    );

    $promptContinuity = VideoPromptComposer::compose($project, $scene, true);
    TestHarness::assertContains(
        'CONTINUITY WITH THE PREVIOUS SCENE',
        $promptContinuity,
        'al activar la continuidad se añade la instrucción para mantener fluidez entre escenas'
    );

    TestHarness::group('Video Studio: política de referencias y roles');

    TestHarness::assertSame(10, VideoReferencePolicy::MAX_IMAGES, 'el límite de imágenes de referencia es 10');
    TestHarness::assertSame(1, VideoReferencePolicy::MAX_VIDEOS, 'el límite de video de referencia es 1');

    TestHarness::assertTrue(
        VideoReferencePolicy::isSingle('artwork_fidelity'),
        'el rol artwork_fidelity es de ocurrencia única'
    );
    TestHarness::assertTrue(
        VideoReferencePolicy::isSingle('character_identity'),
        'el rol character_identity es de ocurrencia única'
    );
    TestHarness::assertTrue(
        VideoReferencePolicy::isSingle('start_frame'),
        'el rol start_frame es de ocurrencia única'
    );
    TestHarness::assertFalse(
        VideoReferencePolicy::isSingle('environment'),
        'el rol environment permite múltiples referencias'
    );

    TestHarness::assertContains(
        'identidad, colores, textura',
        VideoReferencePolicy::defaultInstruction('artwork_fidelity'),
        'la instrucción por defecto del rol artwork_fidelity resguarda la obra'
    );

    TestHarness::assertTrue(
        VideoReferencePolicy::sortWeight('artwork_fidelity') < VideoReferencePolicy::sortWeight('cinematic_style'),
        'la prioridad visual de la obra es mayor que la del estilo cinematográfico'
    );

    TestHarness::group('Video Studio: registro de proveedores y modos de generación');

    TestHarness::assertSame(
        'vertex_gemini_omni',
        VideoProviderRegistry::OMNI,
        'el identificador del proveedor Omni coincide con la constante'
    );
    TestHarness::assertSame(
        'vertex_veo',
        VideoProviderRegistry::VEO,
        'el identificador del proveedor Veo coincide con la constante'
    );

    $durationsOmni = VideoProviderRegistry::durations(VideoProviderRegistry::OMNI);
    TestHarness::assertContains(3, $durationsOmni, 'Omni admite duraciones de 3 segundos');
    TestHarness::assertContains(10, $durationsOmni, 'Omni admite duraciones de hasta 10 segundos');

    $durationsVeo = VideoProviderRegistry::durations(VideoProviderRegistry::VEO);
    TestHarness::assertSame([4, 6, 8], $durationsVeo, 'Veo admite duraciones de 4, 6 y 8 segundos');

    $modesVeo = VideoProviderRegistry::generationModes(VideoProviderRegistry::VEO);
    TestHarness::assertContains('first_last_frame', $modesVeo, 'Veo soporta generación mediante primer y último fotograma');

    $providerOmni = VideoProviderRegistry::make(VideoProviderRegistry::OMNI);
    TestHarness::assertInstanceOf(
        VideoGenerationProvider::class,
        $providerOmni,
        'el registro instancia proveedores que implementan VideoGenerationProvider'
    );
}
