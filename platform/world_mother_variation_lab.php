<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::ADMIN_SCENE_LIBRARY, 'Scene Source Lab');
if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('Only an administrator can edit scene sources.');
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function wmvl_resolve_scene(WorldMotherLibrary $library, string $value): string
{
    $value = trim(str_replace(['\\', '/'], '', $value));
    $normalized = WorldMotherGenerator::safeSlug($value);
    foreach ($library->categories() as $category) {
        $slug = (string)($category['category_slug'] ?? '');
        if ($slug === $value || ($normalized !== '' && WorldMotherGenerator::safeSlug($slug) === $normalized)) {
            return $slug;
        }
    }
    return '';
}

function wmvl_media_url(string $relativePath, int $width = 1200): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    $position = strpos($relativePath, 'storage/world_mothers/');
    if ($position === false) {
        return '';
    }
    $relativePath = substr($relativePath, $position);
    return 'world_mother_media.php?file=' . rawurlencode($relativePath) . '&thumb=1&w=' . max(240, min(1200, $width));
}

function wmvl_local_source(string $relativePath, string $absolutePath): string
{
    if (is_file($absolutePath)) {
        return $absolutePath;
    }
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    $position = strpos($relativePath, 'storage/world_mothers/');
    if ($position === false) {
        return $absolutePath;
    }
    $relativePath = substr($relativePath, $position);
    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($localPath) && StorageService::isGcsActive()) {
        StorageService::downloadFile($relativePath, $localPath);
    }
    return $localPath;
}

$library = new WorldMotherLibrary();
$generator = new WorldMotherGenerator($library);
$sceneSlug = wmvl_resolve_scene($library, (string)($_POST['scene'] ?? $_GET['scene'] ?? ''));
$sourceFile = basename(trim((string)($_POST['source'] ?? $_GET['source'] ?? '')));
$error = '';
$notice = isset($_GET['created']) ? 'Variation created and added to this scene.' : '';
$generated = null;

$sceneCategory = null;
foreach ($library->categories() as $category) {
    if ((string)($category['category_slug'] ?? '') === $sceneSlug) {
        $sceneCategory = $category;
        break;
    }
}
$sceneImages = $sceneSlug !== '' ? $library->imagesForCategory($sceneSlug) : [];
$sourceImage = null;
foreach ($sceneImages as $image) {
    if ((string)($image['file_name'] ?? '') === $sourceFile) {
        $sourceImage = $image;
        break;
    }
}
if ($sourceImage === null && $sceneImages) {
    $sourceImage = $sceneImages[0];
    $sourceFile = (string)($sourceImage['file_name'] ?? '');
}

