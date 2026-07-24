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

    $sheetSource = (string)file_get_contents(__DIR__ . '/../../app/Services/ArtworkSheetService.php');
    TestHarness::assertContains("EditorialIntegrityPolicy::promptRules('artwork')", $sheetSource, 'legacy artwork analysis also receives the policy');
    TestHarness::assertContains("EditorialIntegrityPolicy::promptRules('mockup')", $sheetSource, 'direct mockup analysis also receives the policy');
}
