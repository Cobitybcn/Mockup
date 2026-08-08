<?php
declare(strict_types=1);

function run_editorial_closing_diversity_tests(): void
{
    TestHarness::group('Diversidad e integridad de cierres editoriales');

    // 1. Selección de taxonomía de cierres en DescriptionDiversityEngine
    $context = [
        'width_cm' => 120,
        'height_cm' => 80,
        'surface' => 'dense impasto',
        'territory' => 'structural landscape',
    ];
    $diversity = DescriptionDiversityEngine::select($context, [], 'test-artwork-seed-001');

    TestHarness::assertArrayHasKey('description_closing_type', $diversity, 'la selección incluye la taxonomía de cierre');
    TestHarness::assertArrayHasKey('recent_closing_types_to_avoid', $diversity, 'la selección rastrea cierres recientes a evitar');

    $validTypes = ['material_fact', 'spatial_relation', 'temporal', 'negation', 'unresolved_tension', 'series_position', 'viewer_invitation'];
    TestHarness::assertTrue(
        in_array($diversity['description_closing_type'], $validTypes, true),
        'el tipo de cierre seleccionado pertenece a la taxonomía canónica'
    );

    // 2. Extracción de clave de cierre en EditorialIntegrityPolicy
    $sampleText = "The surface shows thick impasto and incised lines. Quiet presences in a territory where distance itself defines the relationship between forms.";
    $closingKey = EditorialIntegrityPolicy::closingKey($sampleText);

    TestHarness::assertTrue(
        str_contains($closingKey, 'relationship between forms'),
        'closingKey extrae las últimas palabras del texto para comparar la singularidad del cierre'
    );

    $collectedClosings = EditorialIntegrityPolicy::closings(['description' => $sampleText]);
    TestHarness::assertSame(1, count($collectedClosings), 'closings recolecciona correctamente la clave de cierre');

    // 3. Rechazo de cierres formulaicos en español
    $spanishFormulaic = "La obra invita a observar la coexistencia entre la solidez del bloque lateral y la levedad de los trazos lineales.";
    $issuesEs = EditorialIntegrityPolicy::issues(['description' => $spanishFormulaic], 'artwork');
    TestHarness::assertTrue(
        !empty($issuesEs),
        'EditorialIntegrityPolicy rechaza cierres formulaicos en español como "La obra invita a observar..."'
    );

    // 4. Rechazo de cierres formulaicos en inglés
    $englishFormulaic = "Dense cobalt fields sit beside a bare linen strip at the right edge. The piece invites quiet contemplation of the tension between mass and void.";
    $issuesEn = EditorialIntegrityPolicy::issues(['description' => $englishFormulaic], 'artwork');
    TestHarness::assertTrue(
        !empty($issuesEn),
        'EditorialIntegrityPolicy rejects formulaic closings in English like "...invites quiet contemplation..."'
    );

    // 5. El chequeo mira solo la última oración: una mención de "invita" u
    // "observación" en una oración anterior no debe marcar el texto si el
    // cierre real es concreto — este es exactamente el falso positivo que
    // rompió el suite la vez que se probó revisar el texto completo.
    $falsePositiveGuard = "The composition invites close observation of its layered surface. The final passage traces a diagonal seam breaking across the wall's plane.";
    $issuesGuard = EditorialIntegrityPolicy::issues(['description' => $falsePositiveGuard], 'artwork');
    TestHarness::assertTrue(
        empty($issuesGuard),
        'una mención de "invites"/"observation" fuera de la última oración no cuenta como cierre formulaico'
    );
}
