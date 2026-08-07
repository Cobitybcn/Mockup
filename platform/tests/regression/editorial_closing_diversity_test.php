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
}
