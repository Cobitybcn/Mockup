<?php
declare(strict_types=1);

function run_canonical_title_and_paragraph_formatting_tests(): void
{
    TestHarness::group('Normalización canónica de títulos y renderizado de párrafos');

    // 1. Títulos canónicos
    TestHarness::assertSame(
        'STRATA IX — VESTIGIA',
        TitleSuggestionService::formatCanonical('Strata IX — VESTIGIA'),
        'convierte el prefijo de la serie a mayúsculas sostenidas (Strata -> STRATA)'
    );

    TestHarness::assertSame(
        'STRATA VIII — TRIA',
        TitleSuggestionService::formatCanonical('STRATA VIII - TRIA'),
        'reemplaza el guion corto simple por la raya o em-dash canónico'
    );

    TestHarness::assertSame(
        'STRATA IX — VESTIGIA',
        TitleSuggestionService::formatCanonical('STRATA IX – VESTIGIA'),
        'reemplaza el en-dash por em-dash canónico'
    );

    TestHarness::assertSame(
        'GRADUS',
        TitleSuggestionService::formatCanonical('GRADUS'),
        'mantiene títulos individuales sin serie exactamente igual'
    );

    // 2. Renderizado de párrafos HTML y reparación de espacios pegados
    require_once dirname(__DIR__, 2) . '/../artist-site/inc/functions.php';

    $rawConcept = "background.Dense impasto on canvas.\n\nperception.Across the surface, subtle incised lines reveal layers.";
    $rendered = render_published_paragraphs($rawConcept);

    TestHarness::assertTrue(
        str_contains($rendered, '<p>background. Dense impasto on canvas.</p>'),
        'separa oraciones pegadas con espacio y envuelve el primer párrafo en <p>'
    );

    TestHarness::assertTrue(
        str_contains($rendered, '<p>perception. Across the surface, subtle incised lines reveal layers.</p>'),
        'envuelve el segundo párrafo en <p>'
    );
}
