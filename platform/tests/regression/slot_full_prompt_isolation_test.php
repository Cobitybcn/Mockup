<?php
declare(strict_types=1);

function run_slot_full_prompt_isolation_tests(): void
{
    TestHarness::group('Slot full prompt: aislamiento total del prompt compuesto');

    $slots = [
        'detalle_textura_lienzo' => [
            'name' => 'Detalle de Textura del Lienzo',
            'marker' => 'ATMOSPHERE LIMITS:',
        ],
        'borde_canvas_closeup' => [
            'name' => 'Borde de Canvas Close-up',
            'marker' => 'ATMOSPHERE LIMITS:',
        ],
        'esquina_obra_perspectiva_extrema' => [
            'name' => 'Corte Agresivo de Esquina de Obra',
            'marker' => 'CANVAS GEOMETRY:',
        ],
        'rasante_superficie_pintura' => [
            'name' => 'Rasante de Superficie Pictórica',
            'marker' => 'TEXTURE LIMITS:',
        ],
        'nadir_extremo_arquitectonico' => [
            'name' => 'Nadir Extremo Arquitectónico',
            'marker' => 'WORLD MOTHER LIMITS:',
        ],
        'obra_apoyada_suelo_7_8' => [
            'name' => 'Obra Apoyada en Suelo 7/8',
            'marker' => 'FLOOR-LEANING INSTALLATION:',
        ],
        'diagonal_estudio_moderno' => [
            'name' => 'Diagonal Moderna de Estudio',
            'marker' => 'DIAGONAL COMPOSITION:',
        ],
        'luz_dorada_sombra_diagonal' => [
            'name' => 'Luz Dorada y Sombra Diagonal',
            'marker' => 'LIGHT AND SHADOW:',
        ],
        'contrapicado_raton_puro' => [
            'name' => 'Nadir Controlado',
            'marker' => 'ARTWORK INTEGRITY:',
        ],
        'contrapicado_7_8' => [
            'name' => 'Contrapicado 7/8',
            'marker' => 'COMPOSITION:',
        ],
        'reflejo_dorado_tarde_palazzo' => [
            'name' => 'Reflejo Dorado de Tarde / Palazzo',
            'marker' => 'LIGHT:',
        ],
        'vista_aerea_contexto_ventanas' => [
            'name' => 'Nadir Aéreo con Contexto de Ventanas',
            'marker' => 'COMPOSITION:',
        ],
        'pasillo_obra_descentrada_proxima' => [
            'name' => 'Pasillo, Obra Descentrada y Próxima',
            'marker' => 'FOCUS:',
        ],
        'borgona_recovecos_3_4_loft_hormigon' => [
            'name' => 'Borgoña Recovecos / Cámara 3/4 en Loft de Hormigón',
            'marker' => 'DEPTH:',
        ],
    ];
    $forbiddenFragments = [
        'IMAGE ROLE CONTRACT',
        'MOCKUP CONTEXT PROPOSAL',
        'ROOT ARTWORK VISUAL FIDELITY POLICY',
        'INHERITED_NEGATIVE_SHOULD_NOT_APPEAR',
        '{{ARTWORK_TITLE}}',
        '{{ARTWORK_SIZE_CLASS}}',
        '{{NEGATIVE_PROMPT}}',
    ];

    foreach ($slots as $slotId => $slotSpec) {
        $prompt = (new AdminPromptComposerPreview())->compose([
            'artwork_id' => 0,
            'camera_slot_id' => $slotId,
            'camera_slot_name' => $slotSpec['name'],
            'negative_prompt' => 'INHERITED_NEGATIVE_SHOULD_NOT_APPEAR',
        ]);

        TestHarness::assertTrue(
            AdminPromptComposerPreview::hasSlotFullPromptTemplate($slotId),
            "{$slotId} declara full_prompt_template"
        );
        TestHarness::assertContains(
            "Camera Slot ID: {$slotId}",
            $prompt,
            "{$slotId}: el prompt compuesto sale del slot especifico"
        );
        TestHarness::assertContains(
            $slotSpec['marker'],
            $prompt,
            "{$slotId}: el prompt del slot contiene su bloque especifico de limites"
        );
        TestHarness::assertContains(
            'WORLD MOTHER ARTWORK QUARANTINE:',
            $prompt,
            "{$slotId}: el prompt aislado incluye cuarentena contra obras de IMAGE 2"
        );
        TestHarness::assertContains(
            'ENVIRONMENTAL SCALE REASONING POLICY',
            $prompt,
            "{$slotId}: el prompt aislado incluye razonamiento ambiental de escala"
        );
        TestHarness::assertContains(
            'IMAGE 1 is the only artwork content allowed',
            $prompt,
            "{$slotId}: el prompt aislado prohibe copiar contenido artistico de IMAGE 2"
        );
        TestHarness::assertContains(
            'WORLD MOTHER AUTHORITY POLICY',
            $prompt,
            "{$slotId}: IMAGE 2 se limita a inspiracion ambiental y no controla la composicion"
        );
        TestHarness::assertContains(
            'WORLD MOTHER ROLE: ENVIRONMENTAL INSPIRATION ONLY',
            $prompt,
            "{$slotId}: el resultado debe ser una escena nueva y no una copia de IMAGE 2"
        );

        foreach ($forbiddenFragments as $fragment) {
            TestHarness::assertTrue(
                !str_contains($prompt, $fragment),
                "{$slotId}: el prompt aislado no contiene '{$fragment}'"
            );
        }
    }

    $geminiFinalPrompt = new ReflectionMethod(GeminiMockupGenerator::class, 'finalPrompt');
    $geminiFinalPrompt->setAccessible(true);
    TestHarness::assertSame(
        'SLOT_ONLY_PROMPT',
        $geminiFinalPrompt->invoke(new GeminiMockupGenerator(), '123', 'SLOT_ONLY_PROMPT', ['slot_full_prompt_mode' => true]),
        'GeminiMockupGenerator no aplica enhancer en slot_full_prompt_mode'
    );

    $openAiFinalPrompt = new ReflectionMethod(OpenAIMockupGenerator::class, 'finalPrompt');
    $openAiFinalPrompt->setAccessible(true);
    TestHarness::assertSame(
        'SLOT_ONLY_PROMPT',
        $openAiFinalPrompt->invoke(new OpenAIMockupGenerator(), '123', 'SLOT_ONLY_PROMPT', ['slot_full_prompt_mode' => true]),
        'OpenAIMockupGenerator no aplica enhancer en slot_full_prompt_mode'
    );
}
