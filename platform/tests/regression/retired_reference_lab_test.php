<?php
declare(strict_types=1);

function run_retired_reference_lab_tests(): void
{
    TestHarness::group('Reference LAB retirado');

    $platformRoot = dirname(__DIR__, 2);
    $runtimeSources = [
        'Create Scenes' => (string)file_get_contents($platformRoot . '/create_scenes.php'),
        'Start Generate' => (string)file_get_contents($platformRoot . '/start_generate.php'),
        'Mockup Album' => (string)file_get_contents($platformRoot . '/mockups.php'),
        'Sidebar' => (string)file_get_contents($platformRoot . '/sidebar.php'),
        'Generation Worker' => (string)file_get_contents($platformRoot . '/app/Services/MockupGenerationWorker.php'),
        'Queue Worker' => (string)file_get_contents($platformRoot . '/mockup_queue_worker.php'),
        'Bootstrap' => (string)file_get_contents($platformRoot . '/app/bootstrap.php'),
    ];

    foreach ($runtimeSources as $label => $source) {
        TestHarness::assertTrue(
            !str_contains(strtolower($source), 'visual_dna')
            && !str_contains(strtolower($source), 'visual dna')
            && !str_contains($source, 'studio_references_lab')
            && !str_contains($source, 'reference_set_id'),
            "{$label} no conserva integraciones del LAB retirado"
        );
    }

    foreach ([
        'reference_set_save.php',
        'studio_references_lab.php',
        'studio_references_lab.css',
        'studio_references_lab.js',
        'visual_dna_generate.php',
        'visual_dna_generation_status.php',
        'visual_dna_media.php',
        'visual_dna_reference_import.php',
        'visual_dna_reference_upload.php',
        'app/Services/ReferenceAssetService.php',
        'app/Services/ReferenceSetService.php',
        'app/Services/VisualDnaLabMockupGenerator.php',
    ] as $retiredPath) {
        TestHarness::assertTrue(
            !file_exists($platformRoot . '/' . $retiredPath),
            "la ruta retirada {$retiredPath} no vuelve a publicarse"
        );
    }
}
