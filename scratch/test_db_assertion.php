<?php
declare(strict_types=1);

require_once 'c:/laragon/www/mockups/app/bootstrap.php';

echo "=== STARTING DATABASE ASSERTION TEST ===\n";

$db = Database::connection();

// Get the specific test artwork
$stmt = $db->prepare("SELECT * FROM artworks WHERE job_id = :job_id");
$stmt->execute(['job_id' => 'job_test_real_1781266499_2157']);
$artwork = $stmt->fetch();

if (!$artwork) {
    exit("ERROR: Artwork job_test_real_1781266499_2157 not found in database.\n");
}

$artworkId = (int)$artwork['id'];
$jobId = $artwork['job_id'];
$rootFile = $artwork['root_file'];

echo "Artwork ID: $artworkId, Job ID: $jobId, Root File: $rootFile, Status: " . $artwork['status'] . "\n";

// 1. Verify artwork_analysis table
$stmtAnalysis = $db->prepare("SELECT * FROM artwork_analysis WHERE artwork_id = :artwork_id");
$stmtAnalysis->execute(['artwork_id' => $artworkId]);
$analyses = $stmtAnalysis->fetchAll();

echo "Number of entries in artwork_analysis: " . count($analyses) . "\n";
if (!empty($analyses)) {
    echo "SUCCESS: Saved analysis JSON successfully!\n";
} else {
    echo "FAIL: No entry found in artwork_analysis table.\n";
}

// 2. Verify mockup_contexts table
$stmtContexts = $db->prepare("SELECT * FROM mockup_contexts WHERE artwork_id = :artwork_id");
$stmtContexts->execute(['artwork_id' => $artworkId]);
$savedContexts = $stmtContexts->fetchAll();

echo "Number of entries in mockup_contexts: " . count($savedContexts) . "\n";
if (count($savedContexts) >= 2) {
    echo "SUCCESS: Saved mockup contexts successfully!\n";
    foreach ($savedContexts as $idx => $ctx) {
        echo "  [" . ($idx + 1) . "] Context: " . $ctx['context_name'] . "\n";
        echo "      Prompt snippet: " . substr($ctx['prompt'], 0, 80) . "...\n";
    }
} else {
    echo "FAIL: Expected at least 2 mockup contexts, found " . count($savedContexts) . "\n";
}

// 3. Test form2.php loading
echo "\n--- form2.php Load Test ---\n";
if ($rootFile) {
    $_GET = [
        'image' => $rootFile
    ];
    // Start session and mock auth
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['user_id'] = 1;

    ob_start();
    try {
        require 'c:/laragon/www/mockups/form2.php';
        $form2Output = ob_get_clean();
        
        // Assert output contains name of one of the mock contexts we saved
        $hasContextName = false;
        foreach ($savedContexts as $ctx) {
            if ($ctx['context_name'] !== '' && str_contains($form2Output, $ctx['context_name'])) {
                $hasContextName = true;
                break;
            }
        }

        if ($hasContextName && str_contains($form2Output, 'propuestas de contexto para esta obra')) {
            echo "SUCCESS: form2.php loaded successfully and rendered the dynamic contexts!\n";
        } else {
            echo "FAIL: form2.php output did not render dynamic context cards or name.\n";
            echo "Output length: " . strlen($form2Output) . " bytes.\n";
            file_put_contents('scratch/form2_error_output.html', $form2Output);
        }
    } catch (Throwable $e) {
        // Ob_get_clean will end the buffer if exception happens
        $form2Output = ob_get_clean();
        echo "ERROR loading form2.php: " . $e->getMessage() . "\n";
        echo "Line: " . $e->getLine() . " in file: " . $e->getFile() . "\n";
        echo "Output snippet before error:\n" . substr($form2Output, -500) . "\n";
    }
} else {
    echo "ERROR: Root file was not set on artwork.\n";
}

echo "=== DATABASE ASSERTION TEST COMPLETED ===\n";
