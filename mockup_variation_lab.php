<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
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

    copy($sourcePath, RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile);
    if ($promptText !== '') {
        file_put_contents(PROMPTS_DIR . DIRECTORY_SEPARATOR . $registeredPromptFile, $promptText);
    }
    ImageResizer::resize(RESULTS_DIR . DIRECTORY_SEPARATOR . $registeredMockupFile);

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
        $registeredMockupFile,
        $registeredPromptFile,
        $promptText,
        $selectorState
    ): int {
        $insert = Database::connection()->prepare("
            INSERT INTO mockups (user_id, artwork_file, mockup_file, context_id, prompt_file, selector_state_json, created_at)
            VALUES (:user_id, :artwork_file, :mockup_file, :context_id, :prompt_file, :selector_state_json, :created_at)
        ");
        $insert->execute([
            'user_id' => (int)$selectedMockup['user_id'],
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

$selectedMockupId = max(0, (int)($_GET['mockup_id'] ?? 0));

$artworksByRoot = [];
$stmt = $pdo->prepare('SELECT * FROM artworks WHERE user_id = :user_id ORDER BY created_at DESC');
$stmt->execute(['user_id' => (int)$user['id']]);
foreach ($stmt->fetchAll() ?: [] as $artwork) {
    $artworksByRoot[basename((string)($artwork['root_file'] ?? ''))] = $artwork;
}

$stmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id = :user_id ORDER BY id DESC LIMIT 80');
$stmt->execute(['user_id' => (int)$user['id']]);
$mockups = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $root = basename((string)($row['artwork_file'] ?? ''));
    $row['artwork'] = $artworksByRoot[$root] ?? null;
    if (MockupVariationEligibility::canUseVariationLab($row)) {
        $mockups[] = $row;
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
    $labRuns[] = $audit;
}
usort($labRuns, static fn(array $a, array $b): int => strcmp((string)($b['started_at'] ?? ''), (string)($a['started_at'] ?? '')));
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
];
$humanOptions = [
    'none' => ['title' => 'None', 'detail' => ''],
    'female_180' => ['title' => 'Female', 'detail' => '1.60 m'],
    'male_200' => ['title' => 'Male', 'detail' => '1.80 m'],
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
    'light_night' => 'Night light',
    'light_golden' => 'Golden hour',
];
$cameraOptions = [
    'none' => 'No camera change',
    'camera_aerial' => 'Aerial view',
    'camera_nadir' => 'Nadir view',
    'camera_profile_left' => 'Left profile view',
    'camera_profile_right' => 'Right profile view',
];
$cameraStrengthOptions = [
    'normal' => 'Normal',
    'intermediate' => 'Intermediate',
    'extreme' => 'Extreme',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Variation LAB - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .lab-grid { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0, 1fr); gap: 18px; align-items: start; }
        .lab-panel { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 16px; }
        .lab-control-panel {
            padding-bottom: 12px;
        }
        .lab-stage { padding: 18px; }
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
            position: sticky;
            bottom: 0;
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
        .preview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; height: calc(100% - 24px); }
        .preview-grid.mockup-only { grid-template-columns: minmax(0, 1fr); }
        .preview-grid.mockup-only .root-reference-box { display: none; }
        .preview-box {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr);
            min-width: 0;
        }
        .image-frame {
            height: clamp(320px, 36vw, 470px);
            border: 1px solid var(--line);
            background: var(--surface);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .image-frame img,
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
            min-height: clamp(320px, 36vw, 470px);
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
        @media (max-width: 1280px) {
            .lab-equation { grid-template-columns: 1fr; }
            .lab-equation-mark { display: none; }
            .runs-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 1000px) { .lab-grid, .preview-grid, .runs-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Isolated LAB: test variations on existing mockups without touching the main workflow.</div>
        <div class="workspace">
            <?php if (!$mockups): ?>
                <div class="notice">No mockups are available for testing.</div>
            <?php else: ?>
                <div class="lab-grid">
                    <section class="lab-panel lab-control-panel">
                        <form class="lab-form" id="lab-form">
                            <label>
                                Existing mockup
                                <select name="mockup_id" onchange="window.location.href='mockup_variation_lab.php?mockup_id=' + encodeURIComponent(this.value)">
                                    <?php foreach ($mockups as $mockup): ?>
                                        <?php
                                        $artworkTitle = trim((string)($mockup['artwork']['final_title'] ?? ''));
                                        $label = '#' . (int)$mockup['id'] . ' - ' . ($artworkTitle !== '' ? $artworkTitle : basename((string)$mockup['artwork_file']));
                                        ?>
                                        <option value="<?= (int)$mockup['id'] ?>" <?= (int)$mockup['id'] === $selectedMockupId ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Reference mode
                                <select name="reference_mode">
                                    <?php foreach ($referenceModes as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= $value === 'mockup_only' ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div data-compound-control>
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
                            <div data-compound-control>
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
                            <label>
                                Lighting
                                <select name="lighting_modifier">
                                    <?php foreach ($lightingOptions as $value => $label): ?>
                                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Experimental camera
                                <select name="camera_modifier" id="camera-modifier">
                                    <?php foreach ($cameraOptions as $value => $label): ?>
                                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Camera strength
                                <select name="camera_strength" id="camera-strength">
                                    <?php foreach ($cameraStrengthOptions as $value => $label): ?>
                                        <option value="<?= h($value) ?>"><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="lab-note" id="camera-note">Human presence, scale, and lighting can be combined. If you choose an experimental camera, the LAB runs it alone to evaluate recomposition.</div>
                            <details class="lab-advanced">
                                <summary>Prompt instruction</summary>
                                <label style="margin-top:10px;">
                                    Prompt instruction in Spanish
                                    <textarea name="custom_instruction" placeholder="Optional. Example: la mujer tiene rasgos orientales, vestido negro sobrio y zapatos de lujo; mira la obra sin posar para la camara."></textarea>
                                </label>
                            </details>
                            <div class="lab-run-area">
                                <div class="lab-status" id="lab-status"></div>
                                <button class="button-link" type="submit" <?= !$selectedMockup ? 'disabled' : '' ?>>Run test</button>
                            </div>
                        </form>
                    </section>

                    <section class="lab-panel lab-stage">
                        <?php if ($selectedMockup): ?>
                            <div class="lab-visual-title">
                                <h1>Mockup Variation LAB</h1>
                                <p>Test IMAGE 1 = existing mockup and, optionally, IMAGE 2 = root artwork.</p>
                                <?php if (!empty($selectedMockup['artwork']['id'])): ?>
                                    <p><a href="mockup_combination_results.php?id=<?= (int)$selectedMockup['artwork']['id'] ?>">Results</a></p>
                                <?php endif; ?>
                            </div>
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
                                                <img src="media.php?file=<?= rawurlencode($mockupFile) ?>" alt="">
                                            </div>
                                        </div>
                                        <div class="preview-box root-reference-box">
                                            <strong>IMAGE 2 - Root artwork</strong>
                                            <div class="image-frame">
                                                <img src="media.php?file=<?= rawurlencode($rootFile) ?>" alt="">
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
                                        $latestIsFavorite = $latestRegisteredId > 0 && isset($favoriteLookup[$latestRegisteredId]);
                                        ?>
                                        <div class="result-card">
                                            <div class="lab-result-media" data-registered-mockup-id="<?= $latestRegisteredId ?>">
                                                <a href="mockup_variation_lab_viewer.php?mockup_id=<?= (int)$selectedMockupId ?>&file=<?= rawurlencode($latestOutputFile) ?>">
                                                    <img src="mockup_variation_lab_file.php?file=<?= rawurlencode($latestOutputFile) ?>" alt="">
                                                </a>
                                                <?php if ($latestRegisteredId > 0): ?>
                                                    <div class="lab-result-actions" aria-label="Mockup actions">
                                                        <button class="lab-result-action js-lab-favorite <?= $latestIsFavorite ? 'is-favorite' : '' ?>" type="button" title="Favorite" aria-label="Favorite">★</button>
                                                        <button class="lab-result-action js-lab-delete" type="button" title="Delete" aria-label="Delete">×</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="meta">Latest generated test</div>
                                        </div>
                                    <?php else: ?>
                                        <div class="result-placeholder">Run a test to generate the edited mockup here.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <section id="history-section">
                            <h2 style="margin-top:18px;">Latest tests for this mockup</h2>
                            <div class="runs-grid" id="lab-runs-grid">
                                <?php foreach ($labRuns as $run): ?>
                                    <?php
                                    $outputFile = basename((string)($run['output_file'] ?? ''));
                                    $registeredId = (int)($run['registered_mockup_id'] ?? 0);
                                    $isFavorite = $registeredId > 0 && isset($favoriteLookup[$registeredId]);
                                    ?>
                                    <div class="result-card">
                                        <?php if ($outputFile !== '' && ($run['status'] ?? '') === 'generated'): ?>
                                            <div class="lab-result-media" data-registered-mockup-id="<?= $registeredId ?>">
                                                <a href="mockup_variation_lab_viewer.php?mockup_id=<?= (int)$selectedMockupId ?>&file=<?= rawurlencode($outputFile) ?>">
                                                    <img src="mockup_variation_lab_file.php?file=<?= rawurlencode($outputFile) ?>" alt="">
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
                                            <div><strong><?= h((string)($run['variation_type'] ?? '')) ?></strong> / <?= h((string)($run['reference_mode'] ?? '')) ?></div>
                                            <div><?= h((string)($run['started_at'] ?? '')) ?></div>
                                            <?php if (!empty($run['error'])): ?><div>Error: <?= h((string)$run['error']) ?></div><?php endif; ?>
                                            <?php if (!empty($run['prompt_file'])): ?>
                                                <div><a href="mockup_variation_lab_file.php?file=<?= rawurlencode(basename((string)$run['prompt_file'])) ?>" target="_blank" rel="noopener">View prompt</a></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$labRuns): ?>
                                    <div class="notice" id="empty-history">There are no generated tests for this mockup yet.</div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </section>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<div class="lab-generating-overlay" role="status" aria-live="polite">
    <span class="lab-spinner" aria-hidden="true"></span>
    <span>Generating test...</span>
</div>
<script>
const form = document.getElementById('lab-form');
const cameraModifier = document.getElementById('camera-modifier');
const cameraStrength = document.getElementById('camera-strength');
const scaleSlider = document.getElementById('scale-slider');
const scaleHidden = document.getElementById('artwork-scale-value');
const scaleDisplay = document.getElementById('scale-display');
const referenceModeSelect = document.querySelector('select[name="reference_mode"]');
const referencePreviewGrid = document.getElementById('reference-preview-grid');
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
}
function currentScaleLabel() {
    if (!scaleSlider) return '';
    const numeric = parseInt(scaleSlider.value || '0', 10);
    return numeric === 0 ? '' : formatScaleValue(numeric);
}
function currentVariationSummary() {
    const parts = [
        selectedRadioLabel('human_presence'),
        currentScaleLabel(),
        selectedSelectLabel('lighting_modifier')
    ].filter(Boolean);
    const camera = cameraModifier && cameraModifier.value !== 'none'
        ? cameraModifier.options[cameraModifier.selectedIndex].text
        : '';
    const strength = camera && cameraStrength ? cameraStrength.options[cameraStrength.selectedIndex].text : '';
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
        resultBox.innerHTML = '<div class="lab-note">Selection changed. Run a new test to see the updated result.</div>';
    }
    if (status && status.textContent !== 'Generating test...') {
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
        const button = form.querySelector('button[type="submit"]');
        if (!confirm('Run this test with real Gemini generation? It will consume 1 credit if the generation completes.')) {
            return;
        }
        button.disabled = true;
        document.body.classList.add('lab-is-generating');
        status.classList.add('is-loading');
        status.textContent = 'Generating test...';
        resultBox.innerHTML = '<div class="result-placeholder"><span class="lab-spinner" aria-hidden="true"></span><span>Generating edited mockup...</span></div>';
        fetch('generate_mockup_variation_lab.php', { method: 'POST', body: new FormData(form) })
            .then(response => response.text().then(text => {
                let parsed;
                try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 260)); }
                return { status: response.status, body: parsed };
            }))
            .then(result => {
                if (!result.body.ok) {
                    throw new Error(result.body.error || 'The test failed.');
                }
                document.body.classList.remove('lab-is-generating');
                status.textContent = result.body.message || 'Test generated.';
                status.classList.remove('is-loading');
                const summary = currentVariationSummary();
                const registeredMockupId = parseInt(result.body.registered_mockup_id || '0', 10);
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
                button.disabled = false;
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
