<?php
declare(strict_types=1);

ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
set_time_limit(300);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

// Release session lock
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');

function fail_json(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function resolve_root_image_path_for_regeneration(array $artwork): array
{
    $candidates = [
        trim((string)($artwork['root_file'] ?? '')),
        trim((string)($artwork['main_file'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $safeImage = basename($candidate);
        $imagePath = RESULTS_DIR . DIRECTORY_SEPARATOR . $safeImage;
        if (is_file($imagePath)) {
            return [$safeImage, $imagePath];
        }
    }

    return ['', ''];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail_json('Method not allowed.', 405);
}

$artworkId = isset($_POST['artwork_id']) ? (int)$_POST['artwork_id'] : 0;

if ($artworkId <= 0) {
    fail_json('Missing or invalid artwork_id.');
}

$pdo = Database::connection();

// Verify ownership
$stmtArtwork = $pdo->prepare("
    SELECT * FROM artworks
    WHERE id = :id AND user_id = :user_id
    LIMIT 1
");
$stmtArtwork->execute([
    'id'      => $artworkId,
    'user_id' => (int)$currentUser['id'],
]);
$artwork = $stmtArtwork->fetch();

if (!$artwork) {
    fail_json('Artwork was not found or access denied.', 404);
}

[$safeImage, $imagePath] = resolve_root_image_path_for_regeneration($artwork);

if (!is_file($imagePath)) {
    fail_json('Root image file not found for this artwork. Select a root image before regenerating mockup proposals.', 404);
}

// Check if API mode is real
if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi()) {
    fail_json('Regeneration requires real API mode to be enabled (APP_MODE=gemini, ALLOW_REAL_API=true).');
}

// Load artist profile
$artistProfile       = ArtistProfile::findForUser((int)$currentUser['id']);
$artistProfilePrompt = ArtistProfile::hasContent($artistProfile)
    ? ArtistProfile::forPrompt($artistProfile)
    : '';

// Resolve dimensions
$widthCm  = $artwork['width']  ?? null;
$heightCm = $artwork['height'] ?? null;
$depthCm  = $artwork['depth']  ?? null;

$imageBase = pathinfo($safeImage, PATHINFO_FILENAME);
$metaPath  = RESULTS_DIR . DIRECTORY_SEPARATOR . $imageBase . '.meta.json';

if ((!$widthCm || !$heightCm) && is_file($metaPath)) {
    $metaData = json_decode((string)file_get_contents($metaPath), true);
    if (is_array($metaData) && isset($metaData['measurements'])) {
        $widthCm  = $metaData['measurements']['width']  ?? $widthCm;
        $heightCm = $metaData['measurements']['height'] ?? $heightCm;
        $depthCm  = $metaData['measurements']['depth']  ?? $depthCm;
    }
}

$metadata = [
    'title'                 => $safeImage,
    'width_cm'              => $widthCm,
    'height_cm'             => $heightCm,
    'depth_cm'              => $depthCm,
    'artist_notes'          => '',
    'artist_profile'        => $artistProfile,
    'artist_profile_prompt' => $artistProfilePrompt,
    'target_market'         => trim((string)($artistProfile['target_audience'] ?? 'collectors')),
    'preferred_style'       => '',
    'region'                => trim((string)($artistProfile['preferred_regions'] ?? '')),
];

try {
    $engine = new MockupContextEngine();

    // Call Gemini to generate the new proposals using the ADMIN master prompt
    Logger::log("Mockup contexts regeneration initiated for artwork_id={$artworkId} (user: {$currentUser['id']})", 'gemini');
    $analysisData = $engine->analyzeArtworkContext($imagePath, $metadata);

    // Save and replace proposals inside a transaction
    $pdo->beginTransaction();

    try {
        // Delete only mockup_contexts records
        $stmtDelete = $pdo->prepare("DELETE FROM mockup_contexts WHERE artwork_id = :artwork_id");
        $stmtDelete->execute(['artwork_id' => $artworkId]);
        
        Logger::log(
            "Regeneration deleted old mockup_contexts for artwork_id={$artworkId}, count=" . $stmtDelete->rowCount(),
            'analysis_debug'
        );

        // Generate and save new ones (automatically maps views in engine)
        $engine->generateMockupPrompts($artworkId, $analysisData, $metadata);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Logger::log("Mockup contexts regenerated successfully for artwork_id={$artworkId}", 'gemini');

    echo json_encode([
        'ok'      => true,
        'message' => 'Mockup context proposals regenerated successfully.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    Logger::log("Error in regenerating mockup contexts for artwork_id={$artworkId}: " . $e->getMessage(), 'error');
    fail_json('Error during regeneration: ' . $e->getMessage(), 500);
}
