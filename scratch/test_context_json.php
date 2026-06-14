<?php
putenv('PYTHONIOENCODING=utf-8');
putenv('PYTHONUTF8=1');
require_once __DIR__ . '/../app/bootstrap.php';

$jobId = 'job_test_real_1781266499_2157';
$jobDir = __DIR__ . '/../jobs/' . $jobId;
$statusFile = $jobDir . '/status.json';

$status = json_decode(file_get_contents($statusFile), true);
ProviderSettings::set($status['provider_settings'] ?? []);

$db = Database::connection();
$stmtArtwork = $db->prepare("SELECT id FROM artworks WHERE job_id = :job_id LIMIT 1");
$stmtArtwork->execute(['job_id' => $jobId]);
$artworkRow = $stmtArtwork->fetch();
$artworkId = $artworkRow ? (int)$artworkRow['id'] : null;

$measurements = $status['measurements'] ?? [];
$artistProfile = ArtistProfile::findForUser((int)$status['user_id']);

$metadata = [
    'title' => $status['title'] ?? 'Sin título',
    'artist_notes' => $status['artist_notes'] ?? '',
    'region' => '',
    'artist_profile' => $artistProfile,
    'artist_profile_prompt' => ArtistProfile::forPrompt($artistProfile),
    'width_cm' => $measurements['unit'] === 'cm' ? ($measurements['width'] ?? null) : null,
    'height_cm' => $measurements['unit'] === 'cm' ? ($measurements['height'] ?? null) : null,
    'depth_cm' => $measurements['unit'] === 'cm' ? ($measurements['depth'] ?? null) : null,
    'target_market' => $artistProfile['target_audience'] ?? 'collectors',
    'preferred_style' => $status['preferred_style'] ?? '',
];

$engine = new MockupContextEngine();

// Replicate code of analyzeArtworkContext but print raw response!
$refImageMeta = new ReflectionMethod($engine, 'imageMeta');
$refImageMeta->setAccessible(true);
$imagePath = __DIR__ . '/../results/base_artwork_ai_1781266657_1052.png'; // Let's check results dir later if it's named differently
if (!is_file($imagePath)) {
    // Find png in results directory
    $files = glob(__DIR__ . '/../results/*.png');
    if ($files) {
        $imagePath = $files[0];
    }
}
echo "Image path: $imagePath\n";

$imageMeta = $refImageMeta->invoke($engine, $imagePath, $metadata);
$refBuildAnalysisPrompt = new ReflectionMethod($engine, 'buildAnalysisPrompt');
$refBuildAnalysisPrompt->setAccessible(true);
$prompt = $refBuildAnalysisPrompt->invoke($engine, $metadata, $imageMeta);

$client = new GeminiImageClient();
$parts = [
    $client->textPart($prompt),
    $client->imagePart($imagePath),
];

$model = 'gemini-2.5-flash';
echo "Calling generateText with model $model...\n";
$rawText = $client->generateText($parts, $model);
echo "=== RAW TEXT RESPONSE ===\n";
echo $rawText;
echo "\n=========================\n";

$refExtractJson = new ReflectionMethod($engine, 'extractJson');
$refExtractJson->setAccessible(true);
$json = $refExtractJson->invoke($engine, $rawText);
echo "=== EXTRACTED JSON ===\n";
echo $json;
echo "\n======================\n";

$profile = json_decode($json, true);
echo "Is array: " . (is_array($profile) ? 'YES' : 'NO') . "\n";
echo "Has proposals: " . (!empty($profile['contextual_proposals']) ? 'YES' : 'NO') . "\n";
if (is_array($profile)) {
    echo "Keys: " . implode(', ', array_keys($profile)) . "\n";
}
