<?php
declare(strict_types=1);

function run_editorial_integrity_policy_tests(): void
{
    TestHarness::group('Editorial integrity for artworks and mockups');

    $englishIssues = EditorialIntegrityPolicy::issues([
        'description' => 'A pivotal work and a museum-quality investment artwork that will increase in value.',
    ], 'artwork');
    TestHarness::assertTrue(
        count($englishIssues) >= 4,
        'the artwork gate rejects unsupported English prestige and investment claims'
    );

    $spanishIssues = EditorialIntegrityPolicy::issues([
        'description' => 'Una obra maestra, pieza de inversión y punto de inflexión en su carrera.',
    ], 'artwork');
    TestHarness::assertTrue(
        count($spanishIssues) >= 3,
        'the artwork gate rejects unsupported Spanish prestige and investment claims'
    );

    TestHarness::assertSame([], EditorialIntegrityPolicy::issues([
        'description' => 'Campos rojos, líneas incisas y una superficie estratificada construyen una presencia visual sobria. La obra pertenece a la serie STRATA y puede resultar de interés para coleccionistas de abstracción contemporánea.',
    ], 'artwork'), 'supported technique, visual evidence, series and collector interest remain allowed');

    $longMockup = implode(' ', array_fill(0, 181, 'espacio'));
    $lengthIssues = EditorialIntegrityPolicy::issues(['description' => $longMockup], 'mockup');
    TestHarness::assertContains('exceeds 180 words', implode(' | ', $lengthIssues), 'mockup analysis is bounded to prevent inflated copy');

    $artworkPrompt = ArtworkAnalysisV2::prompt();
    TestHarness::assertContains('{editorial_integrity_rules}', $artworkPrompt, 'the canonical artwork prompt reserves the shared integrity policy');

    $serviceSource = (string)file_get_contents(__DIR__ . '/../../app/Services/BilingualEditorialAdapterService.php');
    TestHarness::assertContains("EditorialIntegrityPolicy::promptRules(\$entityType)", $serviceSource, 'Spanish generation and English adaptation share the policy');
    TestHarness::assertContains('repairEditorialIntegrityIfNeeded', $serviceSource, 'bilingual generation repairs policy violations before returning content');

    // EDITORIAL_CORE: el flujo legacy de analisis de obra fue eliminado; el
    // analisis canonico (ArtworkAnalysisV2) recibe la politica via su
    // placeholder, verificado arriba.
    $sheetSource = (string)file_get_contents(__DIR__ . '/../../app/Services/ArtworkSheetService.php');
    TestHarness::assertContains("EditorialIntegrityPolicy::promptRules('mockup')", $sheetSource, 'direct mockup analysis also receives the policy');

    // ————— EDITORIAL_CORE Libro II Cap. 3 (enmienda 2026-07-31) —————
    // Alcance: toda descripcion de todo medio, no solo seo_description.
    foreach ([
        'social.pinterest.description' => 'Pinterest',
        'social.website.description' => 'the website',
        'social.facebook.link_description' => 'the Facebook link',
        'social.instagram.caption' => 'the Instagram caption',
        'social.facebook.post_text' => 'the Facebook post',
        'social.tiktok.caption_seed' => 'the TikTok seed',
    ] as $path => $label) {
        $content = [];
        $cursor = &$content;
        foreach (explode('.', $path) as $segment) {
            $cursor[$segment] = [];
            $cursor = &$cursor[$segment];
        }
        $cursor = "Discover INTERVALLUM by Maurizio Valch, a territorial abstract painting in acrylic and oil.";
        unset($cursor);
        $issues = EditorialIntegrityPolicy::issues($content, 'mockup');
        TestHarness::assertContains(
            'action-verb opening',
            implode(' | ', $issues),
            "the command opening is rejected in {$label} description, not only in seo_description"
        );
    }

    // La prohibicion sigue sin alcanzar titulos ni vocabulario comercial de SEO
    // (Libro III Cap. 3): ahi la repeticion estable es deseable.
    TestHarness::assertSame([], EditorialIntegrityPolicy::issues([
        'social' => ['pinterest' => ['title' => 'Discover INTERVALLUM']],
    ], 'mockup'), 'the opening rule governs prose, never titles or keyword vocabulary');

    // No repeticion: entre canales del mismo mockup.
    $sameOpening = 'Bands of intense red meet a luminous yellow across a scraped acrylic surface that holds the wall.';
    $repeated = EditorialIntegrityPolicy::issues([
        'description' => $sameOpening,
        'social' => ['pinterest' => ['description' => $sameOpening]],
    ], 'mockup');
    TestHarness::assertContains(
        'opens exactly like',
        implode(' | ', $repeated),
        'two channels of one mockup cannot share their first line'
    );

    // No repeticion: contra la obra y contra los mockups hermanos.
    $artworkContent = ['description' => $sameOpening];
    $inherited = EditorialIntegrityPolicy::issues(
        ['social' => ['pinterest' => ['description' => $sameOpening]]],
        'mockup',
        EditorialIntegrityPolicy::openings($artworkContent)
    );
    TestHarness::assertContains(
        'already used by this artwork',
        implode(' | ', $inherited),
        'a mockup cannot open like its own artwork or like a sibling mockup'
    );

    // La apertura se compara normalizada: acentos, mayusculas y puntuacion no
    // alcanzan para disfrazar el mismo texto.
    TestHarness::assertSame(
        EditorialIntegrityPolicy::openingKey('Bandas de rojo intenso, ¡frente al amarillo!'),
        EditorialIntegrityPolicy::openingKey('bandas de ROJO intenso frente al amarillo'),
        'the opening comparison ignores case, accents and punctuation'
    );
    TestHarness::assertSame('', EditorialIntegrityPolicy::openingKey('Rojo y negro'), 'a text too short to compare never claims an opening');

    // openings() solo recoge prosa publica — no tags, titulos ni keywords.
    $collected = EditorialIntegrityPolicy::openings([
        'description' => $sameOpening,
        'tags' => ['Painting', 'Abstract Art'],
        'social' => ['pinterest' => ['title' => 'INTERVALLUM', 'description' => 'A classically panelled room holds the canvas against cold marble and daylight.']],
    ]);
    TestHarness::assertSame(2, count($collected), 'the reference collects public prose only');

    // El cableado que hace util la regla: obra y hermanos entran como referencia.
    TestHarness::assertContains('siblingMockupOpenings', $sheetSource, 'direct mockup analysis compares against its siblings');
    TestHarness::assertContains('EditorialIntegrityPolicy::openings($approvedReading)', $sheetSource, 'direct mockup analysis compares against its own artwork');
    TestHarness::assertContains('crossEntityOpenings', $serviceSource, 'the adaptation compares openings in the locale it writes');
}
