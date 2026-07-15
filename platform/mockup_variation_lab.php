<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$labFlowMode = (string)($_COOKIE['sidebar_flow_mode'] ?? '');
$useSimpleLab = !$isAdmin || $labFlowMode === 'normal';
$pdo = Database::connection();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function lab_thumb_url(string $file, int $width = 640): string
{
    $file = basename($file);
    return $file !== '' ? 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=' . max(240, min(1200, $width)) : '';
}

function mockup_variation_lab_register_run(PDO $pdo, array $run, array $selectedMockup, string $labDir): array
{
    if ((int)($run['registered_mockup_id'] ?? 0) > 0 || ($run['status'] ?? '') !== 'generated') {
        return $run;
    }

    $outputFile = basename((string)($run['output_file'] ?? ''));
    if ($outputFile === '') {
        return $run;
    }

    $sourcePath = $labDir . DIRECTORY_SEPARATOR . $outputFile;
    if (!is_file($sourcePath)) {
        return $run;
    }

    $baseName = pathinfo($outputFile, PATHINFO_FILENAME);
    $registeredMockupFile = $baseName . '-mockup.png';
    $registeredPromptFile = $baseName . '-prompt.txt';
    $promptFile = basename((string)($run['prompt_file'] ?? ''));
    $promptSourcePath = $promptFile !== '' ? $labDir . DIRECTORY_SEPARATOR . $promptFile : '';
    $promptText = is_file($promptSourcePath) ? (string)file_get_contents($promptSourcePath) : '';
    $rootFile = basename((string)($selectedMockup['artwork_file'] ?? ''));
    $sourceArtworkId = (int)($selectedMockup['source_artwork_id'] ?? 0);
    if ($sourceArtworkId <= 0 && is_array($selectedMockup['artwork'] ?? null)) {
        $sourceArtworkId = (int)($selectedMockup['artwork']['id'] ?? 0);
    }
    $artworkGroupId = (int)($selectedMockup['artwork_group_id'] ?? 0);
    if ($artworkGroupId <= 0 && is_array($selectedMockup['artwork'] ?? null)) {
        $artworkGroupId = (int)($selectedMockup['artwork']['artwork_group_id'] ?? 0);
    }

    copy($sourcePath, RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile);
    if ($promptText !== '') {
        file_put_contents(PROMPTS_DIR . DIRECTORY_SEPARATOR . $registeredPromptFile, $promptText);
    }

    if (StorageService::isGcsActive()) {
        if (!StorageService::uploadFile('results/' . $registeredMockupFile, RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile)) {
            throw new RuntimeException('The variation could not be saved to persistent storage.');
        }
        if ($promptText !== '' && !StorageService::uploadFile(
            'mockup-prompts/' . $registeredPromptFile,
            PROMPTS_DIR . DIRECTORY_SEPARATOR . $registeredPromptFile
        )) {
            throw new RuntimeException('The variation prompt could not be saved to persistent storage.');
        }
    }

    $selectorState = [
        'generation_source' => 'mockup_variation_lab',
        'source_mockup_id' => (int)($run['mockup_id'] ?? $selectedMockup['id'] ?? 0),
        'variation_type' => (string)($run['variation_type'] ?? ''),
        'reference_mode' => (string)($run['reference_mode'] ?? ''),
        'human_presence' => (string)($run['human_presence'] ?? ''),
        'artwork_scale' => (string)($run['artwork_scale'] ?? ''),
        'lighting_modifier' => (string)($run['lighting_modifier'] ?? ''),
        'camera_modifier' => (string)($run['camera_modifier'] ?? ''),
        'camera_strength' => (string)($run['camera_strength'] ?? ''),
        'custom_instruction' => (string)($run['custom_instruction'] ?? ''),
        'input_mockup_file' => (string)($run['input_mockup_file'] ?? ''),
        'input_root_file' => (string)($run['input_root_file'] ?? $rootFile),
        'input_world_mother_file' => (string)($run['input_world_mother_file'] ?? ''),
        'lab_output_file' => 'storage/experiments/mockup-variation-lab/' . $outputFile,
        'lab_prompt_file' => $promptFile !== '' ? 'storage/experiments/mockup-variation-lab/' . $promptFile : '',
        'lab_audit_file' => !empty($run['audit_file']) ? 'storage/experiments/mockup-variation-lab/' . basename((string)$run['audit_file']) : '',
    ];

    $registeredMockupId = (int)Database::withBusyRetry(function () use (
        $selectedMockup,
        $rootFile,
        $sourceArtworkId,
        $artworkGroupId,
        $registeredMockupFile,
        $registeredPromptFile,
        $promptText,
        $selectorState
    ): int {
        $insert = Database::connection()->prepare("
            INSERT INTO mockups (user_id, artwork_group_id, source_artwork_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_group_id, :source_artwork_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $insert->execute([
            'user_id' => (int)$selectedMockup['user_id'],
            'artwork_group_id' => $artworkGroupId > 0 ? $artworkGroupId : null,
            'source_artwork_id' => $sourceArtworkId > 0 ? $sourceArtworkId : null,
            'artwork_file' => $rootFile,
            'mockup_file' => $registeredMockupFile,
            'context_id' => 'variation_lab',
            'prompt_file' => $promptText !== '' ? $registeredPromptFile : null,
            'selector_state_json' => json_encode($selectorState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => date('c'),
        ]);

        return (int)Database::connection()->lastInsertId();
    }, 12);

    $run['registered_mockup_id'] = $registeredMockupId;
    $run['registered_mockup_file'] = $registeredMockupFile;
    $run['registered_prompt_file'] = $promptText !== '' ? $registeredPromptFile : '';

    if (!empty($run['audit_file'])) {
        $auditPath = $labDir . DIRECTORY_SEPARATOR . basename((string)$run['audit_file']);
        if (is_file($auditPath)) {
            file_put_contents($auditPath, json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    return $run;
}

$selectedArtworkId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$selectedMockupId = max(0, (int)($_GET['mockup_id'] ?? 0));

$artworksByRoot = [];
$artworksById = [];
$stmt = $pdo->prepare('SELECT * FROM artworks WHERE user_id = :user_id ORDER BY created_at DESC');
$stmt->execute(['user_id' => (int)$user['id']]);
foreach ($stmt->fetchAll() ?: [] as $artwork) {
    $artworkId = (int)($artwork['id'] ?? 0);
    if ($artworkId > 0) {
        $artworksById[$artworkId] = $artwork;
    }
    $rootFile = basename((string)($artwork['root_file'] ?? ''));
    if ($rootFile !== '') {
        $artworksByRoot[$rootFile] = $artwork;
    }
}

$stmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id = :user_id ORDER BY id DESC LIMIT 80');
$stmt->execute(['user_id' => (int)$user['id']]);
$allMockups = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $root = basename((string)($row['artwork_file'] ?? ''));
    $row['artwork'] = $artworksByRoot[$root] ?? null;
    if (MockupVariationEligibility::canUseVariationLab($row)) {
        $allMockups[] = $row;
    }
}

$contextArtworkGroupId = $selectedArtworkId > 0 ? (int)($artworksById[$selectedArtworkId]['artwork_group_id'] ?? 0) : 0;
$mockups = $allMockups;
if ($selectedArtworkId > 0) {
    $contextMockups = array_values(array_filter($allMockups, static function (array $mockup) use ($selectedArtworkId, $contextArtworkGroupId): bool {
        $mockupArtworkId = (int)($mockup['source_artwork_id'] ?? 0);
        if ($mockupArtworkId === $selectedArtworkId) {
            return true;
        }

        $attachedArtwork = is_array($mockup['artwork'] ?? null) ? $mockup['artwork'] : [];
        if ((int)($attachedArtwork['id'] ?? 0) === $selectedArtworkId) {
            return true;
        }

        $mockupGroupId = (int)($mockup['artwork_group_id'] ?? 0);
        $attachedGroupId = (int)($attachedArtwork['artwork_group_id'] ?? 0);
        return $contextArtworkGroupId > 0 && ($mockupGroupId === $contextArtworkGroupId || $attachedGroupId === $contextArtworkGroupId);
    }));
    if ($contextMockups) {
        $mockups = $contextMockups;
    }
}
usort($mockups, static fn(array $a, array $b): int => (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));

if ($selectedMockupId > 0) {
    $selectedMockupIsInContext = false;
    foreach ($mockups as $mockup) {
        if ((int)$mockup['id'] === $selectedMockupId) {
            $selectedMockupIsInContext = true;
            break;
        }
    }
    if (!$selectedMockupIsInContext) {
        $selectedMockupId = 0;
    }
}

if ($selectedMockupId <= 0 && $mockups) {
    $selectedMockupId = (int)$mockups[0]['id'];
}

$selectedMockup = null;
foreach ($mockups as $row) {
    if ((int)$row['id'] === $selectedMockupId) {
        $selectedMockup = $row;
        break;
    }
}
$mockupsById = [];
foreach ($mockups as $mockup) {
    $mockupsById[(int)$mockup['id']] = $mockup;
}
$modificationHistory = [];
$activeChainIds = $selectedMockupId > 0 ? [$selectedMockupId => true] : [];
$lineageCursor = $selectedMockup;
for ($depth = 0; $depth < 16 && is_array($lineageCursor); $depth++) {
    $lineageState = json_decode((string)($lineageCursor['selector_state_json'] ?? ''), true);
    if (!is_array($lineageState) || ($lineageState['generation_source'] ?? '') !== 'mockup_variation_lab') {
        break;
    }
    $parentId = (int)($lineageState['source_mockup_id'] ?? 0);
    if ($parentId <= 0 || isset($activeChainIds[$parentId]) || !isset($mockupsById[$parentId])) {
        break;
    }
    $parent = $mockupsById[$parentId];
    $modificationHistory[] = $parent;
    $activeChainIds[$parentId] = true;
    $lineageCursor = $parent;
}
$otherVariations = array_values(array_filter($mockups, static function (array $mockup) use ($activeChainIds): bool {
    return !isset($activeChainIds[(int)($mockup['id'] ?? 0)]);
}));
$otherVariations = array_slice($otherVariations, 0, 16);
if ($selectedMockup && $selectedArtworkId <= 0) {
    $selectedArtworkId = (int)($selectedMockup['source_artwork_id'] ?? 0);
    if ($selectedArtworkId <= 0 && is_array($selectedMockup['artwork'] ?? null)) {
        $selectedArtworkId = (int)($selectedMockup['artwork']['id'] ?? 0);
    }
}
$labContextQuery = $selectedArtworkId > 0 ? 'id=' . rawurlencode((string)$selectedArtworkId) . '&' : '';

$labDir = __DIR__ . '/storage/experiments/mockup-variation-lab';
$labRuns = [];
foreach (glob($labDir . DIRECTORY_SEPARATOR . '*.audit.json') ?: [] as $auditPath) {
    $audit = json_decode((string)file_get_contents($auditPath), true);
    if (!is_array($audit) || (int)($audit['requested_by_user_id'] ?? 0) !== (int)$user['id']) {
        continue;
    }
    if ($selectedMockupId > 0 && (int)($audit['mockup_id'] ?? 0) !== $selectedMockupId) {
        continue;
    }
    $audit['audit_file'] = basename($auditPath);
    $audit['_sort_timestamp'] = max(
        strtotime((string)($audit['started_at'] ?? '')) ?: 0,
        (int)(filemtime($auditPath) ?: 0)
    );
    $labRuns[] = $audit;
}
usort($labRuns, static function (array $a, array $b): int {
    $timeCompare = (int)($b['_sort_timestamp'] ?? 0) <=> (int)($a['_sort_timestamp'] ?? 0);
    if ($timeCompare !== 0) {
        return $timeCompare;
    }
    return strcmp((string)($b['audit_file'] ?? ''), (string)($a['audit_file'] ?? ''));
});
$labRuns = array_slice($labRuns, 0, 16);
if ($selectedMockup) {
    foreach ($labRuns as $index => $run) {
        $labRuns[$index] = mockup_variation_lab_register_run($pdo, $run, $selectedMockup, $labDir);
    }
}
$favoriteLookup = MockupFavorites::lookupForUser((int)$user['id']);

$referenceModes = [
    'mockup_only' => 'A - Existing mockup only',
    'mockup_root' => 'B - Mockup + root artwork',
    'mockup_root_strict' => 'C - Strict mockup + root artwork',
];
$humanOptions = [
    'none' => ['title' => 'None', 'detail' => ''],
    'female_160' => ['title' => 'Female', 'detail' => '1.80 m'],
    'male_180' => ['title' => 'Male', 'detail' => '2.00 m'],
];
$scaleOptions = [
    'scale_minus_60' => '-60%',
    'scale_minus_40' => '-40%',
    'scale_minus_20' => '-20%',
    'none' => '0',
    'scale_plus_20' => '+20%',
    'scale_plus_40' => '+40%',
    'scale_plus_60' => '+60%',
];
$lightingOptions = [
    'none' => 'No lighting change',
    'light_day' => 'Daylight',
    'light_overcast' => 'Overcast',
    'light_night' => 'Night light',
    'light_golden' => 'Golden hour',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Lab - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .lab-grid { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); gap: 18px; align-items: start; min-width: 0; }
        .lab-panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 16px; min-width: 0; }
        .lab-main-column {
            display: grid;
            gap: 16px;
            min-width: 0;
        }
        .lab-selector-panel {
            display: block;
            margin: 0;
            padding: 14px;
            overflow: hidden;
        }
        .lab-control-panel {
            padding-bottom: 12px;
        }
        .lab-stage { padding: 18px; overflow: hidden; }
        .lab-header-v3 {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 36px;
            padding: 6px 0 24px;
            margin-bottom: 26px;
            border-bottom: 1px solid var(--line);
        }
        .lab-header-v3 .header-main-info {
            display: block;
            flex: 1;
            min-width: 0;
        }
        .lab-header-v3 h1 {
            margin: 0 0 18px;
            font-size: 44px;
            line-height: 1;
            font-family: var(--font-serif);
            font-weight: 500;
        }
        .mobile-lab-title {
            display: none;
        }
        .mobile-mockup-overlays {
            display: none;
        }
        .mobile-active-apply,
        .mobile-cascade-history,
        .mobile-prompt-details {
            display: none;
        }
        .lab-page-desc {
            margin: 0;
            line-height: 1.55;
        }
        .lab-page-desc .desc-kicker {
            display: block;
            font-size: 14px;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .lab-page-desc .desc-instructions {
            display: block;
            max-width: 900px;
            font-size: 16px;
            font-weight: 600;
            color: var(--accent);
        }
        .lab-primary-action {
            flex: 0 0 150px;
            align-self: flex-start;
            padding-top: 2px;
        }
        .lab-primary-action .lab-run-primary,
        .lab-bottom-action .lab-run-primary {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            zoom: 1 !important;
            width: 150px !important;
            min-width: 150px !important;
            height: 150px !important;
            min-height: 150px !important;
            margin: 0 !important;
            padding: 20px !important;
            border-radius: 4px;
            font-size: 13px !important;
            line-height: 1.32 !important;
            text-align: center;
            white-space: normal;
            background: #b77f86 !important;
            border-color: #b77f86 !important;
            color: #fffaf7 !important;
        }
        .lab-primary-action .lab-run-primary:hover,
        .lab-bottom-action .lab-run-primary:hover {
            background: #a86f77 !important;
            border-color: #a86f77 !important;
        }
        .lab-equation {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(360px, .82fr);
            gap: 14px;
            align-items: stretch;
        }
        .lab-equation-col {
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            padding: 12px;
            min-width: 0;
        }
        .lab-equation-col.result-col {
            background: #f4f0e9;
        }
        .lab-equation-mark {
            align-self: center;
            justify-self: center;
            width: 34px;
            height: 34px;
            border: 1px solid var(--line);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            background: var(--surface);
            font-family: var(--font-serif);
            font-size: 20px;
        }
        .lab-equation-title {
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .lab-panel h2,
        .lab-form > label,
        .control-label {
            margin: 0 0 6px;
            font-size: 13px;
            font-family: var(--font-serif);
            font-weight: 600;
            color: var(--ink);
        }
        .lab-visual-title {
            margin: 0 0 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            padding: 14px 16px 12px;
        }
        .lab-visual-title h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.1;
        }
        .lab-visual-title p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 12px;
        }
        .lab-stage-link {
            margin: 0 0 10px;
            font-size: 12px;
        }
        .lab-form { display: grid; gap: 9px; }
        .lab-form > label {
            display: grid;
            gap: 8px;
            margin: 0;
        }
        .control-label {
            display: block;
            margin: 5px 0 2px;
            text-align: center;
        }
        .lab-form select, .lab-form textarea {
            font-family: var(--font-sans);
            font-size: 13px;
            font-weight: 400;
        }
        .segmented-control {
            display: grid;
            gap: 6px;
            grid-template-columns: repeat(auto-fit, minmax(56px, 1fr));
        }
        .segmented-control.human-control { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .segmented-control.lighting-control { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .segmented-control input { position: absolute; opacity: 0; pointer-events: none; }
        .segmented-control input + span {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            border-radius: 6px;
            padding: 8px 6px;
            font-size: 12px;
            color: var(--muted);
            text-align: center;
            cursor: pointer;
            user-select: none;
        }
        .segmented-control input:checked + span {
            background: var(--ink);
            border-color: var(--ink);
            color: var(--surface);
        }
        .segmented-control input:disabled + span {
            opacity: .45;
            cursor: not-allowed;
        }
        .human-control input + span {
            min-height: 46px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.35;
            text-transform: uppercase;
            flex-direction: column;
            gap: 1px;
            padding: 6px 4px;
        }
        .human-control .human-detail {
            font-size: 10px;
            font-weight: 600;
            color: inherit;
            opacity: .72;
            text-transform: none;
        }
        .scale-slider-wrap {
            display: grid;
            gap: 5px;
        }
        .scale-value {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            justify-self: center;
            min-width: 42px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface-soft);
            color: var(--ink);
            padding: 3px 8px;
            font-weight: 700;
            font-size: 11px;
        }
        .scale-slider {
            width: 100%;
            accent-color: var(--ink);
        }
        .scale-ticks {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0;
            color: var(--muted);
            font-size: 11px;
            text-align: center;
        }
        .scale-ticks span::before {
            content: "";
            display: block;
            width: 1px;
            height: 8px;
            background: var(--line);
            margin: 0 auto 4px;
        }
        .lab-form select, .lab-form textarea { width: 100%; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface-soft); color: var(--ink); padding: 10px; }
        .lab-form textarea { min-height: 140px; resize: vertical; }
        .lab-advanced {
            border-top: 1px solid var(--line);
            padding-top: 8px;
        }
        .lab-advanced summary {
            cursor: pointer;
            font-family: var(--font-serif);
            font-size: 15px;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--ink);
            list-style: none;
        }
        .lab-advanced summary::-webkit-details-marker { display: none; }
        .lab-advanced summary::after {
            content: "+";
            float: right;
            color: var(--accent);
        }
        .lab-advanced[open] summary::after { content: "-"; }
        .lab-run-area {
            display: grid;
            gap: 6px;
            margin-top: 0;
            padding-top: 6px;
            background: var(--surface);
        }
        .lab-run-area .lab-note {
            font-size: 11px;
            line-height: 1.35;
        }
        .preview-grid {
            position: relative;
            display: grid;
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            gap: 16px;
            align-items: start;
            height: auto;
        }
        .preview-grid::after {
            content: "+";
            position: absolute;
            left: 50%;
            top: calc(50% + 9px);
            transform: translate(-50%, -50%);
            z-index: 2;
            width: 34px;
            height: 34px;
            border: 1px solid rgba(183, 127, 134, .38);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 250, 247, .96);
            color: #b77f86;
            font-family: var(--font-serif);
            font-size: 24px;
            line-height: 1;
            box-shadow: 0 8px 18px rgba(20, 20, 18, .08);
        }
        .preview-grid.mockup-only {
            grid-template-columns: repeat(2, minmax(260px, 1fr));
            justify-content: stretch;
        }
        .preview-grid.mockup-only .root-reference-box .image-frame img {
            display: none;
        }
        .preview-grid.mockup-only .root-reference-box .image-frame::after {
            content: "No root reference";
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: calc(100% - 32px);
            height: calc(100% - 32px);
            border: 1px dashed rgba(183, 127, 134, .42);
            border-radius: 4px;
            color: #b77f86;
            background: rgba(255, 250, 247, .42);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .preview-box {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            min-width: 0;
        }
        .image-frame {
            aspect-ratio: 4 / 3;
            height: auto;
            border: 1px solid var(--line);
            background:
                radial-gradient(ellipse at 50% 50%, rgba(255, 250, 247, .96) 0 30%, rgba(255, 250, 247, .72) 48%, rgba(183, 127, 134, .22) 100%),
                linear-gradient(90deg, rgba(121, 77, 84, .13), transparent 18%, transparent 82%, rgba(121, 77, 84, .13)),
                linear-gradient(135deg, rgba(255, 255, 255, .38), rgba(183, 127, 134, .18)),
                #ead6d9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: inset 0 0 38px rgba(121, 77, 84, .12);
        }
        .image-frame img {
            width: 100%;
            height: 100%;
            max-height: none;
            object-fit: contain;
            object-position: center;
            display: block;
            border: 0;
            background: transparent;
        }
        .result-card img {
            width: 100%;
            height: 100%;
            max-height: none;
            object-fit: cover;
            object-position: center;
            display: block;
            border: 0;
            background: var(--surface);
        }
        .preview-box strong { display: block; margin-bottom: 6px; font-size: 11px; text-transform: uppercase; color: var(--muted); letter-spacing: .04em; }
        .lab-note { color: var(--muted); font-size: 12px; line-height: 1.5; }
        .lab-status { min-height: 20px; color: var(--muted); font-size: 13px; }
        .runs-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
        .result-card { border: 1px solid var(--line); background: var(--surface); border-radius: var(--radius); padding: 12px; }
        .result-card .meta { color: var(--muted); font-size: 11px; line-height: 1.5; margin-top: 8px; word-break: break-word; }
        .lab-result-media {
            position: relative;
            display: block;
        }
        .runs-grid .lab-result-media {
            height: clamp(220px, 16vw, 280px);
            border: 1px solid var(--line);
            background: #f4f0e9;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .runs-grid .result-card img {
            width: 100%;
            height: 100%;
            border: 0;
            object-fit: contain;
            object-position: center;
            background: #f4f0e9;
        }
        .lab-result-actions {
            position: absolute;
            top: 10px;
            right: 10px;
            display: inline-flex;
            gap: 7px;
            opacity: .32;
            transition: opacity .16s ease;
        }
        .lab-result-media:hover .lab-result-actions,
        .lab-result-actions:focus-within {
            opacity: 1;
        }
        .lab-result-action {
            width: 30px;
            height: 30px;
            border: 1px solid rgba(255, 255, 255, .74);
            border-radius: 999px;
            background: rgba(24, 24, 24, .38);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
            backdrop-filter: blur(7px);
        }
        .lab-result-action.is-favorite {
            background: rgba(166, 128, 86, .72);
        }
        .result-placeholder {
            min-height: clamp(260px, 24vw, 360px);
            border: 1px dashed var(--line);
            background: rgba(255, 255, 255, .38);
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
            padding: 18px;
        }
        .lab-spinner {
            width: 34px;
            height: 34px;
            border-radius: 999px;
            border: 2px solid rgba(166, 128, 86, .22);
            border-top-color: var(--accent);
            animation: labSpin .8s linear infinite;
        }
        .lab-status.is-loading {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .lab-status.is-loading::before {
            content: "";
            width: 14px;
            height: 14px;
            border-radius: 999px;
            border: 2px solid rgba(166, 128, 86, .22);
            border-top-color: var(--accent);
            animation: labSpin .8s linear infinite;
        }
        @keyframes labSpin {
            to { transform: rotate(360deg); }
        }
        .lab-generating-overlay {
            position: fixed;
            right: 22px;
            bottom: 22px;
            z-index: 50;
            display: none;
            align-items: center;
            gap: 10px;
            border: 1px solid rgba(166, 128, 86, .34);
            border-radius: 999px;
            background: rgba(248, 245, 239, .92);
            color: var(--ink);
            padding: 10px 14px 10px 12px;
            box-shadow: 0 14px 40px rgba(34, 28, 20, .16);
            backdrop-filter: blur(8px);
            font-size: 12px;
            font-weight: 700;
        }
        body.lab-is-generating .lab-generating-overlay {
            display: inline-flex;
        }
        #new-result .result-card { padding: 0; border: 0; background: transparent; }
        #new-result .result-card .lab-result-media {
            height: clamp(320px, 36vw, 470px);
            border: 1px solid var(--line);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .lab-mockup-browser {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .lab-mockup-browser > span {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 9px;
            font-family: var(--font-sans);
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .lab-mockup-strip {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 164px;
            gap: 8px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 1px 2px 10px;
            scrollbar-color: #d8cbbb transparent;
            scrollbar-width: thin;
        }
        .lab-mockup-strip::-webkit-scrollbar {
            height: 6px;
        }
        .lab-mockup-strip::-webkit-scrollbar-track {
            background: transparent;
        }
        .lab-mockup-strip::-webkit-scrollbar-thumb {
            background: #d8cbbb;
            border-radius: 999px;
        }
        .lab-mockup-card {
            position: relative;
            display: block;
            min-width: 0;
            padding: 7px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
        }
        .lab-mockup-card:hover,
        .lab-mockup-card.active {
            border-color: #b77f86;
            background: #fbf7ef;
        }
        .lab-mockup-card.active {
            box-shadow: inset 0 0 0 2px #b77f86;
        }
        .lab-mockup-card.active::after {
            content: "Selected";
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 4px 7px;
            border-radius: 3px;
            background: rgba(32, 24, 18, .86);
            color: #fffaf7;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .lab-mockup-card img {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 4;
            height: auto;
            object-fit: cover;
            border-radius: 2px;
            background: var(--surface);
        }
        .lab-mockup-card strong {
            display: block;
            margin-top: 6px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 11px;
            line-height: 1.2;
        }
        .lab-mockup-card small {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.2;
        }
        .lab-select-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            clip-path: inset(50%);
            white-space: nowrap;
        }
        .lab-bottom-action {
            display: none;
        }
        @media (max-width: 980px) {
            .sidebar-mobile-menu {
                display: block !important;
                position: absolute !important;
                top: 50% !important;
                right: 12px !important;
                width: 46px !important;
                height: 38px !important;
                transform: translateY(-50%) !important;
                z-index: 50 !important;
                pointer-events: auto !important;
            }

            .sidebar > .sidebar-mobile-menu:not(.sidebar-mobile-menu-head) {
                display: none !important;
            }

            .sidebar-mobile-menu summary {
                width: 46px !important;
                height: 38px !important;
                border: 1px solid rgba(183, 127, 134, 0.42) !important;
                border-radius: 5px !important;
                background: rgba(255, 250, 247, 0.96) !important;
                box-shadow: 0 10px 24px rgba(28, 23, 20, 0.10) !important;
            }

            .sidebar-mobile-menu summary span {
                width: 22px !important;
                background: #b77f86 !important;
            }

            .sidebar-mobile-menu[open] {
                width: auto !important;
                height: auto !important;
            }

            .sidebar-mobile-panel {
                z-index: 2147483599 !important;
            }

            .lab-header-v3 {
                flex-direction: column;
                align-items: stretch;
                gap: 16px;
            }
            .lab-primary-action {
                flex: 0 0 auto;
                width: 100%;
                padding-right: 0;
            }
            .lab-primary-action .lab-run-primary,
            .lab-bottom-action .lab-run-primary {
                width: 100% !important;
                min-width: 0 !important;
                height: 56px !important;
                min-height: 56px !important;
            }
            .lab-bottom-action {
                display: block;
                margin-top: 18px;
            }
            .lab-equation { grid-template-columns: 1fr; }
            .lab-equation-mark { display: none; }
            .runs-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 1000px) {
            .lab-grid, .preview-grid, .runs-grid { grid-template-columns: 1fr; }
            .preview-grid::after { display: none; }
        }
        <?php if ($useSimpleLab): ?>
        body.lab-user .workspace {
            width: min(100%, 760px);
            margin-left: auto;
            margin-right: auto;
        }
        <?php endif; ?>
        @media (max-width: <?= $useSimpleLab ? '10000px' : '760px' ?>) {
            .app-header {
                display: none;
            }
            .workspace {
                padding-left: 10px;
                padding-right: 10px;
            }
            .alert-strip,
            .lab-page-desc .desc-instructions,
            .lab-primary-action,
            .lab-stage-link,
            .preview-box strong,
            .root-reference-box,
            .lab-equation-mark {
                display: none !important;
            }
            .desktop-lab-title {
                display: none;
            }
            .mobile-lab-title {
                display: inline;
            }
            .lab-header-v3 {
                gap: 8px;
                padding: 0 0 4px;
                margin-bottom: 4px;
            }
            .lab-header-v3 h1 {
                display: none;
            }
            .lab-page-desc {
                max-width: 32rem;
            }
            .lab-page-desc .desc-kicker {
                font-size: 11px;
                line-height: 1.3;
            }
            .lab-grid {
                display: flex;
                flex-direction: column;
                gap: 12px;
                width: 100%;
            }
            .lab-main-column {
                display: contents;
            }
            .lab-selector-panel {
                order: 1;
                position: relative;
                width: 100%;
                margin-left: 0;
                margin-right: 0;
                padding: 10px;
                border-radius: 6px;
                overflow: visible;
            }
            .lab-control-panel {
                display: none;
            }
            .lab-stage {
                display: none;
            }
            .lab-mockup-browser > span {
                display: none;
            }
            .lab-mockup-strip {
                position: relative;
                display: grid;
                grid-auto-flow: column;
                grid-auto-columns: 100%;
                gap: 10px;
                overflow-x: auto;
                overflow-y: hidden;
                scroll-snap-type: x mandatory;
                scroll-padding-inline: 0;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 0 0 2px;
                width: 100%;
                margin-left: 0;
                margin-right: 0;
            }
            .lab-mockup-strip::-webkit-scrollbar {
                display: none;
            }
            .lab-mockup-card {
                display: block;
                scroll-snap-align: start;
                scroll-snap-stop: always;
                min-height: 0;
                padding: 0;
                overflow: hidden;
                border-radius: 6px;
                background: #eee7dd;
                box-sizing: border-box;
                user-select: none;
                -webkit-user-drag: none;
            }
            .lab-mockup-card.active {
                box-shadow: inset 0 0 0 2px #b77f86;
            }
            .lab-mockup-card img {
                width: 100%;
                aspect-ratio: 1 / 1;
                height: auto;
                object-fit: contain;
                object-position: center center;
                background: #eee7dd;
                border-radius: 0;
                pointer-events: none;
            }
            .reference-mode-control,
            .human-presence-control,
            .artwork-scale-control,
            .lighting-select-control {
                display: none !important;
            }
            .mobile-mockup-overlays {
                position: absolute;
                inset: 10px;
                z-index: 8;
                display: block;
                pointer-events: none;
            }
            .mobile-scale-dial {
                position: absolute;
                left: -19px;
                top: 18%;
                width: 38px;
                height: 64px;
                min-width: 38px;
                min-height: 64px;
                max-width: 38px;
                max-height: 64px;
                margin: 0 !important;
                padding: 7px 3px 6px !important;
                transform: translateY(-50%);
                border: 1px solid rgba(255, 255, 255, .68);
                border-radius: 999px;
                box-sizing: border-box;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 0;
                background: rgba(28, 27, 25, .34);
                color: #fff;
                box-shadow: 0 8px 22px rgba(18, 16, 14, .16), inset 0 0 0 1px rgba(255, 255, 255, .08);
                backdrop-filter: blur(10px) saturate(115%);
                -webkit-backdrop-filter: blur(10px) saturate(115%);
                pointer-events: auto;
                touch-action: none;
                user-select: none;
                -webkit-user-select: none;
            }
            .mobile-scale-dial .dial-kicker {
                font-size: 6px;
                line-height: 1;
                font-weight: 800;
                letter-spacing: .12em;
                opacity: .68;
            }
            .mobile-scale-dial strong {
                margin: 3px 0 1px;
                font-size: 15px;
                line-height: 1;
                font-family: var(--font-sans);
                font-weight: 700;
                letter-spacing: -.03em;
            }
            .mobile-scale-dial .dial-unit {
                font-size: 6px;
                line-height: 1;
                font-weight: 700;
                letter-spacing: .08em;
                opacity: .64;
            }
            .mobile-scale-dial:hover,
            .mobile-scale-dial:active,
            .mobile-scale-dial.is-dragging {
                transform: translateY(-50%);
            }
            .mobile-scale-dial.is-dragging {
                background: rgba(183, 127, 134, .62);
            }
            .mobile-human-dial {
                position: absolute;
                left: -19px;
                top: 42%;
                transform: translateY(-50%);
                display: grid;
                gap: 0;
                border: 1px solid rgba(255, 255, 255, .5);
                border-radius: 999px;
                overflow: hidden;
                background: rgba(28, 27, 25, .28);
                box-shadow: 0 8px 20px rgba(18, 16, 14, .14);
                backdrop-filter: blur(9px) saturate(115%);
                -webkit-backdrop-filter: blur(9px) saturate(115%);
                pointer-events: auto;
            }
            .mobile-view-angle {
                position: absolute;
                top: -25px;
                width: 50px;
                height: 50px;
                min-width: 50px;
                min-height: 50px;
                padding: 5px;
                border: 1px solid rgba(255, 255, 255, .52);
                border-radius: 50%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 2px;
                background: rgba(28, 27, 25, .3);
                color: rgba(255, 255, 255, .82);
                box-shadow: 0 7px 18px rgba(18, 16, 14, .14);
                backdrop-filter: blur(9px) saturate(115%);
                -webkit-backdrop-filter: blur(9px) saturate(115%);
                pointer-events: auto;
                touch-action: none;
                user-select: none;
                -webkit-user-select: none;
            }
            .mobile-view-angle[data-angle-side="less"] {
                left: -25px;
            }
            .mobile-view-angle[data-angle-side="more"] {
                right: -25px;
            }
            .mobile-view-angle svg {
                width: 21px;
                height: 17px;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.6;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            .mobile-view-angle[data-angle-side="more"] svg {
                transform: scaleX(-1);
            }
            .mobile-view-angle .angle-value {
                font-size: 8px;
                line-height: 1;
                font-weight: 800;
                letter-spacing: .03em;
                opacity: .72;
            }
            .mobile-view-angle[aria-pressed="true"] {
                border-color: rgba(255, 255, 255, .88);
                background: rgba(183, 127, 134, .68);
                color: #fff;
                transform: scale(1.06);
                box-shadow: 0 8px 22px rgba(18, 16, 14, .2), 0 0 0 2px rgba(183, 127, 134, .24);
            }
            .mobile-view-angle.is-dragging {
                background: rgba(183, 127, 134, .8);
            }
            .mobile-human-option {
                width: 38px;
                height: 32px;
                min-width: 38px;
                min-height: 32px;
                max-width: 38px;
                max-height: 32px;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box;
                border: 0;
                border-bottom: 1px solid rgba(255, 255, 255, .24);
                border-radius: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                color: rgba(255, 255, 255, .78);
                box-shadow: none;
                transition: transform .16s ease, background .16s ease, border-color .16s ease, color .16s ease;
            }
            .mobile-human-option svg {
                width: 17px;
                height: 17px;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.65;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            .mobile-human-option:last-child {
                border-bottom: 0;
            }
            .mobile-human-option[aria-pressed="true"] {
                transform: none;
                background: rgba(183, 127, 134, .64);
                color: #fff;
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .2);
            }
            .mobile-lighting-dial {
                position: absolute;
                left: 50%;
                top: 0;
                transform: translate(-50%, -50%);
                display: grid;
                grid-auto-flow: column;
                gap: 0;
                border: 1px solid rgba(255, 255, 255, .5);
                border-radius: 999px;
                overflow: hidden;
                background: rgba(28, 27, 25, .28);
                box-shadow: 0 8px 20px rgba(18, 16, 14, .14);
                backdrop-filter: blur(9px) saturate(115%);
                -webkit-backdrop-filter: blur(9px) saturate(115%);
                pointer-events: auto;
            }
            .mobile-light-option {
                width: 28px;
                height: 28px;
                min-width: 28px;
                min-height: 28px;
                max-width: 28px;
                max-height: 28px;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box;
                border: 0;
                border-right: 1px solid rgba(255, 255, 255, .24);
                border-radius: 0;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: transparent;
                color: rgba(255, 255, 255, .76);
                box-shadow: none;
                transition: opacity .16s ease, background .16s ease, color .16s ease;
            }
            .mobile-light-option:last-child {
                border-right: 0;
            }
            .mobile-light-option svg {
                width: 15px;
                height: 15px;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.7;
                stroke-linecap: round;
                stroke-linejoin: round;
            }
            .mobile-light-option[data-lighting-value="light_golden"] {
                background: rgba(202, 151, 54, .76);
                color: #fff;
            }
            .mobile-light-option[data-lighting-value="light_overcast"] {
                color: rgba(225, 230, 235, .94);
            }
            .mobile-light-option[data-lighting-value="light_night"] {
                background: rgba(91, 157, 211, .74);
                color: #fff;
            }
            .mobile-light-option[aria-pressed="true"] {
                transform: none;
                background: rgba(183, 127, 134, .68);
                color: #fff;
                box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .2);
            }
            .mobile-light-option[data-lighting-value="light_golden"][aria-pressed="true"] {
                background: rgba(185, 129, 35, .96);
                color: #fff;
                box-shadow: inset 0 0 0 1px rgba(255, 244, 216, .48);
            }
            .mobile-light-option[data-lighting-value="light_night"][aria-pressed="true"] {
                background: rgba(55, 122, 181, .96);
                color: #fff;
                box-shadow: inset 0 0 0 1px rgba(238, 248, 255, .5);
            }
            .mobile-active-apply,
            .mobile-history-apply {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 100%;
                min-height: 48px;
                margin: 18px 0 0;
                border: 1px solid #b77f86;
                border-radius: 4px;
                background: #b77f86;
                color: #fffaf7;
                font-size: 12px;
                font-weight: 800;
                letter-spacing: .09em;
                text-transform: uppercase;
            }
            .mobile-prompt-details {
                display: block;
                margin: 12px 0 0;
                border: 1px solid rgba(183, 127, 134, .28);
                border-radius: 4px;
                background: rgba(255, 250, 247, .72);
                overflow: hidden;
            }
            .mobile-prompt-details summary {
                min-height: 38px;
                padding: 0 12px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                color: var(--muted);
                font-size: 10px;
                font-weight: 800;
                letter-spacing: .08em;
                text-transform: uppercase;
                cursor: pointer;
                list-style: none;
            }
            .mobile-prompt-details summary::-webkit-details-marker {
                display: none;
            }
            .mobile-prompt-details summary::after {
                content: "+";
                color: #b77f86;
                font-size: 16px;
                font-weight: 500;
            }
            .mobile-prompt-details[open] summary::after {
                content: "−";
            }
            .mobile-prompt-details textarea {
                width: calc(100% - 20px);
                min-height: 82px;
                margin: 0 10px 10px;
                padding: 9px 10px;
                box-sizing: border-box;
                border: 1px solid var(--line);
                border-radius: 4px;
                background: rgba(255, 255, 255, .82);
                color: var(--ink);
                font-family: var(--font-sans);
                font-size: 13px;
                line-height: 1.4;
                resize: vertical;
            }
            .mobile-cascade-history {
                order: 2;
                display: grid;
                gap: 14px;
                width: 100%;
            }
            .mobile-cascade-title {
                margin: 4px 0 0;
                color: var(--muted);
                font-size: 10px;
                font-weight: 800;
                letter-spacing: .1em;
                text-transform: uppercase;
            }
            .mobile-history-card {
                position: relative;
                padding: 10px 10px 10px 34px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: var(--surface);
                box-shadow: var(--shadow);
            }
            .mobile-history-card > img {
                width: 100%;
                height: clamp(320px, 58vh, 480px);
                display: block;
                object-fit: cover;
                object-position: center;
                border-radius: 4px;
                background: #eee7dd;
            }
            .mobile-history-controls-host > .mobile-mockup-overlays {
                inset: 10px;
            }
            .mobile-history-apply {
                position: relative;
                z-index: 10;
                margin-top: 12px;
            }
            .mobile-other-variations {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .mobile-other-variation {
                display: block;
                border: 1px solid var(--line);
                border-radius: 5px;
                overflow: hidden;
                background: var(--surface);
            }
            .mobile-other-variation img {
                width: 100%;
                aspect-ratio: 3 / 4;
                display: block;
                object-fit: cover;
            }
            .lab-mockup-card.active::after {
                display: none;
            }
            .lab-mockup-card strong,
            .lab-mockup-card span {
                display: none;
            }
            .lab-mockup-card small {
                display: none;
            }
            .lab-form {
                width: 100%;
                gap: 10px;
            }
            .lab-form > label,
            .lab-form [data-compound-control],
            .lab-advanced {
                width: 100%;
            }
            .lab-form select,
            .lab-form textarea,
            .lab-form input[type="text"],
            .lab-form input[type="number"] {
                min-height: 46px;
                font-size: 14px;
            }
            .lab-form textarea {
                min-height: 118px;
            }
            .segmented-row,
            .segmented-control,
            .lab-radio-grid {
                width: 100%;
                gap: 8px;
            }
            .segmented-control.human-control {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 6px;
            }
            .segmented-control input + span {
                min-height: 42px;
                padding: 7px 5px;
                font-size: 11px;
                line-height: 1.15;
            }
            .human-control input + span {
                min-height: 50px;
                padding: 6px 3px;
            }
            .human-control .human-detail {
                display: none;
            }
            .scale-slider-wrap {
                gap: 4px;
            }
            .scale-ticks {
                font-size: 10px;
            }
            .lab-note {
                display: none;
            }
            .lab-equation {
                display: block;
            }
            .lab-equation-col:first-child {
                display: none;
            }
            .lab-equation-title {
                display: none;
            }
            .lab-equation-col.result-col {
                margin-top: 12px;
            }
            #history-section h2 {
                margin-top: 16px !important;
                font-size: 13px;
                letter-spacing: .08em;
                text-transform: uppercase;
            }
            .runs-grid {
                display: grid;
                grid-template-columns: none;
                grid-auto-flow: column;
                grid-auto-columns: calc(100% - 18px);
                gap: 10px;
                overflow-x: auto;
                overflow-y: hidden;
                scroll-snap-type: x mandatory;
                scroll-padding-inline: 0;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 0 0 2px;
                width: 100%;
                margin-left: 0;
                margin-right: 0;
                margin-top: 10px;
            }
            .runs-grid::-webkit-scrollbar {
                display: none;
            }
            .runs-grid .result-card {
                scroll-snap-align: start;
                scroll-snap-stop: always;
                padding: 0;
                border-radius: 6px;
                overflow: hidden;
            }
            .runs-grid .lab-result-media {
                height: clamp(300px, 58vh, 460px);
                background: #eee7dd;
                border: 0;
            }
            .runs-grid .result-card img {
                object-fit: cover;
                background: #eee7dd;
            }
            .runs-grid .result-card .meta {
                display: none;
            }
            .lab-bottom-action {
                display: none;
            }
            .lab-advanced {
                display: none !important;
            }
        }
    </style>
</head>
<body class="<?= $useSimpleLab ? 'lab-user' : 'lab-admin' ?>">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Mockup Lab: create controlled variations from existing mockups.</div>
        <div class="workspace">
            <?php if (!$mockups): ?>
                <div class="notice">No mockups are available for variation.</div>
            <?php else: ?>
                <div class="lab-header-v3">
                    <div class="header-main-info">
                        <h1><span class="desktop-lab-title">Mockup Lab</span><span class="mobile-lab-title">Mockup Lab</span></h1>
                        <p class="lab-page-desc">
                            <span class="desc-kicker">Create controlled variations from an existing generated mockup.</span>
                            <span class="desc-instructions">Use this module to adjust human presence, artwork scale, lighting or camera direction without changing the main scene workflow. The selected mockup is IMAGE 1; the root artwork can optionally guide the variation as IMAGE 2.</span>
                        </p>
                    </div>
                    <div class="lab-primary-action">
                        <button class="button-link lab-run-primary" type="submit" form="lab-form" data-lab-submit <?= !$selectedMockup ? 'disabled' : '' ?>>Apply Changes</button>
                    </div>
                </div>

                <div class="lab-grid">
                    <section class="lab-panel lab-control-panel">
                        <form class="lab-form" id="lab-form">
                            <label class="lab-select-hidden">
                                Existing mockup fallback
                                <select name="mockup_id" onchange="window.location.href='mockup_variation_lab.php?<?= h($labContextQuery) ?>mockup_id=' + encodeURIComponent(this.value)">
                                    <?php foreach ($mockups as $mockup): ?>
                                        <?php
                                        $artworkTitle = trim((string)($mockup['artwork']['final_title'] ?? ''));
                                        $label = '#' . (int)$mockup['id'] . ' - ' . ($artworkTitle !== '' ? $artworkTitle : basename((string)$mockup['artwork_file']));
                                        ?>
                                        <option value="<?= (int)$mockup['id'] ?>" <?= (int)$mockup['id'] === $selectedMockupId ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <?php if ($isAdmin): ?>
                                <label class="reference-mode-control">
                                    Reference mode
                                    <select name="reference_mode">
                                        <?php foreach ($referenceModes as $value => $label): ?>
                                            <option value="<?= h($value) ?>" <?= $value === 'mockup_root_strict' ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php else: ?>
                                <input type="hidden" name="reference_mode" value="mockup_root">
                            <?php endif; ?>
                            <div data-compound-control class="human-presence-control">
                                <div class="segmented-control human-control">
                                    <?php foreach ($humanOptions as $value => $label): ?>
                                        <label>
                                            <input type="radio" name="human_presence" value="<?= h($value) ?>" <?= $value === 'none' ? 'checked' : '' ?>>
                                            <span>
                                                <span class="human-title"><?= h($label['title']) ?></span>
                                                <?php if ($label['detail'] !== ''): ?><span class="human-detail"><?= h($label['detail']) ?></span><?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div data-compound-control class="artwork-scale-control">
                                <span class="control-label">Artwork scale</span>
                                <div class="scale-slider-wrap">
                                    <input type="hidden" name="artwork_scale" id="artwork-scale-value" value="none">
                                    <div class="scale-value" id="scale-display">0</div>
                                    <input class="scale-slider" id="scale-slider" type="range" min="-60" max="60" step="20" value="0" list="scale-marks" aria-label="Artwork scale">
                                    <datalist id="scale-marks">
                                        <option value="-60"></option>
                                        <option value="-40"></option>
                                        <option value="-20"></option>
                                        <option value="0"></option>
                                        <option value="20"></option>
                                        <option value="40"></option>
                                        <option value="60"></option>
                                    </datalist>
                                    <div class="scale-ticks" aria-hidden="true">
                                        <span>-60</span>
                                        <span>-40</span>
                                        <span>-20</span>
                                        <span>0</span>
                                        <span>+20</span>
                                        <span>+40</span>
                                        <span>+60</span>
                                    </div>
                                </div>
                            </div>
                            <label class="lighting-select-control">
                                Time of day
                                <select name="lighting_modifier">
                                    <?php foreach ($lightingOptions as $value => $label): ?>
                                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <input type="hidden" name="camera_modifier" id="camera-modifier" value="none">
                            <input type="hidden" name="camera_strength" id="camera-strength" value="normal">
                            <details class="lab-advanced">
                                <summary>Prompt instruction</summary>
                                <label style="margin-top:10px;">
                                    Prompt instruction in Spanish
                                    <textarea name="custom_instruction" placeholder="Optional. Example: la mujer tiene rasgos orientales, vestido negro sobrio y zapatos de lujo; mira la obra sin posar para la camara."></textarea>
                                </label>
                            </details>
                            <div class="lab-run-area">
                                <div class="lab-status" id="lab-status"></div>
                            </div>
                        </form>
                        <div class="lab-bottom-action">
                            <button class="button-link lab-run-primary" type="submit" form="lab-form" data-lab-submit <?= !$selectedMockup ? 'disabled' : '' ?>>Apply Changes</button>
                        </div>
                    </section>

                    <div class="lab-main-column">
                        <section class="lab-panel lab-selector-panel" aria-label="Existing mockups">
                            <div class="lab-mockup-browser">
                                <span>Choose existing mockup</span>
                                <div class="lab-mockup-strip">
                                    <?php foreach ($mockups as $mockup): ?>
                                        <?php
                                        $mockupId = (int)$mockup['id'];
                                        $mockupFile = basename((string)($mockup['mockup_file'] ?? ''));
                                        $artworkTitle = trim((string)($mockup['artwork']['final_title'] ?? ''));
                                        $label = $artworkTitle !== '' ? $artworkTitle : basename((string)$mockup['artwork_file']);
                                        $meta = '#' . $mockupId;
                                        $isActiveMockup = $mockupId === $selectedMockupId;
                                        ?>
                                        <a
                                            class="lab-mockup-card <?= $isActiveMockup ? 'active' : '' ?>"
                                            href="mockup_variation_lab.php?<?= h($labContextQuery) ?>mockup_id=<?= $mockupId ?>"
                                            data-mockup-id="<?= $mockupId ?>"
                                            data-viewer-url="viewer.php?id=<?= $mockupId ?>&back=<?= rawurlencode('mockup_variation_lab.php?' . $labContextQuery . 'mockup_id=' . $mockupId) ?>"
                                            title="<?= h($label . ' - ' . $meta) ?>"
                                            aria-label="Select <?= h($label) ?>"
                                        >
                                            <?php if ($mockupFile !== ''): ?>
                                                <img src="<?= h(lab_thumb_url($mockupFile, 640)) ?>" alt="" loading="lazy" decoding="async">
                                            <?php endif; ?>
                                            <strong><?= h($label) ?></strong>
                                            <small><?= h($meta) ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php if ($selectedMockup): ?>
                                <div class="mobile-mockup-overlays" aria-label="Mockup controls">
                                    <div class="mobile-human-dial" aria-label="Human presence">
                                        <button class="mobile-human-option" type="button" data-human-value="none" aria-label="No person" title="None" aria-pressed="true">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7"></circle><path d="M7 7l10 10"></path></svg>
                                        </button>
                                        <button class="mobile-human-option" type="button" data-human-value="female_160" aria-label="Female figure" title="Female" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5.5" r="2.5"></circle><path d="M12 8v5M8.5 18h7L12 10.5 8.5 18ZM12 18v4"></path></svg>
                                        </button>
                                        <button class="mobile-human-option" type="button" data-human-value="male_180" aria-label="Male figure" title="Male" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5.5" r="2.5"></circle><path d="M12 8v7M8.5 11.5 12 9l3.5 2.5M12 15l-3 7M12 15l3 7"></path></svg>
                                        </button>
                                    </div>
                                    <button class="mobile-scale-dial" id="mobile-scale-dial" type="button" role="slider" aria-label="Artwork scale. Swipe up to increase and down to decrease." aria-valuemin="-60" aria-valuemax="60" aria-valuenow="0">
                                        <span class="dial-kicker">SCALE</span>
                                        <strong id="mobile-scale-display">0</strong>
                                        <span class="dial-unit">%</span>
                                    </button>
                                    <div class="mobile-lighting-dial" aria-label="Lighting">
                                        <button class="mobile-light-option" type="button" data-lighting-value="light_overcast" aria-label="Overcast" title="Overcast" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.8 18.5h10.3a3.7 3.7 0 0 0 .5-7.4A5.7 5.7 0 0 0 6.7 9.8a4.4 4.4 0 0 0 .1 8.7Z"></path></svg>
                                        </button>
                                        <button class="mobile-light-option" type="button" data-lighting-value="light_day" aria-label="Daylight" title="Daylight" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.5"></circle><path d="M12 2v2.2M12 19.8V22M2 12h2.2M19.8 12H22M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M19.1 4.9l-1.6 1.6M6.5 17.5l-1.6 1.6"></path></svg>
                                        </button>
                                        <button class="mobile-light-option" type="button" data-lighting-value="light_golden" aria-label="Golden Hour" title="Golden Hour" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3.8"></circle><path d="M12 2v2.2M12 19.8V22M2 12h2.2M19.8 12H22M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M19.1 4.9l-1.6 1.6M6.5 17.5l-1.6 1.6"></path></svg>
                                        </button>
                                        <button class="mobile-light-option" type="button" data-lighting-value="light_night" aria-label="Night Light" title="Night Light" aria-pressed="false">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.5 15.2A8 8 0 0 1 8.8 4.5 8 8 0 1 0 19.5 15.2Z"></path></svg>
                                        </button>
                                    </div>
                                </div>
                                <details class="mobile-prompt-details">
                                    <summary>Additional prompt</summary>
                                    <textarea id="mobile-custom-instruction" placeholder="Optional instruction for this modification"></textarea>
                                </details>
                                <button class="mobile-active-apply" type="submit" form="lab-form" data-lab-submit>Apply Changes</button>
                            <?php endif; ?>
                        </section>

                        <section class="mobile-cascade-history" aria-label="Modification history">
                            <?php if ($modificationHistory): ?>
                                <h2 class="mobile-cascade-title">Modification history</h2>
                                <?php foreach ($modificationHistory as $historyMockup): ?>
                                    <?php
                                    $historyMockupId = (int)$historyMockup['id'];
                                    $historyMockupFile = basename((string)($historyMockup['mockup_file'] ?? ''));
                                    ?>
                                    <article class="mobile-history-card" data-history-mockup-id="<?= $historyMockupId ?>" data-history-scale="0" data-history-human="none" data-history-lighting="none" data-history-camera="none">
                                        <img src="<?= h(lab_thumb_url($historyMockupFile, 640)) ?>" alt="" loading="lazy" decoding="async">
                                        <div class="mobile-history-controls-host"></div>
                                        <details class="mobile-prompt-details">
                                            <summary>Additional prompt</summary>
                                            <textarea class="mobile-history-instruction" placeholder="Optional instruction for this modification"></textarea>
                                        </details>
                                        <button class="mobile-history-apply" type="button">Apply Changes</button>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($otherVariations): ?>
                                <h2 class="mobile-cascade-title">Other previous variations</h2>
                                <div class="mobile-other-variations">
                                    <?php foreach ($otherVariations as $otherMockup): ?>
                                        <?php
                                        $otherMockupId = (int)$otherMockup['id'];
                                        $otherMockupFile = basename((string)($otherMockup['mockup_file'] ?? ''));
                                        ?>
                                        <a class="mobile-other-variation" href="mockup_variation_lab.php?<?= h($labContextQuery) ?>mockup_id=<?= $otherMockupId ?>" aria-label="Edit previous variation <?= $otherMockupId ?>">
                                            <img src="<?= h(lab_thumb_url($otherMockupFile, 420)) ?>" alt="" loading="lazy" decoding="async">
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="lab-panel lab-stage">
                            <?php if ($selectedMockup): ?>
                                <?php if (!empty($selectedMockup['artwork']['id'])): ?>
                                    <div class="lab-stage-link"><a href="mockup_combination_results.php?id=<?= (int)$selectedMockup['artwork']['id'] ?>">Results</a></div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="lab-equation">
                            <div class="lab-equation-col">
                                <h2 class="lab-equation-title">References</h2>
                                <?php if ($selectedMockup): ?>
                                    <?php
                                    $mockupFile = basename((string)$selectedMockup['mockup_file']);
                                    $rootFile = basename((string)$selectedMockup['artwork_file']);
                                    ?>
                                    <div class="preview-grid" id="reference-preview-grid">
                                        <div class="preview-box">
                                            <strong>IMAGE 1 - Existing mockup</strong>
                                            <div class="image-frame">
                                                <img src="<?= h(lab_thumb_url($mockupFile, 640)) ?>" alt="" loading="lazy" decoding="async">
                                            </div>
                                        </div>
                                        <div class="preview-box root-reference-box">
                                            <strong>IMAGE 2 - Root artwork</strong>
                                            <div class="image-frame">
                                                <img src="<?= h(lab_thumb_url($rootFile, 640)) ?>" alt="" loading="lazy" decoding="async">
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="notice">Select a mockup to begin.</div>
                                <?php endif; ?>
                            </div>

                            <div class="lab-equation-mark" aria-hidden="true">=</div>

                            <div class="lab-equation-col result-col">
                                <h2 class="lab-equation-title">Result</h2>
                                <div id="new-result">
                                    <?php if ($labRuns && ($labRuns[0]['status'] ?? '') === 'generated' && !empty($labRuns[0]['output_file'])): ?>
                                        <?php
                                        $latestOutputFile = basename((string)$labRuns[0]['output_file']);
                                        $latestRegisteredId = (int)($labRuns[0]['registered_mockup_id'] ?? 0);
                                        $latestRegisteredFile = basename((string)($labRuns[0]['registered_mockup_file'] ?? ''));
                                        $latestImageUrl = $latestRegisteredId > 0 && $latestRegisteredFile !== ''
                                            ? lab_thumb_url($latestRegisteredFile, 640)
                                            : 'mockup_variation_lab_file.php?file=' . rawurlencode($latestOutputFile);
                                        $latestViewerUrl = $latestRegisteredId > 0
                                            ? 'viewer.php?id=' . rawurlencode((string)$latestRegisteredId) . '&back=' . rawurlencode('mockup_variation_lab.php?mockup_id=' . (int)$selectedMockupId)
                                            : 'mockup_variation_lab_viewer.php?mockup_id=' . (int)$selectedMockupId . '&file=' . rawurlencode($latestOutputFile);
                                        $latestIsFavorite = $latestRegisteredId > 0 && isset($favoriteLookup[$latestRegisteredId]);
                                        ?>
                                        <div class="result-card">
                                            <div class="lab-result-media" data-registered-mockup-id="<?= $latestRegisteredId ?>">
                                                <a href="<?= h($latestViewerUrl) ?>">
                                                    <img src="<?= h($latestImageUrl) ?>" alt="">
                                                </a>
                                                <?php if ($latestRegisteredId > 0): ?>
                                                    <div class="lab-result-actions" aria-label="Mockup actions">
                                                        <button class="lab-result-action js-lab-favorite <?= $latestIsFavorite ? 'is-favorite' : '' ?>" type="button" title="Favorite" aria-label="Favorite">★</button>
                                                        <button class="lab-result-action js-lab-delete" type="button" title="Delete" aria-label="Delete">×</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="meta">Latest variation</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="result-placeholder">Generate a variation to see the edited mockup here.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                            <section id="history-section">
                            <h2 style="margin-top:18px;">Latest variations for this mockup</h2>
                            <div class="runs-grid" id="lab-runs-grid">
                                <?php foreach ($labRuns as $run): ?>
                                    <?php
                                    $outputFile = basename((string)($run['output_file'] ?? ''));
                                    $registeredId = (int)($run['registered_mockup_id'] ?? 0);
                                    $registeredFile = basename((string)($run['registered_mockup_file'] ?? ''));
                                    $runImageUrl = $registeredId > 0 && $registeredFile !== ''
                                        ? lab_thumb_url($registeredFile, 640)
                                        : 'mockup_variation_lab_file.php?file=' . rawurlencode($outputFile);
                                    $runViewerUrl = $registeredId > 0
                                        ? 'viewer.php?id=' . rawurlencode((string)$registeredId) . '&back=' . rawurlencode('mockup_variation_lab.php?mockup_id=' . (int)$selectedMockupId)
                                        : 'mockup_variation_lab_viewer.php?mockup_id=' . (int)$selectedMockupId . '&file=' . rawurlencode($outputFile);
                                    $isFavorite = $registeredId > 0 && isset($favoriteLookup[$registeredId]);
                                    ?>
                                    <div class="result-card">
                                        <?php if ($outputFile !== '' && ($run['status'] ?? '') === 'generated'): ?>
                                            <div class="lab-result-media" data-registered-mockup-id="<?= $registeredId ?>">
                                                <a href="<?= h($runViewerUrl) ?>">
                                                    <img src="<?= h($runImageUrl) ?>" alt="">
                                                </a>
                                                <?php if ($registeredId > 0): ?>
                                                    <div class="lab-result-actions" aria-label="Mockup actions">
                                                        <button class="lab-result-action js-lab-favorite <?= $isFavorite ? 'is-favorite' : '' ?>" type="button" title="Favorite" aria-label="Favorite">★</button>
                                                        <button class="lab-result-action js-lab-delete" type="button" title="Delete" aria-label="Delete">×</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="notice">No generated image.</div>
                                        <?php endif; ?>
                                        <div class="meta">
                                            <div><strong><?= h((string)($run['variation_type'] ?? '')) ?></strong><?php if ($isAdmin): ?> / <?= h((string)($run['reference_mode'] ?? '')) ?><?php endif; ?></div>
                                            <div><?= h((string)($run['started_at'] ?? '')) ?></div>
                                            <?php if (!empty($run['error'])): ?><div>Error: <?= h((string)$run['error']) ?></div><?php endif; ?>
                                            <?php if ($isAdmin && !empty($run['prompt_file'])): ?>
                                                <div><a href="mockup_variation_lab_file.php?file=<?= rawurlencode(basename((string)$run['prompt_file'])) ?>" target="_blank" rel="noopener">View prompt</a></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$labRuns): ?>
                                    <div class="notice" id="empty-history">There are no generated variations for this mockup yet.</div>
                                <?php endif; ?>
                            </div>
                            </section>
                        </section>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<div class="lab-generating-overlay" role="status" aria-live="polite">
    <span class="lab-spinner" aria-hidden="true"></span>
    <span>Generating variation...</span>
</div>
<script>
const form = document.getElementById('lab-form');
const cameraModifier = document.getElementById('camera-modifier');
const cameraStrength = document.getElementById('camera-strength');
const scaleSlider = document.getElementById('scale-slider');
const scaleHidden = document.getElementById('artwork-scale-value');
const scaleDisplay = document.getElementById('scale-display');
const mobileScaleDial = document.getElementById('mobile-scale-dial');
const mobileScaleDisplay = document.getElementById('mobile-scale-display');
const lightingSelect = document.querySelector('select[name="lighting_modifier"]');
const activeMobileOverlay = document.querySelector('.lab-selector-panel .mobile-mockup-overlays');
const historyCards = Array.from(document.querySelectorAll('.mobile-history-card'));
historyCards.forEach(card => {
    const host = card.querySelector('.mobile-history-controls-host');
    if (!host || !activeMobileOverlay) return;
    const controls = activeMobileOverlay.cloneNode(true);
    controls.removeAttribute('aria-label');
    controls.querySelectorAll('[id]').forEach(element => element.removeAttribute('id'));
    controls.querySelectorAll('[aria-pressed="true"]').forEach(element => element.setAttribute('aria-pressed', 'false'));
    controls.querySelectorAll('[data-angle-side]').forEach(element => {
        element.dataset.angleLevel = '0';
        element.setAttribute('aria-pressed', 'false');
        const value = element.querySelector('.angle-value');
        if (value) value.textContent = element.dataset.angleSide === 'less' ? '−' : '+';
    });
    const noneHuman = controls.querySelector('[data-human-value="none"]');
    if (noneHuman) noneHuman.setAttribute('aria-pressed', 'true');
    host.appendChild(controls);
});
const mobileLightingOptions = Array.from(document.querySelectorAll('.lab-selector-panel [data-lighting-value]'));
const humanPresenceInputs = Array.from(document.querySelectorAll('input[name="human_presence"]'));
const mobileHumanOptions = Array.from(document.querySelectorAll('.lab-selector-panel [data-human-value]'));
const mobileAngleControls = Array.from(document.querySelectorAll('.lab-selector-panel [data-angle-side]'));
const customInstructionInput = document.querySelector('#lab-form textarea[name="custom_instruction"]');
const mobileCustomInstruction = document.getElementById('mobile-custom-instruction');
if (customInstructionInput && mobileCustomInstruction) {
    mobileCustomInstruction.value = customInstructionInput.value;
    mobileCustomInstruction.addEventListener('input', () => {
        customInstructionInput.value = mobileCustomInstruction.value;
        clearTransientResult();
    });
}
const referenceModeSelect = document.querySelector('select[name="reference_mode"]');
const referencePreviewGrid = document.getElementById('reference-preview-grid');
const labMockupStrip = document.querySelector('.lab-mockup-strip');
const labRunsGrid = document.getElementById('lab-runs-grid');
function showLatestLabRun(options = {}) {
    if (!labRunsGrid || !window.matchMedia('(max-width: 760px)').matches) return;
    window.requestAnimationFrame(() => {
        labRunsGrid.scrollTo({ left: 0, behavior: options.smooth ? 'smooth' : 'auto' });
    });
}
showLatestLabRun();
window.addEventListener('pageshow', () => showLatestLabRun());
if (labMockupStrip) {
    const labSliderCards = Array.from(labMockupStrip.querySelectorAll('.lab-mockup-card'));
    const mockupSelect = document.querySelector('select[name="mockup_id"]');
    const mobileSliderQuery = window.matchMedia('(max-width: 760px)');
    let labSliderIndex = Math.max(0, labSliderCards.findIndex(card => card.classList.contains('active')));
    let labScrollTimer = 0;
    let labPointerStartX = 0;
    let labPointerStartY = 0;
    let labSuppressClick = false;

    function labSliderIsMobile() {
        return mobileSliderQuery.matches;
    }

    function applyLabSliderPosition() {
        if (!labSliderIsMobile()) {
            labSliderCards.forEach(card => card.removeAttribute('aria-current'));
            return;
        }
        labSliderCards.forEach((card, index) => {
            const active = index === labSliderIndex;
            card.classList.toggle('active', active);
            card.setAttribute('aria-current', active ? 'true' : 'false');
        });
        const activeCard = labSliderCards[labSliderIndex];
        if (mockupSelect && activeCard && activeCard.dataset.mockupId) {
            mockupSelect.value = activeCard.dataset.mockupId;
        }
    }

    function setLabSliderIndex(index, options = {}) {
        const previousIndex = labSliderIndex;
        labSliderIndex = Math.max(0, Math.min(labSliderCards.length - 1, index));
        applyLabSliderPosition();
        if (options.scroll) {
            const activeCard = labSliderCards[labSliderIndex];
            if (activeCard) {
                labMockupStrip.scrollTo({
                    left: activeCard.offsetLeft - labMockupStrip.offsetLeft,
                    behavior: options.smooth ? 'smooth' : 'auto'
                });
            }
        }
        if (labSliderIndex !== previousIndex && options.clear !== false) {
            clearTransientResult();
        }
    }

    function syncLabSliderFromScroll() {
        if (!labSliderIsMobile() || !labSliderCards.length) return;
        const stripRect = labMockupStrip.getBoundingClientRect();
        const targetLeft = stripRect.left + 10;
        let closestIndex = labSliderIndex;
        let closestDistance = Number.POSITIVE_INFINITY;
        labSliderCards.forEach((card, index) => {
            const cardRect = card.getBoundingClientRect();
            const distance = Math.abs(cardRect.left - targetLeft);
            if (distance < closestDistance) {
                closestDistance = distance;
                closestIndex = index;
            }
        });
        setLabSliderIndex(closestIndex, { clear: false });
    }

    labSliderCards.forEach((card, index) => {
        card.addEventListener('click', event => {
            if (!labSliderIsMobile()) return;
            event.preventDefault();
            if (labSuppressClick) return;
            if (index === labSliderIndex && card.dataset.viewerUrl) {
                window.location.href = card.dataset.viewerUrl;
                return;
            }
            setLabSliderIndex(index, { scroll: true, smooth: true });
        });
    });

    labMockupStrip.addEventListener('pointerdown', event => {
        if (!labSliderIsMobile()) return;
        labPointerStartX = event.clientX;
        labPointerStartY = event.clientY;
        labSuppressClick = false;
    }, { passive: true });

    labMockupStrip.addEventListener('pointermove', event => {
        if (!labSliderIsMobile()) return;
        const dx = Math.abs(event.clientX - labPointerStartX);
        const dy = Math.abs(event.clientY - labPointerStartY);
        if (dx > 10 && dx > dy) {
            labSuppressClick = true;
        }
    }, { passive: true });

    labMockupStrip.addEventListener('pointerup', () => {
        window.setTimeout(() => { labSuppressClick = false; }, 180);
    }, { passive: true });

    labMockupStrip.addEventListener('pointercancel', () => {
        window.setTimeout(() => { labSuppressClick = false; }, 180);
    }, { passive: true });

    labMockupStrip.addEventListener('scroll', () => {
        window.clearTimeout(labScrollTimer);
        labScrollTimer = window.setTimeout(syncLabSliderFromScroll, 80);
    }, { passive: true });

    window.addEventListener('resize', () => setLabSliderIndex(labSliderIndex, { scroll: true, clear: false }));
    mobileSliderQuery.addEventListener?.('change', () => setLabSliderIndex(labSliderIndex, { scroll: true, clear: false }));
    setLabSliderIndex(labSliderIndex, { scroll: true, clear: false });
}
function selectedRadioLabel(name) {
    const checked = document.querySelector('input[name="' + name + '"]:checked');
    if (!checked) return '';
    const label = checked.closest('label');
    const text = label ? (label.textContent || '').trim() : checked.value;
    return text === '0' ? '' : text;
}
function selectedSelectLabel(name) {
    const select = document.querySelector('select[name="' + name + '"]');
    if (!select || select.value === 'none') return '';
    return select.options[select.selectedIndex].text;
}
function formatScaleValue(value) {
    const numeric = parseInt(value || '0', 10);
    if (numeric > 0) return '+' + numeric + '%';
    if (numeric < 0) return numeric + '%';
    return '0';
}
function angleModifierValue(side, level) {
    if (level === 1 && side === 'less') return 'camera_less_profile';
    if (level === 1 && side === 'more') return 'camera_more_profile';
    return 'none';
}
function setupAngleControls(buttons, onChange) {
    const updateButton = (button, level) => {
        const bounded = Math.max(0, Math.min(1, parseInt(level || '0', 10)));
        button.dataset.angleLevel = String(bounded);
        button.setAttribute('aria-pressed', bounded > 0 ? 'true' : 'false');
        const value = button.querySelector('.angle-value');
        if (value) value.textContent = button.dataset.angleSide === 'less' ? '−' : '+';
    };
    const notify = () => {
        const active = buttons.find(button => parseInt(button.dataset.angleLevel || '0', 10) > 0);
        onChange(active
            ? angleModifierValue(active.dataset.angleSide || 'less', parseInt(active.dataset.angleLevel || '0', 10))
            : 'none');
    };
    buttons.forEach(button => {
        let pointerId = null;
        let startY = 0;
        let startLevel = 0;
        let dragged = false;
        const applyLevel = level => {
            const bounded = Math.max(0, Math.min(1, level));
            if (bounded > 0) {
                buttons.forEach(other => {
                    if (other !== button) updateButton(other, 0);
                });
            }
            updateButton(button, bounded);
            notify();
        };
        button.addEventListener('pointerdown', event => {
            event.preventDefault();
            pointerId = event.pointerId;
            startY = event.clientY;
            startLevel = parseInt(button.dataset.angleLevel || '0', 10);
            dragged = false;
            button.classList.add('is-dragging');
            button.setPointerCapture?.(event.pointerId);
        });
        button.addEventListener('pointermove', event => {
            if (pointerId !== event.pointerId) return;
            const delta = startY - event.clientY;
            if (Math.abs(delta) < 12) return;
            event.preventDefault();
            dragged = true;
            applyLevel(startLevel + Math.round(delta / 24));
        });
        const finish = event => {
            if (pointerId !== null && event.pointerId !== undefined && pointerId !== event.pointerId) return;
            pointerId = null;
            button.classList.remove('is-dragging');
        };
        button.addEventListener('pointerup', finish);
        button.addEventListener('pointercancel', event => {
            dragged = false;
            finish(event);
        });
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            if (dragged) {
                dragged = false;
                return;
            }
            const current = parseInt(button.dataset.angleLevel || '0', 10);
            applyLevel(current === 1 ? 0 : 1);
        });
    });
}
function scaleValueToField(value) {
    const numeric = parseInt(value || '0', 10);
    if (numeric < 0) return 'scale_minus_' + Math.abs(numeric);
    if (numeric > 0) return 'scale_plus_' + numeric;
    return 'none';
}
function syncScaleSlider() {
    if (!scaleSlider || !scaleHidden || !scaleDisplay) return;
    scaleHidden.value = scaleValueToField(scaleSlider.value);
    scaleDisplay.textContent = formatScaleValue(scaleSlider.value);
    if (mobileScaleDial && mobileScaleDisplay) {
        const numeric = parseInt(scaleSlider.value || '0', 10);
        mobileScaleDisplay.textContent = numeric > 0 ? '+' + numeric : String(numeric);
        mobileScaleDial.setAttribute('aria-valuenow', String(numeric));
        mobileScaleDial.setAttribute('aria-valuetext', formatScaleValue(numeric));
    }
}
function syncMobileLighting() {
    const selected = lightingSelect ? lightingSelect.value : 'none';
    mobileLightingOptions.forEach(button => {
        button.setAttribute('aria-pressed', button.dataset.lightingValue === selected ? 'true' : 'false');
    });
}
function syncMobileHumanPresence() {
    const selected = humanPresenceInputs.find(input => input.checked)?.value || 'none';
    mobileHumanOptions.forEach(button => {
        button.setAttribute('aria-pressed', button.dataset.humanValue === selected ? 'true' : 'false');
    });
}
function currentScaleLabel() {
    if (!scaleSlider) return '';
    const numeric = parseInt(scaleSlider.value || '0', 10);
    return numeric === 0 ? '' : 'Scale ' + formatScaleValue(numeric);
}
function currentVariationSummary() {
    const parts = [
        selectedRadioLabel('human_presence'),
        currentScaleLabel(),
        selectedSelectLabel('lighting_modifier')
    ].filter(Boolean);
    const cameraLabels = {
        camera_less_profile: 'Less profiled',
        camera_more_profile: 'More profiled',
        camera_left_3_4: 'Left 3/4 · 45°',
        camera_right_3_4: 'Right 3/4 · 45°'
    };
    const camera = cameraModifier ? (cameraLabels[cameraModifier.value] || '') : '';
    const strength = '';
    if (camera) {
        const cameraLabel = strength ? camera + ' / ' + strength : camera;
        const lighting = selectedSelectLabel('lighting_modifier');
        return lighting ? cameraLabel + ' / ' + lighting : cameraLabel;
    }
    return parts.length ? parts.join(' / ') : 'No modifiers';
}
function labActionMarkup(mockupId) {
    if (!mockupId) return '';
    return '<div class="lab-result-actions" aria-label="Mockup actions">' +
        '<button class="lab-result-action js-lab-favorite" type="button" title="Favorite" aria-label="Favorite">★</button>' +
        '<button class="lab-result-action js-lab-delete" type="button" title="Delete" aria-label="Delete">×</button>' +
        '</div>';
}
function clearTransientResult() {
    const resultBox = document.getElementById('new-result');
    const status = document.getElementById('lab-status');
    if (resultBox && resultBox.innerHTML.trim() !== '') {
        resultBox.innerHTML = '<div class="lab-note">Selection changed. Generate a new variation to see the updated result.</div>';
    }
    if (status && status.textContent !== 'Generating variation...') {
        status.textContent = '';
    }
}
function updateReferencePreviewMode() {
    if (!referenceModeSelect || !referencePreviewGrid) return;
    const cameraActive = cameraModifier && cameraModifier.value && cameraModifier.value !== 'none';
    referencePreviewGrid.classList.toggle('mockup-only', referenceModeSelect.value === 'mockup_only' && !cameraActive);
}
function updateCameraIsolationState() {
    if (!cameraModifier) return;
    const cameraActive = cameraModifier.value && cameraModifier.value !== 'none';
    document.querySelectorAll('[data-compound-control] input:not([type="hidden"]), [data-compound-control] select').forEach(control => {
        control.disabled = cameraActive;
    });
    if (cameraStrength) {
        cameraStrength.disabled = !cameraActive;
    }
    const note = document.getElementById('camera-note');
    if (note) {
        note.textContent = cameraActive
            ? 'Experimental camera active: human presence and scale are disabled. Lighting can still be combined.'
            : 'Human presence, scale, and lighting can be combined. If you choose an experimental camera, the LAB runs it without human presence or scale changes.';
    }
}
if (cameraModifier) {
    cameraModifier.addEventListener('change', () => {
        updateCameraIsolationState();
        updateReferencePreviewMode();
        clearTransientResult();
    });
    updateCameraIsolationState();
}
if (referenceModeSelect) {
    referenceModeSelect.addEventListener('change', () => {
        updateReferencePreviewMode();
        clearTransientResult();
    });
    updateReferencePreviewMode();
}
if (cameraStrength) {
    cameraStrength.addEventListener('change', clearTransientResult);
}
if (scaleSlider) {
    syncScaleSlider();
    scaleSlider.addEventListener('input', () => {
        syncScaleSlider();
        clearTransientResult();
    });
}
if (mobileScaleDial && scaleSlider) {
    let scaleDragStartY = 0;
    let scaleDragStartValue = 0;
    let scalePointerId = null;

    const applyMobileScale = value => {
        const bounded = Math.max(-60, Math.min(60, Math.round(value / 20) * 20));
        if (parseInt(scaleSlider.value || '0', 10) === bounded) return;
        scaleSlider.value = String(bounded);
        scaleSlider.dispatchEvent(new Event('input', { bubbles: true }));
    };

    mobileScaleDial.addEventListener('pointerdown', event => {
        if (scaleSlider.disabled) return;
        event.preventDefault();
        scalePointerId = event.pointerId;
        scaleDragStartY = event.clientY;
        scaleDragStartValue = parseInt(scaleSlider.value || '0', 10);
        mobileScaleDial.classList.add('is-dragging');
        mobileScaleDial.setPointerCapture?.(event.pointerId);
    });
    mobileScaleDial.addEventListener('pointermove', event => {
        if (scalePointerId !== event.pointerId) return;
        event.preventDefault();
        const steps = Math.round((scaleDragStartY - event.clientY) / 24);
        applyMobileScale(scaleDragStartValue + (steps * 20));
    });
    const finishScaleGesture = event => {
        if (scalePointerId !== null && event.pointerId !== undefined && scalePointerId !== event.pointerId) return;
        scalePointerId = null;
        mobileScaleDial.classList.remove('is-dragging');
    };
    mobileScaleDial.addEventListener('pointerup', finishScaleGesture);
    mobileScaleDial.addEventListener('pointercancel', finishScaleGesture);
    mobileScaleDial.addEventListener('keydown', event => {
        const current = parseInt(scaleSlider.value || '0', 10);
        if (event.key === 'ArrowUp' || event.key === 'ArrowRight') {
            event.preventDefault();
            applyMobileScale(current + 20);
        } else if (event.key === 'ArrowDown' || event.key === 'ArrowLeft') {
            event.preventDefault();
            applyMobileScale(current - 20);
        } else if (event.key === 'Home') {
            event.preventDefault();
            applyMobileScale(0);
        }
    });
}
if (lightingSelect && mobileLightingOptions.length) {
    mobileLightingOptions.forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            const requested = button.dataset.lightingValue || 'none';
            lightingSelect.value = lightingSelect.value === requested ? 'none' : requested;
            syncMobileLighting();
            lightingSelect.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    syncMobileLighting();
}
if (humanPresenceInputs.length && mobileHumanOptions.length) {
    mobileHumanOptions.forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            const requested = button.dataset.humanValue || 'none';
            const input = humanPresenceInputs.find(candidate => candidate.value === requested);
            if (!input || input.disabled) return;
            input.checked = true;
            syncMobileHumanPresence();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
    syncMobileHumanPresence();
}
if (mobileAngleControls.length && cameraModifier) {
    setupAngleControls(mobileAngleControls, value => {
        cameraModifier.value = value;
        clearTransientResult();
    });
}
historyCards.forEach(card => {
    const historyHumanButtons = Array.from(card.querySelectorAll('[data-human-value]'));
    const historyLightingButtons = Array.from(card.querySelectorAll('[data-lighting-value]'));
    const historyScaleDial = card.querySelector('.mobile-scale-dial');
    const historyScaleDisplay = historyScaleDial ? historyScaleDial.querySelector('strong') : null;
    const historyAngleControls = Array.from(card.querySelectorAll('[data-angle-side]'));
    const historyApply = card.querySelector('.mobile-history-apply');

    setupAngleControls(historyAngleControls, value => {
        card.dataset.historyCamera = value;
    });

    historyHumanButtons.forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            card.dataset.historyHuman = button.dataset.humanValue || 'none';
            historyHumanButtons.forEach(option => option.setAttribute('aria-pressed', option === button ? 'true' : 'false'));
        });
    });

    historyLightingButtons.forEach(button => {
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            const requested = button.dataset.lightingValue || 'none';
            card.dataset.historyLighting = card.dataset.historyLighting === requested ? 'none' : requested;
            historyLightingButtons.forEach(option => {
                option.setAttribute('aria-pressed', option.dataset.lightingValue === card.dataset.historyLighting ? 'true' : 'false');
            });
        });
    });

    if (historyScaleDial && historyScaleDisplay) {
        let historyPointerId = null;
        let historyStartY = 0;
        let historyStartValue = 0;
        const applyHistoryScale = value => {
            const bounded = Math.max(-60, Math.min(60, Math.round(value / 20) * 20));
            card.dataset.historyScale = String(bounded);
            historyScaleDisplay.textContent = bounded > 0 ? '+' + bounded : String(bounded);
            historyScaleDial.setAttribute('aria-valuenow', String(bounded));
            historyScaleDial.setAttribute('aria-valuetext', formatScaleValue(bounded));
        };
        historyScaleDial.addEventListener('pointerdown', event => {
            event.preventDefault();
            historyPointerId = event.pointerId;
            historyStartY = event.clientY;
            historyStartValue = parseInt(card.dataset.historyScale || '0', 10);
            historyScaleDial.classList.add('is-dragging');
            historyScaleDial.setPointerCapture?.(event.pointerId);
        });
        historyScaleDial.addEventListener('pointermove', event => {
            if (historyPointerId !== event.pointerId) return;
            event.preventDefault();
            const steps = Math.round((historyStartY - event.clientY) / 24);
            applyHistoryScale(historyStartValue + (steps * 20));
        });
        const finishHistoryScale = event => {
            if (historyPointerId !== null && event.pointerId !== undefined && historyPointerId !== event.pointerId) return;
            historyPointerId = null;
            historyScaleDial.classList.remove('is-dragging');
        };
        historyScaleDial.addEventListener('pointerup', finishHistoryScale);
        historyScaleDial.addEventListener('pointercancel', finishHistoryScale);
    }

    historyApply?.addEventListener('click', () => {
        const mockupSelect = document.querySelector('select[name="mockup_id"]');
        const historyMockupId = card.dataset.historyMockupId || '';
        if (!form || !mockupSelect || historyMockupId === '') return;
        mockupSelect.value = historyMockupId;

        const requestedHuman = card.dataset.historyHuman || 'none';
        const humanInput = humanPresenceInputs.find(input => input.value === requestedHuman);
        if (humanInput) humanInput.checked = true;

        if (scaleSlider) {
            scaleSlider.value = card.dataset.historyScale || '0';
            syncScaleSlider();
        }
        if (lightingSelect) {
            lightingSelect.value = card.dataset.historyLighting || 'none';
            syncMobileLighting();
        }
        if (cameraModifier) {
            cameraModifier.value = card.dataset.historyCamera || 'none';
        }
        if (customInstructionInput) {
            customInstructionInput.value = card.querySelector('.mobile-history-instruction')?.value || '';
        }
        form.requestSubmit();
    });
});
document.querySelectorAll('#lab-form input, #lab-form select, #lab-form textarea').forEach(control => {
    if (control.name === 'mockup_id' || control.id === 'camera-modifier' || control.id === 'scale-slider') return;
    control.addEventListener('change', clearTransientResult);
    control.addEventListener('input', clearTransientResult);
});
if (form) {
    form.addEventListener('submit', event => {
        event.preventDefault();
        const status = document.getElementById('lab-status');
        const resultBox = document.getElementById('new-result');
        const buttons = Array.from(document.querySelectorAll('[data-lab-submit]'));
        if (!confirm('Generate this variation? It will consume 1 credit if the generation completes.')) {
            return;
        }
        buttons.forEach(button => { button.disabled = true; });
        document.body.classList.add('lab-is-generating');
        status.classList.add('is-loading');
        status.textContent = 'Generating variation...';
        resultBox.innerHTML = '<div class="result-placeholder"><span class="lab-spinner" aria-hidden="true"></span><span>Generating edited mockup...</span></div>';
        syncScaleSlider();
        fetch('generate_mockup_variation_lab.php', { method: 'POST', body: new FormData(form) })
            .then(response => response.text().then(text => {
                let parsed;
                try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 260)); }
                return { status: response.status, body: parsed };
            }))
            .then(result => {
                if (!result.body.ok) {
                    throw new Error(result.body.error || 'The variation failed.');
                }
                document.body.classList.remove('lab-is-generating');
                status.textContent = result.body.message || 'Test generated.';
                status.classList.remove('is-loading');
                const summary = currentVariationSummary();
                const registeredMockupId = parseInt(result.body.registered_mockup_id || '0', 10);
                if (registeredMockupId > 0) {
                    const promotedUrl = new URL(window.location.href);
                    promotedUrl.searchParams.set('mockup_id', String(registeredMockupId));
                    window.location.assign(promotedUrl.toString());
                    return;
                }
                resultBox.innerHTML =
                    '<div class="result-card">' +
                    '<div class="lab-result-media" data-registered-mockup-id="' + registeredMockupId + '">' +
                    '<a href="' + (result.body.viewer_url || result.body.output_url) + '">' +
                    '<img src="' + result.body.output_url + '" alt="">' +
                    '</a>' +
                    labActionMarkup(registeredMockupId) +
                    '</div>' +
                    '<div class="meta"><strong>' + summary + '</strong><br><a href="' + result.body.prompt_url + '" target="_blank" rel="noopener">View prompt</a> · ' +
                    '<a href="' + result.body.audit_url + '" target="_blank" rel="noopener">View audit</a></div>' +
                    '</div>';
                const historyGrid = document.getElementById('lab-runs-grid');
                if (historyGrid) {
                    const emptyHistory = document.getElementById('empty-history');
                    if (emptyHistory) emptyHistory.remove();
                    const card = document.createElement('div');
                    card.className = 'result-card';
                    card.innerHTML =
                        '<div class="lab-result-media" data-registered-mockup-id="' + registeredMockupId + '">' +
                        '<a href="' + (result.body.viewer_url || result.body.output_url) + '">' +
                        '<img src="' + result.body.output_url + '" alt="">' +
                        '</a>' +
                        labActionMarkup(registeredMockupId) +
                        '</div>' +
                        '<div class="meta"><div><strong>' + summary + '</strong></div>' +
                        '<div>Now</div>' +
                        '<div><a href="' + result.body.prompt_url + '" target="_blank" rel="noopener">View prompt</a></div>' +
                        '</div>';
                    historyGrid.prepend(card);
                    showLatestLabRun({ smooth: true });
                }
            })
            .catch(err => {
                document.body.classList.remove('lab-is-generating');
                status.classList.remove('is-loading');
                status.textContent = 'Error: ' + err.message;
            })
            .finally(() => {
                document.body.classList.remove('lab-is-generating');
                status.classList.remove('is-loading');
                buttons.forEach(button => { button.disabled = false; });
            });
    });
}
document.addEventListener('click', event => {
    const favoriteButton = event.target.closest('.js-lab-favorite');
    const deleteButton = event.target.closest('.js-lab-delete');
    if (!favoriteButton && !deleteButton) return;

    event.preventDefault();
    event.stopPropagation();
    const media = event.target.closest('.lab-result-media');
    const mockupId = media ? parseInt(media.dataset.registeredMockupId || '0', 10) : 0;
    if (!mockupId) return;

    const body = new FormData();
    body.append('mockup_id', String(mockupId));

    if (favoriteButton) {
        fetch('toggle_mockup_favorite.php', { method: 'POST', body })
            .then(response => response.json())
            .then(result => {
                if (!result.ok) throw new Error(result.error || 'Favorite failed.');
                favoriteButton.classList.toggle('is-favorite', !!result.favorite);
            })
            .catch(err => alert(err.message));
        return;
    }

    if (!confirm('Delete this mockup from the album?')) return;
    fetch('delete_mockup_result.php', { method: 'POST', body })
        .then(response => response.json())
        .then(result => {
            if (!result.ok) throw new Error(result.error || 'Delete failed.');
            const card = media.closest('.result-card');
            if (card) card.remove();
        })
        .catch(err => alert(err.message));
});
</script>
</body>
</html>
