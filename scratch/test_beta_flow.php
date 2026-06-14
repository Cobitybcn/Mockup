<?php
declare(strict_types=1);

require_once 'c:/laragon/www/mockups/app/bootstrap.php';

echo "=== STARTING E2E TEST FOR BETA CONTEXTUAL ENGINE (REAL API) ===\n";

// Authenticate as user 1
session_start();
$_SESSION['user_id'] = 1;

$db = Database::connection();

// Create a new artwork record and a new job directory
$jobId = 'job_test_real_' . time() . '_' . random_int(1000, 9999);
$mainFile = 'test.png';
$now = date('c');

$stmt = $db->prepare("
    INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
    VALUES (1, :job_id, :main_file, NULL, 'queued', '100', '80', '2', 'cm', :now, :now)
");
$stmt->execute([
    'job_id' => $jobId,
    'main_file' => $mainFile,
    'now' => $now
]);
$artworkId = (int)$db->lastInsertId();

echo "Created new Artwork ID: $artworkId, Job ID: $jobId\n";

// Create job directory and write dummy file
$jobDir = __DIR__ . '/../jobs/' . $jobId;
if (!is_dir($jobDir)) {
    mkdir($jobDir, 0775, true);
}

// Generate a valid 100x100 black PNG
$img = imagecreatetruecolor(100, 100);
imagepng($img, $jobDir . '/' . $mainFile);
imagedestroy($img);

// Create status.json
$statusFile = $jobDir . '/status.json';
$statusData = [
    'job_id' => $jobId,
    'user_id' => 1,
    'artist_notes' => 'Test curatorial energy and geometric structure.',
    'title' => 'Calma Mineral',
    'main_file' => $mainFile,
    'measurements' => [
        'width' => '100',
        'height' => '80',
        'depth' => '2',
        'unit' => 'cm'
    ],
    'provider_settings' => [
        'app_mode' => 'openai',
        'allow_real_api' => '1',
        'image_provider' => 'gemini',
        'gemini_image_model' => 'gemini-2.5-flash-image'
    ]
];
file_put_contents(
    $statusFile,
    json_encode($statusData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

// 2. Set App mode to openai to run real API analysis
echo "Setting ProviderSettings to openai mode...\n";
ProviderSettings::set([
    'app_mode' => 'openai',
    'allow_real_api' => '1',
    'image_provider' => 'gemini',
    'gemini_image_model' => 'gemini-2.5-flash-image'
]);

// 4. Run process_generate.php
echo "Running process_generate.php in second background/CLI environment...\n";
define('PROCESS_JOB_ID', $jobId);

try {
    ob_start();
    require 'c:/laragon/www/mockups/process_generate.php';
    $output = ob_get_clean();
    echo "CLI Execution Output:\n" . $output . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "CLI Execution Caught Exception: " . $e->getMessage() . "\n";
}

// 5. Verify database insertions
echo "\n--- Database Verification ---\n";
$stmtAnalysis = $db->prepare("SELECT * FROM artwork_analysis WHERE artwork_id = :artwork_id");
$stmtAnalysis->execute(['artwork_id' => $artworkId]);
$analyses = $stmtAnalysis->fetchAll();
echo "Number of entries in artwork_analysis for artwork $artworkId: " . count($analyses) . "\n";
if (!empty($analyses)) {
    echo "Saved analysis JSON:\n" . json_encode(json_decode($analyses[0]['analysis_json']), JSON_PRETTY_PRINT) . "\n";
}

$stmtContexts = $db->prepare("SELECT * FROM mockup_contexts WHERE artwork_id = :artwork_id");
$stmtContexts->execute(['artwork_id' => $artworkId]);
$contexts = $stmtContexts->fetchAll();
echo "Number of entries in mockup_contexts for artwork $artworkId: " . count($contexts) . "\n";
foreach ($contexts as $ctx) {
    echo "- Context Name: " . $ctx['context_name'] . "\n";
    echo "  Prompt snippet: " . substr($ctx['prompt'], 0, 100) . "...\n";
}

echo "\n=== TEST COMPLETED ===\n";