$csrf = (string)($_SESSION['world_mother_variation_csrf'] ?? '');
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['world_mother_variation_csrf'] = $csrf;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_variation') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('The editing session expired. Reload the page and try again.');
        }
        if ($sceneSlug === '' || !is_array($sceneCategory) || !is_array($sourceImage)) {
            throw new RuntimeException('The selected scene source was not found.');
        }
        $customInstruction = trim((string)($_POST['instruction'] ?? ''));
        $humanPresence = trim((string)($_POST['human_presence'] ?? 'none'));
        $sceneScale = trim((string)($_POST['artwork_scale'] ?? 'none'));
        $lightingModifier = trim((string)($_POST['lighting_modifier'] ?? 'none'));
        $allowedHumanPresence = [
            'none' => 'Keep the environment unoccupied, without people.',
            'female_160' => 'Add one naturally positioned adult female figure, approximately 160 cm tall, integrated credibly into the architecture without posing for the camera.',
            'male_180' => 'Add one naturally positioned adult male figure, approximately 180 cm tall, integrated credibly into the architecture without posing for the camera.',
        ];
        $allowedLighting = [
            'none' => '',
            'light_overcast' => 'Rebuild the light as soft, neutral overcast daylight.',
            'light_day' => 'Rebuild the light as clear architectural daylight.',
            'light_golden' => 'Shift the scene to warm golden-hour light with credible directional shadows.',
            'light_night' => 'Shift the scene to a controlled night ambience with believable practical lights.',
        ];
        if (!array_key_exists($humanPresence, $allowedHumanPresence)
            || !array_key_exists($lightingModifier, $allowedLighting)
            || !preg_match('/^(?:none|scale_(?:minus|plus)_(?:20|40|60))$/', $sceneScale)) {
            throw new RuntimeException('One of the selected scene controls is not valid.');
        }
        $scaleInstruction = '';
        if (preg_match('/^scale_(minus|plus)_(20|40|60)$/', $sceneScale, $scaleMatch)) {
            $amount = (int)$scaleMatch[2];
            $scaleInstruction = $scaleMatch[1] === 'plus'
                ? 'Open the environmental framing by approximately ' . $amount . ' percent, revealing more architectural context while preserving the source identity.'
                : 'Tighten the environmental framing by approximately ' . $amount . ' percent, creating a closer scene crop while preserving the source identity.';
        }
        $instruction = trim(implode("\n", array_filter([
            $allowedHumanPresence[$humanPresence],
            $scaleInstruction,
            $allowedLighting[$lightingModifier],
            $customInstruction,
        ])));
        if ($instruction === '') {
            throw new RuntimeException('Choose a change or write an additional prompt.');
        }
        $sourcePath = wmvl_local_source(
            (string)($sourceImage['relative_path'] ?? ''),
            (string)($sourceImage['absolute_path'] ?? '')
        );
        if (!is_file($sourcePath)) {
            throw new RuntimeException('The selected scene source could not be prepared for editing.');
        }

        $generated = $generator->editWorldMother($sourcePath, $sceneSlug, [
            'scene_type' => (string)($sceneCategory['category_name'] ?? $sceneSlug),
            'architecture_language' => 'Preserve the recognizable world identity of the selected source while rebuilding the environment according to the user instruction.',
            'wall_language' => 'Keep at least one generous, credible artwork-ready plane.',
            'negative_risks' => ['no existing artwork', 'no logos', 'no readable text', 'no people unless explicitly requested', 'no literal clone'],
        ], [
            'notes' => $instruction,
        ]);

        $newFile = (string)($generated['file_name'] ?? '');
        header(
            'Location: world_mother_variation_lab.php?scene=' . rawurlencode($sceneSlug)
            . '&source=' . rawurlencode($newFile)
            . '&created=1'
        );
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$sceneName = (string)($sceneCategory['category_name'] ?? $sceneSlug);
$sourceRelativePath = is_array($sourceImage) ? (string)($sourceImage['relative_path'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scene Source Lab - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <style>
        .scene-lab-workspace {
            width:min(784px, 100%);
            margin:0 auto;
            padding:14px 10px 64px;
            box-sizing:border-box;
        }
        .scene-lab-instruction {
            margin:0 0 12px;
            color:var(--muted);
            font-size:10px;
            line-height:1.4;
            text-align:left;
        }
        .scene-lab-instruction a {
            color:var(--accent);
            font-weight:700;
            text-decoration:none;
        }
        .scene-source-main {
            position:relative;
            width:100%;
            padding:10px;
            border:1px solid var(--line);
            border-radius:6px;
            box-sizing:border-box;
            background:var(--surface);
            box-shadow:var(--shadow);
            overflow:visible;
        }
        .scene-source-form { display:block; margin:0; }
        .scene-source-stage {
            position:relative;
            width:100%;
            background:#eee7dd;
        }
        .scene-source-image {
            display:block;
            width:100%;
            max-height:98vh;
            min-height:504px;
            object-fit:contain;
            object-position:center;
            background:#eee7dd;
            border-radius:4px;
        }
        .mobile-mockup-overlays {
            position:absolute;
            inset:0;
            z-index:8;
            pointer-events:none;
        }
        .mobile-scale-dial {
            position:absolute;
            left:-19px;
            top:18%;
            width:38px;
            height:64px;
            min-width:38px;
            min-height:64px;
            max-width:38px;
            max-height:64px;
            margin:0 !important;
            padding:7px 3px 6px !important;
            transform:translateY(-50%);
            border:1px solid rgba(255,255,255,.68);
            border-radius:999px;
            box-sizing:border-box;
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:0;
            background:rgba(28,27,25,.34);
            color:#fff;
            box-shadow:0 8px 22px rgba(18,16,14,.16), inset 0 0 0 1px rgba(255,255,255,.08);
            backdrop-filter:blur(10px) saturate(115%);
            -webkit-backdrop-filter:blur(10px) saturate(115%);
            pointer-events:auto;
            touch-action:none;
            user-select:none;
            -webkit-user-select:none;
        }
        .mobile-scale-dial .dial-kicker {
            font-size:6px;
            line-height:1;
            font-weight:800;
            letter-spacing:.12em;
            opacity:.68;
        }
        .mobile-scale-dial strong {
            margin:3px 0 1px;
            font-size:15px;
            line-height:1;
            font-family:var(--font-sans);
            font-weight:700;
            letter-spacing:-.03em;
        }
        .mobile-scale-dial .dial-unit {
            font-size:6px;
            line-height:1;
            font-weight:700;
            letter-spacing:.08em;
            opacity:.64;
        }
        .mobile-scale-dial:hover,
        .mobile-scale-dial:active,
        .mobile-scale-dial.is-dragging { transform:translateY(-50%); }
        .mobile-scale-dial.is-dragging { background:rgba(183,127,134,.62); }
        .mobile-human-dial {
            position:absolute;
            left:-19px;
            top:42%;
            transform:translateY(-50%);
            display:grid;
            gap:0;
            border:1px solid rgba(255,255,255,.5);
            border-radius:999px;
            overflow:hidden;
            background:rgba(28,27,25,.28);
            box-shadow:0 8px 20px rgba(18,16,14,.14);
            backdrop-filter:blur(9px) saturate(115%);
            -webkit-backdrop-filter:blur(9px) saturate(115%);
            pointer-events:auto;
        }
        .mobile-human-option {
            width:38px;
            height:32px;
            min-width:38px;
            min-height:32px;
            max-width:38px;
            max-height:32px;
            margin:0 !important;
            padding:0 !important;
            box-sizing:border-box;
            border:0;
            border-bottom:1px solid rgba(255,255,255,.24);
            border-radius:0;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:transparent;
            color:rgba(255,255,255,.78);
            box-shadow:none;
            transition:transform .16s ease, background .16s ease, border-color .16s ease, color .16s ease;
        }
        .mobile-human-option svg {
            width:17px;
            height:17px;
            fill:none;
            stroke:currentColor;
            stroke-width:1.65;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .mobile-human-option:last-child { border-bottom:0; }
        .mobile-human-option[aria-pressed="true"] {
            transform:none;
            background:rgba(183,127,134,.64);
            color:#fff;
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.2);
        }
        .mobile-lighting-dial {
            position:absolute;
            left:50%;
            top:0;
            grid-auto-flow:column;
            transform:translate(-50%,-50%);
            display:grid;
            gap:0;
            border:1px solid rgba(255,255,255,.5);
            border-radius:999px;
            overflow:hidden;
            background:rgba(28,27,25,.28);
            box-shadow:0 8px 20px rgba(18,16,14,.14);
            backdrop-filter:blur(9px) saturate(115%);
            -webkit-backdrop-filter:blur(9px) saturate(115%);
            pointer-events:auto;
        }
        .mobile-light-option {
            width:28px;
            height:28px;
            min-width:28px;
            min-height:28px;
            max-width:28px;
            max-height:28px;
            margin:0 !important;
            padding:0 !important;
            box-sizing:border-box;
            border:0;
            border-right:1px solid rgba(255,255,255,.24);
            border-radius:0;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            background:transparent;
            color:rgba(255,255,255,.76);
            box-shadow:none;
            transition:opacity .16s ease, background .16s ease, color .16s ease;
        }
        .mobile-light-option:last-child { border-right:0; }
        .mobile-light-option svg {
            width:15px;
            height:15px;
            fill:none;
            stroke:currentColor;
            stroke-width:1.7;
            stroke-linecap:round;
            stroke-linejoin:round;
        }
        .mobile-light-option[data-lighting-value="light_golden"] {
            background:rgba(202,151,54,.76);
            color:#fff;
        }
        .mobile-light-option[data-lighting-value="light_overcast"] { color:rgba(225,230,235,.94); }
        .mobile-light-option[data-lighting-value="light_night"] {
            background:rgba(91,157,211,.74);
            color:#fff;
        }
        .mobile-light-option[aria-pressed="true"] {
            transform:none;
            background:rgba(183,127,134,.68);
            color:#fff;
            box-shadow:inset 0 0 0 1px rgba(255,255,255,.2);
        }
        .mobile-light-option[data-lighting-value="light_golden"][aria-pressed="true"] { background:rgba(185,129,35,.96); }
        .mobile-light-option[data-lighting-value="light_night"][aria-pressed="true"] { background:rgba(55,122,181,.96); }
        .scene-source-prompt {
            display:block;
            margin:12px 0 0;
            border:1px solid rgba(183,127,134,.28);
            border-radius:4px;
            background:rgba(255,250,247,.72);
            overflow:hidden;
        }
        .scene-source-prompt summary {
            min-height:38px;
            padding:0 12px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            color:var(--muted);
            font-size:10px;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
            cursor:pointer;
            list-style:none;
        }
        .scene-source-prompt summary::-webkit-details-marker { display:none; }
        .scene-source-prompt summary::after { content:'+'; color:#b77f86; font-size:16px; font-weight:500; }
        .scene-source-prompt[open] summary::after { content:'−'; }
        .scene-source-prompt textarea {
            width:calc(100% - 20px);
            min-height:82px;
            margin:0 10px 10px;
            padding:9px 10px;
            box-sizing:border-box;
            border:1px solid var(--line);
            border-radius:4px;
            background:rgba(255,255,255,.82);
            color:var(--ink);
            font-family:var(--font-sans);
            font-size:13px;
            line-height:1.4;
            resize:vertical;
        }
        .scene-source-submit {
            display:flex;
            align-items:center;
            justify-content:center;
            width:100%;
            min-height:48px;
            margin:18px 0 0;
            border:1px solid #b77f86;
            border-radius:4px;
            background:#b77f86;
            color:#fffaf7;
            font-size:12px;
            font-weight:800;
            letter-spacing:.09em;
            text-transform:uppercase;
        }
        .scene-source-submit:disabled { opacity:.58; cursor:wait; }
        .scene-source-rail { display:grid; gap:14px; width:100%; margin-top:14px; }
        .scene-source-rail-head { display:flex; justify-content:space-between; gap:18px; align-items:center; }
        .scene-source-rail h2 {
            margin:4px 0 0;
            color:var(--muted);
            font-size:10px;
            font-weight:800;
            letter-spacing:.1em;
            text-transform:uppercase;
        }
        .scene-source-rail p { margin:0; color:var(--muted); font-size:10px; }
        .scene-source-list { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; }
        .scene-source-option {
            display:block;
            border:1px solid var(--line);
            border-radius:5px;
            overflow:hidden;
            background:var(--surface);
            color:inherit;
            text-decoration:none;
        }
        .scene-source-option.is-active { box-shadow:inset 0 0 0 2px #b77f86; }
        .scene-source-option img { display:block; width:100%; aspect-ratio:3 / 4; object-fit:cover; }
        .scene-source-option span { display:none; }
        @media (max-width:760px) {
            .scene-lab-workspace { padding:14px 10px 48px; }
            .scene-source-image { min-height:0; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= h($user['email']) ?></a></header>
        <div class="alert-strip">Scene Source Lab: transform one environmental source while preserving its scene family.</div>
        <div class="scene-lab-workspace">
            <p class="scene-lab-instruction">
                Create controlled variations from this scene source.
                <a href="world_mother_studio.php?scene=<?= rawurlencode($sceneSlug) ?>#scene-detail">Back to <?= h($sceneName) ?></a>
            </p>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <?php if (is_array($sourceImage)): ?>
                <section class="scene-source-main" aria-labelledby="scene-source-title">
                    <form class="scene-source-form" method="post" data-scene-source-form>
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="create_variation">
                        <input type="hidden" name="scene" value="<?= h($sceneSlug) ?>">
                        <input type="hidden" name="source" value="<?= h($sourceFile) ?>">
                        <input type="hidden" name="human_presence" id="scene-human-presence" value="none">
                        <input type="hidden" name="artwork_scale" id="artwork-scale-value" value="none">
                        <input type="hidden" name="lighting_modifier" id="scene-lighting-modifier" value="none">
                        <div class="scene-source-stage">
                            <img class="scene-source-image" src="<?= h(wmvl_media_url($sourceRelativePath)) ?>" alt="<?= h((string)($sourceImage['title'] ?? 'Selected scene source')) ?>">
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
                        </div>
                        <details class="scene-source-prompt">
                            <summary id="scene-source-title">Additional Prompt</summary>
                            <textarea id="scene-source-instruction" name="instruction" placeholder="Example: keep the carved stone arches and warm material identity, rebuild the room with deeper diagonal perspective, quieter furniture and a generous artwork-ready wall."></textarea>
                        </details>
                        <button class="scene-source-submit" type="submit">Apply Changes</button>
                    </form>
                </section>
            <?php else: ?>
                <div class="notice error">This scene has no editable visual sources.</div>
            <?php endif; ?>

            <?php if ($sceneImages): ?>
                <section class="scene-source-rail" aria-labelledby="scene-source-rail-title">
                    <div class="scene-source-rail-head">
                        <h2 id="scene-source-rail-title">Other Previous Variations</h2>
                        <p><?= count($sceneImages) ?> available</p>
                    </div>
                    <div class="scene-source-list">
                        <?php foreach ($sceneImages as $image): ?>
                            <?php
                            $imageFile = (string)($image['file_name'] ?? '');
                            $imageRelativePath = (string)($image['relative_path'] ?? '');
                            ?>
                            <a class="scene-source-option<?= $imageFile === $sourceFile ? ' is-active' : '' ?>"
                                href="world_mother_variation_lab.php?scene=<?= rawurlencode($sceneSlug) ?>&source=<?= rawurlencode($imageFile) ?>">
                                <img src="<?= h(wmvl_media_url($imageRelativePath, 420)) ?>" alt="">
                                <span><?= h((string)($image['title'] ?? $imageFile)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
const scaleHidden = document.getElementById('artwork-scale-value');
const mobileScaleDial = document.getElementById('mobile-scale-dial');
const mobileScaleDisplay = document.getElementById('mobile-scale-display');
const humanPresenceInput = document.getElementById('scene-human-presence');
const mobileHumanOptions = Array.from(document.querySelectorAll('[data-human-value]'));
const lightingModifierInput = document.getElementById('scene-lighting-modifier');
const mobileLightingOptions = Array.from(document.querySelectorAll('[data-lighting-value]'));

function scaleValueToField(value) {
    const numeric = parseInt(value || '0', 10);
    if (numeric < 0) return 'scale_minus_' + Math.abs(numeric);
    if (numeric > 0) return 'scale_plus_' + numeric;
    return 'none';
}

function formatScaleValue(value) {
    const numeric = parseInt(value || '0', 10);
    if (numeric > 0) return '+' + numeric + '%';
    if (numeric < 0) return numeric + '%';
    return '0';
}

if (mobileScaleDial && mobileScaleDisplay && scaleHidden) {
    let scalePointerId = null;
    let scaleDragStartY = 0;
    let scaleDragStartValue = 0;
    const applyMobileScale = value => {
        const bounded = Math.max(-60, Math.min(60, Math.round(value / 20) * 20));
        scaleHidden.value = scaleValueToField(bounded);
        mobileScaleDisplay.textContent = bounded > 0 ? '+' + bounded : String(bounded);
        mobileScaleDial.setAttribute('aria-valuenow', String(bounded));
        mobileScaleDial.setAttribute('aria-valuetext', formatScaleValue(bounded));
    };

    mobileScaleDial.addEventListener('pointerdown', event => {
        event.preventDefault();
        scalePointerId = event.pointerId;
        scaleDragStartY = event.clientY;
        scaleDragStartValue = parseInt(mobileScaleDial.getAttribute('aria-valuenow') || '0', 10);
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
        const current = parseInt(mobileScaleDial.getAttribute('aria-valuenow') || '0', 10);
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

mobileHumanOptions.forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const requested = button.dataset.humanValue || 'none';
        if (humanPresenceInput) humanPresenceInput.value = requested;
        mobileHumanOptions.forEach(option => {
            option.setAttribute('aria-pressed', option === button ? 'true' : 'false');
        });
    });
});

mobileLightingOptions.forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const requested = button.dataset.lightingValue || 'none';
        if (!lightingModifierInput) return;
        lightingModifierInput.value = lightingModifierInput.value === requested ? 'none' : requested;
        mobileLightingOptions.forEach(option => {
            option.setAttribute('aria-pressed', option.dataset.lightingValue === lightingModifierInput.value ? 'true' : 'false');
        });
    });
});

document.querySelector('[data-scene-source-form]')?.addEventListener('submit', event => {
    const button = event.currentTarget.querySelector('button[type="submit"]');
    if (button) {
        button.disabled = true;
        button.textContent = 'Creating Variation…';
    }
});
</script>
</body>
</html>
