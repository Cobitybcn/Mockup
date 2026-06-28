<?php
declare(strict_types=1);

/**
 * Phase 2.9 test harness — APPENDS 6 fresh contexts for one artwork and reports
 * the new Vital Presence + canonical world identity fields.
 *
 * SAFE BY DESIGN:
 *   - Does NOT delete or migrate any existing context (unlike regenerate_*).
 *   - Preflight only by default. It calls Gemini + writes 6 rows ONLY when
 *     invoked with &confirm=1 (web) or `confirm` as argv[2] (cli).
 *
 * Usage:
 *   Preflight: scratch/run_phase29_test.php?artwork_id=381
 *   Live run : scratch/run_phase29_test.php?artwork_id=381&confirm=1
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('max_execution_time', '300');
set_time_limit(300);

require_once dirname(__DIR__) . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$artworkId = (int)($_GET['artwork_id'] ?? $_POST['artwork_id'] ?? ($argv[1] ?? 0));
$confirm   = isset($_GET['confirm']) || isset($_POST['confirm']) || (($argv[2] ?? '') === 'confirm');

if ($artworkId <= 0) {
    exit("Provide artwork_id. e.g. ?artwork_id=381\n");
}

$pdo = Database::connection();
$artwork = $pdo->query("SELECT * FROM artworks WHERE id = " . (int)$artworkId . " LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$artwork) {
    exit("Artwork {$artworkId} not found.\n");
}

// Resolve root image on disk (mirror regenerate_mockup_proposals.php)
$image = '';
foreach ([trim((string)($artwork['root_file'] ?? '')), trim((string)($artwork['main_file'] ?? ''))] as $cand) {
    if ($cand === '') { continue; }
    $p = RESULTS_DIR . DIRECTORY_SEPARATOR . basename($cand);
    if (is_file($p)) { $image = $p; break; }
}

$widthCm  = $artwork['width']  ?? null;
$heightCm = $artwork['height'] ?? null;
$depthCm  = $artwork['depth']  ?? null;

echo "=== PHASE 2.9 PREFLIGHT ===\n";
echo "artwork_id      : {$artworkId}\n";
echo "root image      : " . ($image !== '' ? $image : '<<NOT FOUND ON DISK>>') . "\n";
echo "dimensions cm   : " . ($widthCm ?? '?') . " x " . ($heightCm ?? '?') . " x " . ($depthCm ?? '?') . "\n";
echo "real API mode   : " . ((ProviderSettings::isRealMode() && ProviderSettings::allowRealApi()) ? 'YES' : 'NO') . "\n";
echo "context count   : " . PromptSettings::mockupContextCount() . "\n";
echo "existing ctxs   : " . (int)$pdo->query("SELECT COUNT(*) FROM mockup_contexts WHERE artwork_id=" . (int)$artworkId)->fetchColumn() . "\n";
echo "will DELETE old : NO (append-only)\n";
echo "confirm flag    : " . ($confirm ? 'YES -> will generate' : 'NO -> stop after preflight') . "\n\n";

if ($image === '') {
    exit("Cannot generate: root image missing on disk.\n");
}
if (!ProviderSettings::isRealMode() || !ProviderSettings::allowRealApi()) {
    exit("Cannot generate: real API mode disabled (need APP_MODE=gemini, ALLOW_REAL_API=true).\n");
}
if (!$confirm) {
    exit("Preflight OK. Re-run with &confirm=1 to APPEND 6 fresh contexts (calls Gemini).\n");
}

echo "=== GENERATING (append-only, no deletion) ===\n";
$artistProfile = ArtistProfile::findForUser((int)($artwork['user_id'] ?? 0));
$metadata = [
    'title'                 => basename($image),
    'width_cm'              => $widthCm,
    'height_cm'             => $heightCm,
    'depth_cm'              => $depthCm,
    'artist_notes'          => '',
    'artist_profile'        => is_array($artistProfile) ? $artistProfile : [],
    'artist_profile_prompt' => (is_array($artistProfile) && ArtistProfile::hasContent($artistProfile)) ? ArtistProfile::forPrompt($artistProfile) : '',
    'target_market'         => 'collectors',
    'preferred_style'       => '',
    'region'                => '',
];

$before = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM mockup_contexts WHERE artwork_id=" . (int)$artworkId)->fetchColumn();

$engine = new MockupContextEngine();
$analysis = $engine->analyzeArtworkContext($image, $metadata);
$engine->generateMockupPrompts($artworkId, $analysis, $metadata);

$rows = $pdo->query("SELECT id, context_name, context_json, prompt FROM mockup_contexts WHERE artwork_id=" . (int)$artworkId . " AND id > {$before} ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "new contexts    : " . count($rows) . "\n\n";

foreach ($rows as $i => $r) {
    $cj = json_decode((string)$r['context_json'], true) ?: [];
    $vp = $cj['vital_presence'] ?? [];
    echo "--- #" . ($i + 1) . " ctx {$r['id']} : " . $r['context_name'] . " ---\n";
    echo "  selected_world/family/variant : "
        . ($cj['selected_world_id'] ?? '∅') . " / " . ($cj['selected_family_id'] ?? '∅') . " / " . ($cj['selected_variant_id'] ?? '∅') . "\n";
    echo "  identity_source               : " . ($cj['identity_source'] ?? '∅') . "\n";
    echo "  vital_presence.mode           : " . ($vp['mode'] ?? '∅')
        . "  (human_allowed=" . (!empty($vp['human_allowed']) ? 1 : 0)
        . ", organic=" . (!empty($vp['organic_presence_allowed']) ? 1 : 0)
        . ", atmospheric=" . (!empty($vp['atmospheric_presence_allowed']) ? 1 : 0) . ")\n";
    echo "  prompt has VITAL PRESENCE     : " . (strpos((string)$r['prompt'], 'VITAL PRESENCE:') !== false ? 'YES' : 'NO') . "\n";
}
echo "\nDone. Old contexts untouched. View: scratch/view_mockup_slot_audit.php?artwork_id={$artworkId}\n";
