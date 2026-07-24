<?php
declare(strict_types=1);

function run_public_slug_regression_tests(): void
{
    TestHarness::group('Slugs públicos universales y bilingües');

    TestHarness::assertSame(
        'mare-somniorum-vii',
        PublicSlug::universal('  Mare Somniorum VII!  '),
        'el slug universal elimina puntuación, espacios y mayúsculas'
    );
    TestHarness::assertSame(
        'emersio-iii-lux',
        PublicSlug::universal('Emersió III — Lux'),
        'el slug universal translitera acentos y signos'
    );
    TestHarness::assertSame(
        'strata-x-limen-modern-living-room',
        PublicSlug::mockup('strata-x-limen', 'Modern living room'),
        'el mockup comienza con el slug universal de la obra'
    );
    TestHarness::assertSame(
        'strata-x-limen-en-salon-moderno',
        PublicSlug::mockup('strata-x-limen', 'en salón moderno'),
        'el contexto español conserva el mismo prefijo universal'
    );

    $spanish = [
        'seo_title' => 'STRATA X LIMEN en un salón brutalista | Maurizio Valch',
    ];
    TestHarness::assertSame(
        'en-un-salon-brutalista',
        PublicSlug::mockupContext('STRATA X LIMEN', $spanish, ''),
        'el contexto se extrae del contenido editorial sin traducir el nombre de la obra'
    );
    $technical = PublicSlug::technicalMockupContexts(json_encode([
        'combination' => [
            'world_mother_category' => 'Creative Lofts',
            'camera_slot_name' => 'Canvas Corner Close-Up',
        ],
    ], JSON_UNESCAPED_UNICODE));
    TestHarness::assertSame(
        'creative-lofts-canvas-corner-close-up',
        $technical['en'],
        'los mockups históricos usan su escena y cámara reales en inglés'
    );
    TestHarness::assertSame(
        'loft-creativo-detalle-de-esquina-del-lienzo',
        $technical['es'],
        'los mockups históricos traducen su escena y cámara reales al español'
    );

    $used = [];
    TestHarness::assertSame('obra-en-salon', PublicSlug::uniqueMockup('obra-en-salon', $used), 'el primer contexto no recibe números');
    TestHarness::assertSame('obra-en-salon-2', PublicSlug::uniqueMockup('obra-en-salon', $used), 'solo una colisión semántica recibe sufijo');
}
