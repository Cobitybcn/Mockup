<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

echo "<h1>Mockup Scale Rules Verification</h1>";

// Initialize class
$composer = new AdminPromptComposerPreview();

// 1. Create temporary mock core files
$coreDir = __DIR__ . '/analysis/core';
if (!is_dir($coreDir)) {
    mkdir($coreDir, 0775, true);
}

function run_test_case(int $testArtworkId, ?array $dims, ?array $physRef, $testName) {
    global $composer, $coreDir;
    $filePath = $coreDir . '/' . $testArtworkId . '.core.json';
    
    $data = [
        'core_schema_version' => '1.1',
        'artwork' => [
            'artwork_id' => $testArtworkId,
            'dimensions' => $dims
        ],
        'physical_artwork_reference' => $physRef
    ];
    
    file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT));
    
    // Simulate context proposal
    $contextProposal = [
        'artwork_id' => $testArtworkId,
        'context_name' => 'Test Room',
        'context_json' => json_encode([
            'camera_view' => 'frontal view',
            'camera_distance' => 'close-up view',
        ])
    ];
    
    echo "<h2>Test Case: {$testName}</h2>";
    try {
        $prompt = $composer->compose($contextProposal);
        
        // Extract PHYSICAL ARTWORK RULES section
        if (preg_match('/PHYSICAL ARTWORK RULES:(.*?)(?=ARTWORK PRIORITY|MOCKUP CONTEXT PROPOSAL)/s', $prompt, $matches)) {
            echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc;'>" 
                . htmlspecialchars(trim($matches[1])) 
                . "</pre>";
        } else {
            echo "<pre style='background:#fff5f5; padding:10px; border:1px solid red; color:red;'>"
                . "Could not isolate PHYSICAL ARTWORK RULES section! Full prompt preview:\n"
                . htmlspecialchars(substr($prompt, 0, 1000))
                . "</pre>";
        }
    } catch (Throwable $e) {
        echo "Error: " . htmlspecialchars($e->getMessage()) . "<br>";
    }
    
    // Clean up
    if (is_file($filePath)) {
        unlink($filePath);
    }
}

// Case A: Dimensions exist with depth
run_test_case(99991, ['width_cm' => 150, 'height_cm' => 100, 'depth_cm' => 5], null, "Dimensions and depth fully present");

// Case B: Dimensions present but depth missing (should not show depth)
run_test_case(99992, ['width_cm' => 90, 'height_cm' => 60], null, "Dimensions present, depth missing");

// Case C: Depth defined in physical_artwork_reference preferred
run_test_case(99993, ['width_cm' => 80, 'height_cm' => 80, 'depth_cm' => 2], ['depth_cm' => 3], "Depth in physical_artwork_reference overrides artwork.dimensions");

// Case D: Missing dimensions (should use relative scale fallback)
run_test_case(99994, ['width_cm' => null, 'height_cm' => null], null, "Missing dimensions");

echo "<h3>Scale verification test finished.</h3>";
