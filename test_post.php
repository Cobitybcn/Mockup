<?php
// test_post.php
declare(strict_types=1);

// Enable error reporting to catch any exception
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app/bootstrap.php';

try {
    $pdo = Database::connection();
    
    // Fetch artwork 418
    $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = 418 LIMIT 1');
    $stmt->execute();
    $artwork = $stmt->fetch();
    
    if (!$artwork) {
        echo "ERROR: Artwork 418 not found in local database.\n";
        exit;
    }
    
    // Simulate login for user_id = 1 (or the artwork's user_id)
    $userId = (int)$artwork['user_id'];
    $_SESSION['user_id'] = $userId;
    echo "Simulating request for user_id: {$userId}, artwork_id: 418\n";
    
    // Resolve combinations using the engine
    $engine = new MockupCombinationEngine($pdo);
    $review = $engine->buildForArtwork(418, [], [
        'selected_world_mother_category' => 'Dark Collector',
        'scene_board_index' => 1
    ]);
    
    $combinations = $review['combinations'] ?? [];
    if (!$combinations) {
        echo "ERROR: No combinations found for this artwork.\n";
        exit;
    }
    
    $firstCombo = $combinations[0];
    echo "First combination found:\n";
    echo " - Slot ID: " . $firstCombo['selected_camera_slot_id'] . "\n";
    echo " - Index: " . $firstCombo['combination_index'] . "\n";
    echo " - Generation Ready: " . ($firstCombo['generation_ready'] ? 'YES' : 'NO') . "\n";
    if (!empty($firstCombo['validation_notes'])) {
        echo " - Validation notes:\n";
        print_r($firstCombo['validation_notes']);
    }
    
    // Set POST variables
    $_POST['artwork_id'] = 418;
    $_POST['combination_index'] = $firstCombo['combination_index'];
    $_POST['camera_slot_id'] = $firstCombo['selected_camera_slot_id'];
    $_POST['world_mother_category'] = 'Dark Collector';
    $_POST['world_mother_variant_offset'] = 0;
    $_POST['board'] = 1;
    
    echo "\nExecuting generate_mockup_combination.php...\n";
    echo "----------------------------------------------\n";
    
    // Execute the endpoint
    include __DIR__ . '/generate_mockup_combination.php';
    
} catch (Throwable $e) {
    echo "\nEXCEPTION TRIGGERED:\n";
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
