<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function report_normalize_size_override(?string $value): int
{
    $size = (int)trim((string)$value);

    return $size >= -50 && $size <= 50 ? $size : 0;
}

function find_file(string $name): ?string
{
    $safe = basename($name);

    $paths = [
        ANALYSIS_DIR . DIRECTORY_SEPARATOR . $safe,
        RESULTS_DIR . DIRECTORY_SEPARATOR . $safe,
        __DIR__ . '/uploads/' . $safe,
        __DIR__ . '/' . $safe,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function public_path(string $file): string
{
    $base = basename($file);

    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $base)) {
        return 'media.php?file=' . rawurlencode($base);
    }

    if (is_file(__DIR__ . '/uploads/' . $base)) {
        return 'uploads/' . rawurlencode($base);
    }

    return rawurlencode($base);
}

function report_string_list($value): array
{
    if (!is_array($value)) {
        $value = $value !== null && trim((string)$value) !== '' ? [$value] : [];
    }

    $items = [];
    foreach ($value as $item) {
        if (is_array($item) || is_object($item)) {
            continue;
        }

        $text = trim((string)$item);
        if ($text !== '') {
            $items[] = $text;
        }
    }

    return array_values(array_unique($items));
}

function report_has_text($value): bool
{
    return trim((string)$value) !== '';
}

function report_normalize_publishing_metadata(array $source): array
{
    $titleOptions = [];
    $rawTitles = $source['suggested_titles'] ?? $source['publishing_metadata']['suggested_titles'] ?? [];

    if (is_array($rawTitles)) {
        $isList = array_keys($rawTitles) === range(0, count($rawTitles) - 1);

        if ($isList) {
            foreach ($rawTitles as $option) {
                if (!is_array($option)) {
                    continue;
                }

                $title = trim((string)($option['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $desc = trim((string)($option['description'] ?? ''));
                if ($desc === '') {
                    $curatorialDesc = trim((string)($option['curatorial_description'] ?? ''));
                    $commercialDesc = trim((string)($option['commercial_description'] ?? ''));
                    $shortDesc = trim((string)($option['short_description'] ?? ''));
                    $desc = trim((string)($curatorialDesc ?: $commercialDesc ?: $shortDesc));
                }

                $titleOptions[] = [
                    'title' => $title,
                    'subtitle' => trim((string)($option['subtitle'] ?? '')),
                    'description' => $desc,
                ];
            }
        } else {
            foreach ($rawTitles as $label => $title) {
                $title = trim((string)$title);
                if ($title === '') {
                    continue;
                }

                $titleOptions[] = [
                    'title' => $title,
                    'subtitle' => ucwords(str_replace('_', ' ', (string)$label)),
                    'description' => '',
                ];
            }
        }
    }

    return [
        'title_options' => $titleOptions,
    ];
}

function report_is_new_schema(array $analysis): bool
{
    return array_key_exists('suggested_titles', $analysis) && array_key_exists('contextual_proposals', $analysis);
}

function report_valid_new_schema(array $analysis): bool
{
    if (!is_array($analysis['suggested_titles'] ?? null) || !is_array($analysis['contextual_proposals'] ?? null)) {
        return false;
    }

    foreach ($analysis['suggested_titles'] as $titleOption) {
        if (!is_array($titleOption)
            || trim((string)($titleOption['title'] ?? '')) === ''
            || trim((string)($titleOption['subtitle'] ?? '')) === ''
            || trim((string)($titleOption['description'] ?? '')) === '') {
            return false;
        }
    }

    return true;
}

function report_normalize_contextual_proposals(array $proposals, array $dbContexts = []): array
{
    $promptByName = [];
    $idByName = [];
    foreach ($dbContexts as $dbCtx) {
        $name = trim((string)($dbCtx['context_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $promptByName[$name] = (string)($dbCtx['prompt'] ?? '');
        $idByName[$name] = (string)($dbCtx['id'] ?? '');
    }

    $contexts = [];
    foreach ($proposals as $index => $proposal) {
        if (!is_array($proposal)) {
            continue;
        }

        $name = trim((string)($proposal['context_name'] ?? $proposal['name'] ?? ('Proposal ' . ($index + 1))));
        $contexts[] = [
            'id' => $idByName[$name] ?? (string)($proposal['id'] ?? ('ctx_' . ($index + 1))),
            'name' => $name,
            'purpose' => (string)($proposal['context_role'] ?? $proposal['purpose'] ?? ''),
            'scene' => (string)($proposal['space_type'] ?? ''),
            'atmosphere' => (string)($proposal['atmosphere'] ?? ''),
            'materials' => is_array($proposal['materials'] ?? null) ? $proposal['materials'] : [],
            'lighting' => (string)($proposal['lighting'] ?? ''),
            'camera' => (string)($proposal['camera_view'] ?? ''),
            'camera_angle' => (string)($proposal['camera_view'] ?? ''),
            'camera_group' => '',
            'camera_view' => (string)($proposal['camera_view'] ?? ''),
            'camera_distance' => (string)($proposal['camera_distance'] ?? ''),
            'camera_angle_notes' => (string)($proposal['camera_angle_notes'] ?? ''),
            'time_of_day' => '',
            'placement' => 'hanging',
            'with_human' => strtolower(trim((string)($proposal['human_presence'] ?? 'none'))) !== 'none',
            'human_profile' => null,
            'why' => (string)($proposal['curatorial_reason'] ?? ''),
            'commercial_reason' => (string)($proposal['commercial_reason'] ?? ''),
            'prompt' => (string)($proposal['prompt'] ?? $promptByName[$name] ?? $proposal['mockup_prompt'] ?? ''),
            'score' => 20,
        ];
    }

    return $contexts;
}

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        http_response_code(403);
        die('Metadata not found for this artwork.');
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        http_response_code(403);
        die('You do not have access to this artwork.');
    }
}

$copyIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
$image = $_GET['image'] ?? $_POST['image'] ?? '';
$json = $_GET['json'] ?? $_POST['json'] ?? '';

if (!$image && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $qs);

    foreach ($qs as $key => $value) {
        if (preg_match('/\.(png|jpg|jpeg|webp)$/i', $key)) {
            $image = $key;
            break;
        }
    }
}

if (!$image && $json) {
    $jsonPathTmp = find_file($json);

    if ($jsonPathTmp) {
        $tmpData = json_decode((string)file_get_contents($jsonPathTmp), true);
        $image = $tmpData['image']['file'] ?? '';
    }
}

if (!$image) {
    // Smart redirect: find the latest root image that is complete for this user
    $db = Database::connection();
    $stmt = $db->prepare("SELECT root_file FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['user_id' => $currentUser['id']]);
    $latestArtwork = $stmt->fetch();
    if ($latestArtwork && !empty($latestArtwork['root_file'])) {
        header('Location: report.php?image=' . urlencode(basename($latestArtwork['root_file'])));
        exit;
    } else {
        // No root artwork selected yet, redirect to upload page (artwork_new.php)
        header('Location: artwork_new.php');
        exit;
    }
}

$imagePath = find_file($image);

if (!$imagePath) {
    die('Image not found: ' . h($image));
}

assert_root_owner($imagePath, $currentUser);

if (!$json) {
    $base = pathinfo(basename($image), PATHINFO_FILENAME);
    $possibleJson = $base . '.analysis.json';

    if (find_file($possibleJson)) {
        $json = $possibleJson;
    }
}

$analysis = null;
$jsonPath = $json ? find_file($json) : null;

if ($jsonPath) {
    $analysis = json_decode((string)file_get_contents($jsonPath), true);
}

$currentArtistProfile = ArtistProfile::findForUser((int)$currentUser['id']);
$currentArtistProfileUpdatedAt = (string)($currentArtistProfile['updated_at'] ?? '');
$analysisProfile = is_array($analysis) && is_array($analysis['artwork_profile'] ?? null)
    ? $analysis['artwork_profile']
    : [];
$analysisArtistProfileUpdatedAt = (string)($analysisProfile['_artist_profile_updated_at'] ?? '');
$hasArtistProfileForValidation = ArtistProfile::hasContent($currentArtistProfile);
$hasCurrentArtistProfileInAnalysis = !$hasArtistProfileForValidation ||
    ($currentArtistProfileUpdatedAt !== '' && $currentArtistProfileUpdatedAt === $analysisArtistProfileUpdatedAt);

$contextsForValidation = $analysis['contextual_proposals'] ?? $analysis['recommended_contexts'] ?? [];
$expectedContextCount = PromptSettings::mockupContextCount();
$firstPromptForValidation = $contextsForValidation[0]['prompt'] ?? '';
$metaPathForValidation = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';
$hasRootMeta = is_file($metaPathForValidation);
$analysisWidthCm = $analysis['image']['physical_size']['width_cm'] ?? null;
$hasDynamicScaleText = str_contains((string)$firstPromptForValidation, 'small') ||
    str_contains((string)$firstPromptForValidation, 'medium') ||
    str_contains((string)$firstPromptForValidation, 'large statement') ||
    str_contains((string)$firstPromptForValidation, 'monumental');
$hasScaleAnchors = str_contains((string)$firstPromptForValidation, 'PROMPT_RULESET_VERSION: admin_editable_v1');
$cameraGroupsForValidation = array_map(fn($ctx) => (string)($ctx['camera_group'] ?? ''), $contextsForValidation);
$timesForValidation = array_map(fn($ctx) => (string)($ctx['time_of_day'] ?? ''), $contextsForValidation);
$humanProfilesForValidation = array_values(array_filter(array_map(fn($ctx) => (string)($ctx['human_profile'] ?? ''), $contextsForValidation)));
$hasBasicContextShape = count($contextsForValidation) === $expectedContextCount &&
    count(array_filter($contextsForValidation, fn($ctx) => trim((string)($ctx['prompt'] ?? '')) !== '')) === $expectedContextCount &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v !== '')) === $expectedContextCount &&
    count(array_filter($timesForValidation, fn($v) => $v !== '')) === $expectedContextCount;
$hasFullMockupQuotas =
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_left')) >= 2 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_right')) >= 2 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'front_close')) >= 2 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'day')) >= 4 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'afternoon')) >= 3 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'night')) >= 3 &&
    count($humanProfilesForValidation) >= 4 &&
    in_array('male_180', $humanProfilesForValidation, true) &&
    in_array('female_155', $humanProfilesForValidation, true);
$hasExpectedMockupQuotas = $expectedContextCount >= 10 ? $hasFullMockupQuotas : $hasBasicContextShape;

// Consultar base de datos SQLite por contextos e interpretación
$db = Database::connection();
$stmtArtwork = $db->prepare("SELECT * FROM artworks WHERE root_file = :root_file LIMIT 1");
$stmtArtwork->execute(['root_file' => basename($imagePath)]);
$artworkRow = $stmtArtwork->fetch();
$artworkId = $artworkRow ? (int)$artworkRow['id'] : null;

$dbContexts = [];
$dbAnalysis = null;
$dbMockups = [];
$dbQueueJobs = [];
if ($artworkId) {
    $stmtContexts = $db->prepare("SELECT * FROM mockup_contexts WHERE artwork_id = :artwork_id ORDER BY id ASC");
    $stmtContexts->execute(['artwork_id' => $artworkId]);
    $dbContexts = $stmtContexts->fetchAll();

    $stmtAnalysis = $db->prepare("SELECT * FROM artwork_analysis WHERE artwork_id = :artwork_id ORDER BY id DESC LIMIT 1");
    $stmtAnalysis->execute(['artwork_id' => $artworkId]);
    $dbAnalysis = $stmtAnalysis->fetch();

    $mockupDateOrder = Database::dateOrderSql('created_at', 'DESC');
    $stmtMockups = $db->prepare("SELECT * FROM mockups WHERE user_id = :user_id AND artwork_file = :artwork_file ORDER BY {$mockupDateOrder}, id DESC");
    $stmtMockups->execute([
        'user_id' => (int)$currentUser['id'],
        'artwork_file' => basename($imagePath)
    ]);
    $dbMockups = $stmtMockups->fetchAll();

    $dbQueueJobs = MockupBatchQueue::rowsForArtwork($artworkId);
}

$useClassicMode = isset($_GET['classic']) && $_GET['classic'] === '1';
$analysisForPublishing = [];
$dbAnalysisData = [];
$hasNewDbAnalysis = false;

if ($dbAnalysis && !empty($dbAnalysis['analysis_json'])) {
    $decodedDbAnalysis = json_decode((string)$dbAnalysis['analysis_json'], true);
    $dbAnalysisData = is_array($decodedDbAnalysis) ? $decodedDbAnalysis : [];
    $hasNewDbAnalysis = $dbAnalysisData !== [] && report_is_new_schema($dbAnalysisData);
}

if ($dbAnalysis && !$useClassicMode && $hasNewDbAnalysis) {
    // ---- MODO DINÁMICO IMPULSADO POR LA OBRA (BETA) ----
    $analysisForPublishing = $dbAnalysisData;
    $analysis = $dbAnalysisData;

    $profile = [
        'one_line_curatorial_read' => trim((string)($dbAnalysisData['suggested_titles'][0]['description'] ?? '')),
        'style_summary' => '',
        'style_tags' => [],
        'palette' => [],
        'palette_family' => [],
        'mood_tags' => [],
        'luminosity' => '',
        'saturation' => '',
        'emotional_palette' => ['temperature' => ''],
        'dreamlike_presence' => ['level' => ''],
        'audience_profile' => ['primary' => ''],
        'seasonal_strategy' => ['primary_season' => ''],
        'commercial_fit' => [],
        'avoid' => [],
        'materiality_strategy' => ['show' => []],
    ];

    $contexts = report_normalize_contextual_proposals($dbAnalysisData['contextual_proposals'] ?? [], $dbContexts);
    Logger::log(sprintf(
        'report.php schema=new_schema contexts_source=contextual_proposals suggested_titles=%d contextual_proposals=%d old_mockup_contexts_ignored=%s',
        count((array)($dbAnalysisData['suggested_titles'] ?? [])),
        count((array)($dbAnalysisData['contextual_proposals'] ?? [])),
        !empty($dbContexts) ? 'yes' : 'no'
    ), 'analysis_debug');

    $isAdmin = Auth::isAdmin($currentUser);
    $mode = 'gemini';
    $mockNotice = '';
    $audience = $profile['audience_profile']['primary'] ?? '';
    $season = $profile['seasonal_strategy']['primary_season'] ?? '';
    $emotionalTemperature = $profile['emotional_palette']['temperature'] ?? '';
    $dreamlikeLevel = $profile['dreamlike_presence']['level'] ?? '';
    
    $imagePublic = public_path($imagePath);
    $jsonPublic = $jsonPath ? basename($jsonPath) : '';
    
    $orientation = 'horizontal';
    $dbArtworkWidth = (float)($artworkRow['width'] ?? 0);
    $dbArtworkHeight = (float)($artworkRow['height'] ?? 0);
    if ($dbArtworkWidth > 0 && $dbArtworkHeight > 0) {
        $orientation = $dbArtworkWidth > $dbArtworkHeight ? 'horizontal' : ($dbArtworkHeight > $dbArtworkWidth ? 'vertical' : 'square');
    }
    
    $widthCm = $artworkRow['width'] ?? null;
    $heightCm = $artworkRow['height'] ?? null;
    $depthCm = $artworkRow['depth'] ?? null;
} else {
    // ---- MODO CLÁSICO DE RESPALDO (FALLBACK) ----
    $hasValidNewFileAnalysis = is_array($analysis) && report_valid_new_schema($analysis);
    if (
        !$analysis ||
        (!$hasValidNewFileAnalysis && (
            empty($contextsForValidation) ||
            count($contextsForValidation) !== $expectedContextCount ||
            str_contains((string)$firstPromptForValidation, 'Prototype prompt generated locally') ||
            !$hasDynamicScaleText ||
            !$hasScaleAnchors ||
            !$hasExpectedMockupQuotas ||
            !$hasCurrentArtistProfileInAnalysis ||
            ($hasRootMeta && !$analysisWidthCm)
        ))
    ) {
        if (isset($_GET['json']) && $_GET['json'] !== '') {
            http_response_code(500);
            die('Could not generate a valid analysis for Step 2. Please check the analysis JSON: ' . h((string)$_GET['json']));
        }

        $analyzeUrl = 'analyze.php?image=' . rawurlencode(basename($imagePath)) . '&redirect=1';
        header('Location: ' . $analyzeUrl);
        exit;
    }

    $profile = $analysis['artwork_profile'] ?? [
        'one_line_curatorial_read' => trim((string)($analysis['suggested_titles'][0]['description'] ?? '')),
        'style_summary' => '',
        'style_tags' => [],
        'palette' => [],
        'palette_family' => [],
        'mood_tags' => [],
        'luminosity' => '',
        'saturation' => '',
        'emotional_palette' => ['temperature' => ''],
        'dreamlike_presence' => ['level' => ''],
        'audience_profile' => ['primary' => ''],
        'seasonal_strategy' => ['primary_season' => ''],
        'commercial_fit' => [],
        'avoid' => [],
        'materiality_strategy' => ['show' => []],
    ];
    $analysisForPublishing = report_is_new_schema($analysis) ? $analysis : ($analysis['artwork_analysis'] ?? $profile);
    $contexts = report_is_new_schema($analysis)
        ? report_normalize_contextual_proposals($analysis['contextual_proposals'] ?? [], $dbContexts)
        : ($analysis['contextual_proposals'] ?? $analysis['recommended_contexts'] ?? []);
    Logger::log(sprintf(
        'report.php schema=%s contexts_source=%s suggested_titles=%d contextual_proposals=%d',
        report_is_new_schema($analysis) ? 'new_schema' : 'legacy_schema',
        report_is_new_schema($analysis) ? 'contextual_proposals' : 'legacy_analysis',
        count((array)($analysis['suggested_titles'] ?? [])),
        count((array)($analysis['contextual_proposals'] ?? []))
    ), 'analysis_debug');
    $isAdmin = Auth::isAdmin($currentUser);
    $mode = $analysis['mode'] ?? ServiceFactory::appMode();
    $mockNotice = $analysis['mock_notice'] ?? '';
    $audience = $profile['audience_profile']['primary'] ?? '';
    $season = $profile['seasonal_strategy']['primary_season'] ?? '';
    $emotionalTemperature = $profile['emotional_palette']['temperature'] ?? '';
    $dreamlikeLevel = $profile['dreamlike_presence']['level'] ?? '';

    $imagePublic = public_path($imagePath);
    $jsonPublic = $jsonPath ? basename($jsonPath) : '';

    $orientation = $analysis['image']['orientation'] ?? '';
    $widthCm = $analysis['image']['physical_size']['width_cm'] ?? null;
    $heightCm = $analysis['image']['physical_size']['height_cm'] ?? null;
    $depthCm = $analysis['image']['physical_size']['depth_cm'] ?? null;
}

$sizeText = '';

if ($widthCm && $heightCm) {
    $sizeText = $widthCm . ' × ' . $heightCm . ' cm';

    if ($depthCm) {
        $sizeText .= ' × ' . $depthCm . ' cm';
    }
}
$publishing = report_normalize_publishing_metadata($analysis ?? $analysisForPublishing);
$hasPublishingTitles = !empty($publishing['title_options']);
$hasPublishing = $hasPublishingTitles;
$isNewSchemaRender = is_array($analysisForPublishing) && report_is_new_schema($analysisForPublishing);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Report - Curatorial Direction</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            
            /* High-end Art Gallery Light Palette */
            --gal-bg: #FAF9F6;          /* Plaster white */
            --gal-surface: #FFFFFF;     /* Pure white */
            --gal-surface-soft: #F4F3EE;/* Warm linen */
            --gal-border: #E5E3DD;      /* Soft clay line */
            --gal-ink: #141412;         /* Deep charcoal */
            --gal-muted: #7A7872;       /* Warm dust */
            --gal-accent: #9A7B56;      /* Gallery gold/bronze */
            --gal-accent-light: #F6F3EE;
            --gal-accent-hover: #7E6342;
            --gal-danger: #A63C3C;      /* Red wax seal */
            --gal-shadow: 0 4px 30px rgba(20, 20, 18, 0.02);
            --gal-shadow-hover: 0 20px 48px rgba(20, 20, 18, 0.05);
            --gal-radius: 4px;
        }

        /* Full App Shell light-mode overrides */
        body {
            margin: 0;
            padding: 0;
            font-family: var(--font-sans);
            background: var(--gal-bg);
            color: var(--gal-ink);
            line-height: 1.6;
            zoom: 0.7;
        }

        .app-shell {
            background: var(--gal-bg);
            grid-template-columns: 260px 1fr;
        }

        .sidebar {
            background: var(--gal-surface);
            color: var(--gal-ink);
            border-right: 1px solid var(--gal-border);
        }

        .sidebar-head {
            background: var(--gal-bg);
            border-bottom: 1px solid var(--gal-border);
        }

        .brand {
            color: var(--gal-ink) !important;
            font-family: var(--font-serif);
            font-weight: 600;
            letter-spacing: 0.15em;
        }

        .brand-mark {
            border-color: var(--gal-accent);
        }

        .sidebar-action {
            border-bottom: 1px solid var(--gal-border);
        }

        .button-link {
            border: 1px solid var(--gal-accent);
            background: var(--gal-accent);
            color: var(--gal-surface) !important;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            text-align: center;
            border-radius: var(--gal-radius);
            padding: 10px 14px;
            display: inline-block;
        }

        .button-link:hover {
            background: var(--gal-accent-hover);
            border-color: var(--gal-accent-hover);
        }

        .nav a {
            color: var(--gal-muted) !important;
            border-bottom: 1px solid var(--gal-border);
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .nav a:hover,
        .nav a.active {
            background: var(--gal-accent-light) !important;
            color: var(--gal-ink) !important;
        }

        .nav a.active {
            border-left: 3px solid var(--gal-accent);
        }

        .app-header {
            background: var(--gal-surface);
            border-bottom: 1px solid var(--gal-border);
        }

        .user-chip {
            border-color: var(--gal-border) !important;
            color: var(--gal-ink) !important;
            background: var(--gal-bg);
            font-size: 12px;
            font-weight: 500;
        }

        .alert-strip {
            background: var(--gal-accent-light);
            color: var(--gal-accent);
            border-bottom: 1px solid var(--gal-border);
            font-size: 14px;
            font-weight: 500;
            padding: 14px 28px;
        }

        .workspace {
            padding: 30px 40px 54px;
        }

        .wrap {
            max-width: 1720px;
            margin: 0 auto;
            padding: 0;
        }

        /* Split Curatorial Layout */
        .curatorial-layout {
            display: grid;
            grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
            gap: 40px;
            align-items: start;
        }

        /* Left Curatorial Sidebar */
        .curatorial-sidebar {
            position: sticky;
            top: 30px;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding-right: 12px;
        }

        /* Custom Scrollbar for sticky sidebar */
        .curatorial-sidebar::-webkit-scrollbar {
            width: 4px;
        }
        .curatorial-sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .curatorial-sidebar::-webkit-scrollbar-thumb {
            background: var(--gal-border);
            border-radius: 2px;
        }

        .artwork-box {
            background: var(--gal-surface);
            padding: 16px;
            border: 1px solid var(--gal-border);
            box-shadow: var(--gal-shadow);
            border-radius: var(--gal-radius);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .artwork-box:hover {
            transform: scale(1.01);
            box-shadow: var(--gal-shadow-hover);
        }

        .artwork-box img {
            width: 100%;
            height: auto;
            display: block;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03);
            border-radius: 2px;
        }

        .artwork-dimensions {
            margin-top: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            border-top: 1px dashed var(--gal-border);
            padding-top: 10px;
        }

        .artwork-dimensions span {
            color: var(--gal-muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .artwork-dimensions strong {
            color: var(--gal-ink);
            font-weight: 600;
        }

        .profile {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 24px;
            box-shadow: var(--gal-shadow);
            border-radius: var(--gal-radius);
            margin-top: 0; /* Override */
        }

        .profile h2 {
            font-family: var(--font-serif);
            margin: 0 0 16px;
            font-size: 20px;
            font-weight: 500;
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 10px;
            letter-spacing: -0.01em;
        }

        .curatorial-read-line {
            font-family: var(--font-serif);
            font-style: italic;
            font-size: 16px;
            line-height: 1.5;
            color: var(--gal-accent);
            margin-bottom: 24px;
            background: var(--gal-accent-light);
            padding: 14px;
            border-left: 2px solid var(--gal-accent);
            border-radius: 0 var(--gal-radius) var(--gal-radius) 0;
        }

        .profile-details {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .detail-block {
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 16px;
        }

        .detail-block:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-block strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--gal-muted);
            letter-spacing: 0.1em;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .detail-block p {
            margin: 0 0 8px;
            font-size: 12.5px;
            color: var(--gal-ink);
            line-height: 1.5;
        }

        /* Tags */
        .curatorial-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .curatorial-tag {
            font-size: 10px;
            font-weight: 500;
            background: var(--gal-bg);
            color: var(--gal-ink);
            border: 1px solid var(--gal-border);
            padding: 3px 8px;
            border-radius: 4px;
            text-transform: capitalize;
            transition: all 0.2s ease;
        }

        .curatorial-tag:hover {
            background: var(--gal-surface-soft);
            border-color: var(--gal-accent);
        }

        .tag-commercial {
            background: rgba(154, 123, 86, 0.05);
            color: var(--gal-accent);
            border-color: rgba(154, 123, 86, 0.15);
        }

        .tag-avoid {
            background: rgba(166, 60, 60, 0.03);
            color: var(--gal-danger);
            border-color: rgba(166, 60, 60, 0.15);
            text-transform: none;
        }

        .tag-materiality {
            background: rgba(20, 20, 18, 0.03);
            border-color: var(--gal-border);
        }

        /* Swatches */
        .palette-swatches {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .swatch-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            background: var(--gal-bg);
            padding: 3px 6px;
            border: 1px solid var(--gal-border);
            border-radius: 4px;
            font-family: monospace;
        }

        .swatch {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .palette-family-text {
            font-size: 11px;
            color: var(--gal-muted);
            margin-top: 6px;
            font-style: italic;
        }

        /* Right Context Area */
        .contexts-area {
            min-width: 0;
        }

        .contexts-header {
            margin-bottom: 36px;
        }

        h1 {
            font-family: var(--font-serif);
            margin: 0 0 12px;
            font-size: 38px;
            font-weight: 500;
            line-height: 1.15;
            letter-spacing: -0.01em;
            color: var(--gal-ink);
        }

        .subtitle {
            font-size: 15px;
            line-height: 1.5;
            color: var(--gal-muted);
            font-weight: 300;
            max-width: 780px;
        }

        .mock-warning {
            margin-top: 16px;
            background: var(--gal-accent-light);
            border-left: 2px solid var(--gal-accent);
            padding: 10px 16px;
            font-size: 12px;
            color: var(--gal-accent);
            border-radius: 0 var(--gal-radius) var(--gal-radius) 0;
        }

        .contexts-area h2 {
            font-family: var(--font-serif);
            margin: 30px 0 20px;
            font-size: 24px;
            font-weight: 500;
            color: var(--gal-ink);
            border-bottom: 1px solid var(--gal-border);
            padding-bottom: 12px;
        }

        .publishing-panel {
            margin: 28px 0 34px;
            border-top: 1px solid var(--gal-border);
            border-bottom: 1px solid var(--gal-border);
            padding: 22px 0;
        }

        .publishing-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .publishing-card {
            border: 1px solid var(--gal-border);
            background: var(--gal-surface);
            border-radius: var(--gal-radius);
            padding: 14px;
            min-width: 0;
        }

        .publishing-card h3 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 18px;
            font-weight: 500;
            line-height: 1.25;
        }

        .publishing-card .pub-subtitle,
        .publishing-label {
            color: var(--gal-muted);
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .publishing-text {
            margin-top: 10px;
            font-size: 13px;
            line-height: 1.55;
            color: var(--gal-ink);
        }

        .publishing-stack {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .publishing-stack details {
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            background: var(--gal-surface);
            padding: 12px 14px;
        }

        .publishing-stack summary {
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }

        .publishing-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }

        .publishing-tags span {
            border: 1px solid var(--gal-border);
            border-radius: 999px;
            padding: 4px 8px;
            color: var(--gal-muted);
            font-size: 11px;
            background: var(--gal-bg);
        }

        .publishing-context-details {
            margin-top: 14px;
            border-top: 1px solid var(--gal-border);
            padding-top: 12px;
        }

        .publishing-context-details summary {
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }

        /* Context cards grid */
        .contexts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 20px;
            display: flex;
            flex-direction: column;
            min-height: 440px;
            box-shadow: var(--gal-shadow);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            border-radius: var(--gal-radius);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--gal-shadow-hover);
            border-color: var(--gal-accent);
        }

        .number {
            font-size: 9px;
            text-transform: uppercase;
            color: var(--gal-accent);
            letter-spacing: 0.12em;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .card h3 {
            font-family: var(--font-serif);
            margin: 0;
            font-size: 20px;
            line-height: 1.25;
            font-weight: 500;
            color: var(--gal-ink);
        }

        .purpose {
            display: inline-block;
            align-self: flex-start;
            margin-top: 8px;
            margin-bottom: 16px;
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 3px 6px;
            background: var(--gal-accent-light);
            color: var(--gal-accent);
            border: 1px solid rgba(154, 123, 86, 0.15);
            border-radius: var(--gal-radius);
        }

        /* Results / Mockup view in card */
        .inline-result {
            margin: 16px 0;
            background: var(--gal-bg);
            border: 1.5px dashed var(--gal-border);
            border-radius: var(--gal-radius);
            aspect-ratio: 4 / 3;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .card:not(.generated) .inline-result:hover {
            border-color: var(--gal-accent);
            background: var(--gal-accent-light);
        }

        .card.generated .inline-result {
            border-style: solid;
            border-width: 1px;
            padding: 10px;
            display: block;
            aspect-ratio: auto;
            background: var(--gal-bg);
        }

        .card:not(.generated) .inline-result svg {
            transition: all 0.3s ease;
        }

        .card:not(.generated) .inline-result:hover svg {
            transform: scale(1.1);
            stroke: var(--gal-accent);
        }

        .inline-result img {
            width: 100%;
            height: auto;
            max-height: 500px;
            object-fit: contain;
            display: block;
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: 2px;
            transition: transform 0.3s ease;
        }

        .inline-thumb {
            display: block;
            margin-bottom: 8px;
            overflow: hidden;
            border-radius: 2px;
        }

        .inline-thumb:hover img {
            transform: scale(1.02);
        }

        .inline-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .inline-actions a {
            font-weight: 600;
            font-size: 11px;
            text-decoration: none;
            color: var(--gal-ink);
            border: 1px solid var(--gal-border);
            padding: 6px 12px;
            border-radius: 4px;
            background: var(--gal-surface);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }

        .inline-actions a:hover {
            color: var(--gal-accent);
            border-color: var(--gal-accent);
            background: var(--gal-accent-light);
        }

        .download-icon {
            width: 12px;
            height: 12px;
            border-bottom: 2px solid currentColor;
            display: inline-block;
            position: relative;
        }

        .download-icon::before {
            content: "";
            position: absolute;
            left: 5px;
            top: 1px;
            width: 2px;
            height: 7px;
            background: currentColor;
        }

        .download-icon::after {
            content: "";
            position: absolute;
            left: 2px;
            top: 5px;
            width: 5px;
            height: 5px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
        }

        .inline-status {
            color: var(--gal-muted);
            font-size: 12px;
            line-height: 1.4;
        }

        .inline-loader {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            height: 100%;
            min-height: 160px;
            background: var(--gal-bg);
            color: var(--gal-ink);
            text-align: center;
        }

        .spinner {
            width: 32px;
            height: 32px;
            border: 2px solid var(--gal-border);
            border-top-color: var(--gal-accent);
            border-radius: 50%;
            animation: spin 0.85s linear infinite;
            flex-shrink: 0;
            margin-top: 0;
        }

        .loader-track {
            height: 3px;
            width: 100%;
            overflow: hidden;
            background: var(--gal-border);
            position: relative;
            border-radius: 2px;
            margin-top: 8px;
        }

        .loader-track::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 30%;
            background: var(--gal-accent);
            animation: trackMove 1.5s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes trackMove {
            0% { left: -30%; }
            55% { left: 100%; }
            100% { left: 100%; }
        }

        /* Card metadata grids */
        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin: 16px 0;
            font-size: 12px;
            border-top: 1px solid var(--gal-border);
            border-bottom: 1px solid var(--gal-border);
            padding: 12px 0;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .meta-item span {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--gal-muted);
        }

        .meta-item strong {
            font-weight: 500;
            color: var(--gal-ink);
        }

        .score-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 2px;
        }

        .score-bar-track {
            flex: 1;
            height: 4px;
            background: var(--gal-border);
            border-radius: 2px;
            overflow: hidden;
            max-width: 120px;
        }

        .score-bar-fill {
            height: 100%;
            background: var(--gal-accent);
            border-radius: 2px;
        }

        .scene-text,
        .lighting-text {
            font-size: 12.5px;
            line-height: 1.5;
            color: var(--gal-muted);
            margin-bottom: 14px;
        }

        .scene-text strong,
        .lighting-text strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--gal-ink);
            letter-spacing: 0.05em;
            margin-bottom: 3px;
        }

        .why {
            font-size: 11.5px;
            line-height: 1.5;
            color: var(--gal-accent);
            background: var(--gal-accent-light);
            padding: 10px 14px;
            border-left: 2px solid var(--gal-accent);
            margin-bottom: 16px;
            font-style: italic;
            border-radius: 0 var(--gal-radius) var(--gal-radius) 0;
        }

        .meta-mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 12px;
            margin-top: 10px;
            font-size: 11px;
        }
        
        .meta-mini-grid div {
            display: flex;
            flex-direction: column;
        }
        
        .meta-mini-grid span {
            color: var(--gal-muted);
            text-transform: uppercase;
            font-size: 9px;
            letter-spacing: 0.05em;
        }
        
        .meta-mini-grid strong {
            color: var(--gal-ink);
            font-weight: 500;
        }

        form {
            margin-top: auto;
            width: 100%;
        }

        button {
            width: 100%;
            border: 1px solid var(--gal-accent);
            background: var(--gal-accent);
            color: var(--gal-surface);
            padding: 12px 20px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            border-radius: var(--gal-radius);
            box-shadow: 0 2px 6px rgba(154, 123, 86, 0.1);
        }

        button:hover {
            background: var(--gal-accent-hover);
            border-color: var(--gal-accent-hover);
            box-shadow: 0 4px 12px rgba(154, 123, 86, 0.2);
        }

        button:disabled {
            background: var(--gal-border);
            border-color: var(--gal-border);
            cursor: not-allowed;
            color: var(--gal-muted);
            box-shadow: none;
        }

        details {
            margin-top: 14px;
            font-size: 11px;
            color: var(--gal-muted);
            border-top: 1px solid var(--gal-border);
            padding-top: 10px;
        }

        summary {
            cursor: pointer;
            margin-bottom: 8px;
            font-weight: 500;
        }

        textarea {
            width: 100%;
            min-height: 120px;
            font-family: Consolas, Monaco, monospace;
            font-size: 10px;
            line-height: 1.4;
            border: 1px solid var(--gal-border);
            background: var(--gal-bg);
            padding: 8px;
            box-sizing: border-box;
            border-radius: var(--gal-radius);
            color: var(--gal-muted);
        }

        .admin-mockup-prompts {
            margin: 0 0 28px;
            padding: 18px;
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            border-radius: 8px;
            box-shadow: var(--gal-shadow);
        }

        .admin-mockup-prompts h3 {
            margin: 0 0 4px;
            font-family: var(--font-serif);
            font-size: 24px;
            font-weight: 500;
            color: var(--gal-ink);
        }

        .admin-mockup-prompts > p {
            margin: 0 0 14px;
            color: var(--gal-muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .admin-mockup-prompt {
            margin-top: 10px;
            padding-top: 0;
            border: 1px solid var(--gal-border);
            border-radius: 6px;
            background: var(--gal-bg);
            overflow: hidden;
        }

        .admin-mockup-prompt summary {
            margin: 0;
            padding: 10px 12px;
            color: var(--gal-ink);
            font-size: 12px;
            font-weight: 600;
            list-style-position: inside;
        }

        .admin-mockup-meta {
            display: grid;
            gap: 3px;
            padding: 0 12px 10px;
            color: var(--gal-muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .admin-mockup-actions {
            display: flex;
            justify-content: flex-end;
            padding: 0 12px 10px;
        }

        .admin-mockup-actions button {
            width: auto;
            margin: 0;
            padding: 7px 10px;
            font-size: 11px;
        }

        .admin-mockup-prompt textarea {
            display: block;
            width: calc(100% - 24px);
            min-height: 320px;
            margin: 0 12px 12px;
            background: #fbfaf7;
            color: var(--gal-ink);
            font-size: 11px;
            line-height: 1.45;
        }

        .back {
            margin-top: 48px;
            padding-top: 24px;
            border-top: 1px solid var(--gal-border);
            display: flex;
            gap: 20px;
            font-size: 13px;
        }

        .back a {
            color: var(--gal-ink);
            text-decoration: none;
            font-weight: 500;
            border-bottom: 1px solid var(--gal-border);
            transition: all 0.2s ease;
        }
        
        .back a:hover {
            color: var(--gal-accent);
            border-color: var(--gal-accent);
        }

        @media (max-width: 1200px) {
            .curatorial-layout {
                grid-template-columns: 1fr;
                gap: 32px;
            }
            .curatorial-sidebar {
                position: static;
                max-height: none;
            }
        }

        @media (max-width: 1100px) {
            .contexts {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
            .publishing-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .contexts {
                grid-template-columns: 1fr;
            }
            .publishing-grid {
                grid-template-columns: 1fr;
            }
            body {
                padding: 0;
            }
            .workspace {
                padding: 20px 16px 40px;
            }
        }

        /* Collapsible containers for simplification */
        .card-details-toggle,
        .sidebar-details-toggle {
            margin-top: 14px;
            border-top: 1px solid var(--gal-border);
            padding-top: 8px;
            border-bottom: none;
        }

        .card-details-summary,
        .sidebar-details-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            padding: 8px 0;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--gal-accent);
            list-style: none; /* Hide default chevron on Firefox */
            user-select: none;
            transition: color 0.2s ease;
        }

        .card-details-summary::-webkit-details-marker,
        .sidebar-details-summary::-webkit-details-marker {
            display: none; /* Hide default chevron on Chrome/Safari */
        }

        .card-details-summary:hover,
        .sidebar-details-summary:hover {
            color: var(--gal-accent-hover);
        }

        .card-details-summary .chevron-icon,
        .sidebar-details-summary .chevron-icon {
            width: 14px;
            height: 14px;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            color: var(--gal-muted);
        }

        .card-details-toggle[open] .card-details-summary .chevron-icon,
        .sidebar-details-toggle[open] .sidebar-details-summary .chevron-icon {
            transform: rotate(180deg);
            color: var(--gal-accent);
        }

        .card-details-content,
        .profile-details {
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            padding-top: 8px;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mockup Customizer Selector Styles */
        .customizer-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 14px;
            margin: 16px 0;
            padding-top: 14px;
            border-top: 1px solid var(--gal-border);
        }

        .opt-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .opt-group.full-width {
            grid-column: 1 / -1;
        }

        .opt-group label {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--gal-muted);
            letter-spacing: 0.08em;
            font-weight: 600;
        }

        .selector-icons {
            display: flex;
            background: var(--gal-bg);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 2px;
            gap: 2px;
            min-width: 0;
        }

        .icon-btn {
            flex: 1;
            min-width: 0;
            background: transparent;
            border: none;
            border-radius: calc(var(--gal-radius) - 1px);
            padding: 6px 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            color: var(--gal-muted);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: none !important;
            margin: 0 !important;
            width: auto !important;
        }

        .icon-btn .icon {
            width: 16px;
            height: 16px;
            stroke-width: 2px;
        }

        .icon-btn span {
            font-size: 8px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: block;
            line-height: 1.15;
            max-width: 100%;
            overflow-wrap: anywhere;
        }

        .icon-btn:hover {
            color: var(--gal-ink);
            background: var(--gal-surface-soft);
        }

        .icon-btn.active {
            color: var(--gal-surface) !important;
            background: var(--gal-accent) !important;
        }

        .size-select {
            width: 100%;
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            background: var(--gal-bg);
            color: var(--gal-ink);
            padding: 10px 12px;
            font-size: 12px;
        }

        .scale-slider-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .scale-badge {
            font-size: 10px;
            color: var(--gal-accent);
            background: var(--gal-accent-light);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 2px 6px;
        }

        .scale-slider-wrapper {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 8px;
        }

        .slider-side-label {
            font-size: 9px;
            color: var(--gal-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .premium-slider {
            width: 100%;
            min-width: 0;
        }

        .scale-slider-ticks {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 4px;
            margin-top: 4px;
            font-size: 8px;
            color: var(--gal-muted);
            text-align: center;
        }

        .scale-slider-ticks .tick {
            min-width: 0;
            cursor: pointer;
        }
    </style>
</head>

<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Curatorial Direction: Choose the most suitable visual context to present the artwork with scale, atmosphere and intention.
        </div>

        <div class="workspace">
            <div class="wrap">

    <div class="curatorial-layout">
        <!-- Sidebar: Sticky Curatorial Info -->
        <aside class="curatorial-sidebar">
            <div class="artwork-box">
                <img src="<?= h($imagePublic) ?>" alt="Root image">
                <div class="artwork-dimensions">
                    <span>Physical dimensions</span>
                    <strong><?= h($sizeText ?: 'Not specified') ?></strong>
                </div>
            </div>

            <div class="profile">
                <h2>Curatorial Read</h2>
                <?php if (!empty($profile['one_line_curatorial_read'])): ?>
                    <div class="curatorial-read-line">
                        "<?= h($profile['one_line_curatorial_read']) ?>"
                    </div>
                <?php endif; ?>

                <?php if (!$isNewSchemaRender): ?>
                <details class="sidebar-details-toggle">
                    <summary class="sidebar-details-summary">
                        <span>Show Detailed Analysis</span>
                        <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="6 9 12 15 18 9"></polyline>
                        </svg>
                    </summary>
                    <div class="profile-details">
                        <div class="detail-block">
                            <strong>Visual Language & Style</strong>
                            <p><?= h($profile['style_summary'] ?? '-') ?></p>
                            <div class="curatorial-tags">
                                <?php foreach (($profile['style_tags'] ?? []) as $tag): ?>
                                    <span class="curatorial-tag"><?= h($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="detail-block">
                            <strong>Dominant Palette</strong>
                            <div class="palette-swatches">
                                <?php 
                                $palette = $profile['palette'] ?? [];
                                foreach ($palette as $color): 
                                    $colorTrim = trim($color);
                                    if (preg_match('/^#[0-9a-fA-F]{3,6}$/', $colorTrim)): 
                                ?>
                                        <div class="swatch-item" title="<?= h($colorTrim) ?>">
                                            <span class="swatch" style="background-color: <?= h($colorTrim) ?>; border: 1px solid var(--gal-border);"></span>
                                            <span class="swatch-label"><?= h($colorTrim) ?></span>
                                        </div>
                                <?php else: ?>
                                        <span class="curatorial-tag"><?= h($colorTrim) ?></span>
                                <?php 
                                    endif;
                                endforeach; 
                                if (empty($palette)) echo '-';
                                ?>
                            </div>
                            <?php if (!empty($profile['palette_family'])): ?>
                                <div class="palette-family-text">
                                    Color Family: <?= h(implode(', ', $profile['palette_family'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="detail-block">
                            <strong>Atmosphere & Emotional Energy</strong>
                            <div class="curatorial-tags">
                                <?php foreach (($profile['mood_tags'] ?? []) as $tag): ?>
                                    <span class="curatorial-tag"><?= h($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="meta-mini-grid">
                                <div><span>Luminosity</span> <strong><?= h($profile['luminosity'] ?? '-') ?></strong></div>
                                <div><span>Saturation</span> <strong><?= h($profile['saturation'] ?? '-') ?></strong></div>
                                <div><span>Temperature</span> <strong><?= h($emotionalTemperature ?: '-') ?></strong></div>
                                <div><span>Dreamlike Level</span> <strong><?= h($dreamlikeLevel ?: '-') ?></strong></div>
                            </div>
                        </div>

                        <div class="detail-block">
                            <strong>Suggested Audience & Presentation Context</strong>
                            <div class="meta-mini-grid">
                                <div><span>Target Audience</span> <strong><?= h($audience ?: '-') ?></strong></div>
                                <div><span>Season</span> <strong><?= h($season ?: '-') ?></strong></div>
                            </div>
                            <div style="margin-top: 12px;">
                                <strong>Optimal Settings</strong>
                                <div class="curatorial-tags">
                                    <?php foreach (($profile['commercial_fit'] ?? []) as $space): ?>
                                        <span class="curatorial-tag tag-commercial"><?= h($space) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($profile['avoid'])): ?>
                            <div class="detail-block">
                                <strong>Avoid in Presentation</strong>
                                <div class="curatorial-tags">
                                    <?php foreach ($profile['avoid'] as $avoidItem): ?>
                                        <span class="curatorial-tag tag-avoid"><?= h($avoidItem) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($profile['materiality_strategy']['show'])): ?>
                            <div class="detail-block">
                                <strong>Materiality Focus</strong>
                                <div class="curatorial-tags">
                                    <?php foreach ($profile['materiality_strategy']['show'] as $matItem): ?>
                                        <span class="curatorial-tag tag-materiality"><?= h($matItem) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>
            </div>

            <?php
            $rawAnalysisJson = '';
            if (is_array($dbAnalysis) && !empty($dbAnalysis['analysis_json'])) {
                $rawAnalysisJson = (string)$dbAnalysis['analysis_json'];
            } elseif ($json && is_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . basename($json))) {
                $rawAnalysisJson = (string)file_get_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . basename($json));
            }
            if ($rawAnalysisJson !== ''):
                $decodedJson = json_decode($rawAnalysisJson, true);
                if (is_array($decodedJson)) {
                    $rawAnalysisJson = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            ?>
                <details class="details-panel" style="margin-top: 20px; border: 1px solid var(--gal-border); background: var(--gal-surface); border-radius: var(--gal-radius); padding: 16px; box-shadow: var(--gal-shadow);">
                    <summary style="font-weight: 600; cursor: pointer; color: var(--gal-ink); font-size: 13px;">Show Raw AI Analysis (JSON)</summary>
                    <div style="margin-top: 12px;">
                        <pre style="background: var(--gal-bg); border: 1px solid var(--gal-border); padding: 12px; border-radius: var(--gal-radius); overflow-x: auto; font-family: monospace; font-size: 11px; margin: 0; max-height: 400px; color: var(--gal-ink);"><code class="json"><?= h($rawAnalysisJson) ?></code></pre>
                    </div>
                </details>
            <?php endif; ?>
        </aside>

        <!-- Main Workspace Area -->
        <div class="contexts-area">
            <div class="contexts-header" style="display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
                <h1 style="margin: 0; line-height: 1.1;">Curatorial Direction</h1>
                <?php if (!empty($analysis['prompt_version'])): ?>
                    <span class="prompt-version-badge" style="font-size: 11px; font-weight: 600; padding: 3px 8px; background: var(--gal-surface); border: 1px solid var(--gal-border); border-radius: 4px; color: var(--gal-muted); display: inline-flex; align-items: center; letter-spacing: 0.02em;"><?= h($analysis['prompt_version']) ?></span>
                <?php endif; ?>
                
                <?php if ($mockNotice): ?>
                    <div class="mock-warning" style="width: 100%; margin-top: 10px;">
                        <?= h($mockNotice) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($hasPublishing): ?>
                <section class="publishing-panel" aria-label="Publishing metadata" style="margin-bottom: 30px;">
                    <?php if ($hasPublishingTitles): ?>
                        <div class="publishing-grid" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px;">
                            <?php foreach ($publishing['title_options'] as $optionIndex => $option): ?>
                                <?php
                                    $altTitle = $option['title'] ?? '';
                                    $altSub = $option['subtitle'] ?? '';
                                    $altDesc = $option['description'] ?? '';
                                ?>
                                <article class="publishing-card" style="background: var(--gal-surface); border: 1px solid var(--gal-border); border-radius: var(--gal-radius); padding: 18px; display: flex; flex-direction: column; gap: 14px;">
                                    <div class="publishing-label" style="font-size: 11px; text-transform: uppercase; color: var(--gal-muted); font-weight: 600; letter-spacing: 0.05em;">Option <?= h((string)($optionIndex + 1)) ?></div>
                                    
                                    <div class="field-item">
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                            <strong style="font-size: 10px; text-transform: uppercase; color: var(--gal-muted); font-weight: 700; letter-spacing: 0.05em;">Title</strong>
                                            <button class="copy-button secondary" type="button" data-copy="<?= h($altTitle) ?>" aria-label="Copy Title" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                        </div>
                                        <div style="font-size: 16px; font-weight: 600; color: var(--gal-ink); line-height: 1.4;"><?= h($altTitle) ?></div>
                                    </div>
                                    
                                    <?php if ($altSub !== ''): ?>
                                        <div class="field-item">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                <strong style="font-size: 10px; text-transform: uppercase; color: var(--gal-muted); font-weight: 700; letter-spacing: 0.05em;">Subtitle</strong>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($altSub) ?>" aria-label="Copy Subtitle" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                            <div style="font-size: 13px; color: var(--gal-accent); font-family: var(--font-serif); line-height: 1.4;"><?= h($altSub) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($altDesc !== ''): ?>
                                        <div class="field-item" style="margin-top: auto; padding-top: 10px; border-top: 1px dashed var(--gal-border);">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                                <strong style="font-size: 10px; text-transform: uppercase; color: var(--gal-muted); font-weight: 700; letter-spacing: 0.05em;">Premium Description</strong>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($altDesc) ?>" aria-label="Copy Description" style="padding: 4px; display: inline-flex; align-items: center; justify-content: center; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                            <p class="publishing-text" style="font-size: 13px; line-height: 1.5; color: var(--gal-ink); margin: 0;"><?= h($altDesc) ?></p>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <h2><?= h(count($contexts)) ?> contextual proposals for this artwork</h2>

            <?php if ($isAdmin && !empty($contexts)): ?>
                <aside class="admin-mockup-prompts" aria-label="Admin mockup prompts">
                    <h3>Admin - Mockup Prompts</h3>
                    <p>Prompts completos preparados para generar mockups desde esta obra raiz. Solo visible para administradores.</p>

                    <?php foreach ($contexts as $promptIndex => $promptContext): ?>
                        <?php
                            $adminPromptId = 'adminMockupPrompt' . $promptIndex;
                            $adminCtxId = (string)($promptContext['id'] ?? ('ctx_' . ($promptIndex + 1)));
                            $adminPromptText = (string)($promptContext['prompt'] ?? '');
                        ?>
                        <details class="admin-mockup-prompt" <?= $promptIndex === 0 ? 'open' : '' ?>>
                            <summary>Proposal <?= h((string)($promptIndex + 1)) ?> - <?= h((string)($promptContext['name'] ?? 'Context')) ?></summary>
                            <div class="admin-mockup-meta">
                                <span>Context ID: <?= h($adminCtxId) ?></span>
                                <span>Purpose: <?= h(str_replace('_', ' ', (string)($promptContext['purpose'] ?? ''))) ?></span>
                                <span>Camera: <?= h((string)($promptContext['camera'] ?? '')) ?></span>
                                <span>Time: <?= h((string)($promptContext['time_of_day'] ?? '')) ?></span>
                            </div>
                            <div class="admin-mockup-actions">
                                <button type="button" class="button secondary admin-copy-mockup-prompt" data-target="<?= h($adminPromptId) ?>">Copy prompt</button>
                            </div>
                            <textarea id="<?= h($adminPromptId) ?>" readonly><?= h($adminPromptText) ?></textarea>
                        </details>
                    <?php endforeach; ?>
                </aside>
            <?php endif; ?>

            <?php $reportBackUrl = 'report.php?image=' . rawurlencode(basename($imagePath)) . ($json ? '&json=' . rawurlencode(basename($json)) : ''); ?>

            <?php
            // Pre-calculate generated vs pending state for each context
            $generatedContexts = [];
            $pendingContexts   = [];
            foreach ($contexts as $i => $ctx) {
                $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                $existingMockupCheck = null;
                foreach ($dbMockups as $m) {
                    if ((string)$m['context_id'] === (string)$ctxId) {
                        $existingMockupCheck = $m;
                        break;
                    }
                }
                if ($existingMockupCheck) {
                    $generatedContexts[] = ['i' => $i, 'ctx' => $ctx];
                } else {
                    $pendingContexts[] = ['i' => $i, 'ctx' => $ctx];
                }
            }
            ?>

            <div class="contexts">
                <!-- Row 1: Generated mockups -->
                <?php if (!empty($generatedContexts)): ?>
                    <div class="contexts-row-header" style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                        <span style="font-size: 9px; text-transform: uppercase; letter-spacing: 0.12em; font-weight: 700; color: var(--gal-accent);">Mockups Generados</span>
                        <div style="flex: 1; height: 1px; background: var(--gal-border);"></div>
                    </div>
                <?php endif; ?>
                <?php foreach ($generatedContexts as $entry): ?>
                <?php $i = $entry['i']; $ctx = $entry['ctx']; ?>
                    <?php
                        $prompt = $ctx['prompt'] ?? '';
                        $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                        $humanProfile = $ctx['human_profile'] ?? null;
                        $humanText = match ($humanProfile) {
                            'male_180' => 'male 1.80 m',
                            'female_155' => 'female 1.55 m',
                            default => (!empty($ctx['with_human']) ? 'discreet figure' : 'none'),
                        };

                        // Check if a mockup has already been generated for this context
                        $existingMockup = null;
                        foreach ($dbMockups as $m) {
                            if ((string)$m['context_id'] === (string)$ctxId) {
                                $existingMockup = $m;
                                break;
                            }
                        }
                        $selectorState = [];
                        if ($existingMockup && !empty($existingMockup['selector_state_json'])) {
                            $decodedSelectorState = json_decode((string)$existingMockup['selector_state_json'], true);
                            $selectorState = is_array($decodedSelectorState) ? $decodedSelectorState : [];
                        }

                        $queueJob = null;
                        foreach ($dbQueueJobs as $job) {
                            if ((string)$job['context_id'] === (string)$ctxId) {
                                $queueJob = $job;
                                break;
                            }
                        }
                        $queueStatus = $queueJob ? (string)$queueJob['status'] : '';
                        $isAutoPending = !$existingMockup && in_array($queueStatus, ['queued', 'processing'], true);
                    ?>

                    <div class="card <?= $existingMockup ? 'generated' : '' ?> <?= $isAutoPending ? 'auto-pending' : '' ?>" id="context-<?= h($ctxId) ?>" data-context-id="<?= h($ctxId) ?>">
                        <div class="number">Proposal <?= $i + 1 ?></div>

                        <h3><?= h($ctx['name'] ?? 'Context') ?></h3>

                        <span class="purpose">
                            <?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?>
                        </span>

                        <div class="inline-result" aria-live="polite">
                            <?php if ($existingMockup): ?>
                                <?php
                                    $mFile = basename((string)$existingMockup['mockup_file']);
                                    $mUrl = public_path($mFile);
                                    $mViewerUrl = 'viewer.php?id=' . rawurlencode((string)$existingMockup['id']) . '&back=' . rawurlencode($reportBackUrl . '#context-' . $ctxId);
                                    $mDownloadUrl = $mUrl . '&download=1';
                                ?>
                                <a class="inline-thumb" href="<?= h($mViewerUrl) ?>" aria-label="Open generated mockup">
                                    <img src="<?= h($mUrl) ?>" alt="Generated mockup">
                                </a>
                                <div class="inline-actions" style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                                    <a href="<?= h($mDownloadUrl) ?>" aria-label="Download mockup" title="Download">
                                        <span class="download-icon" aria-hidden="true"></span>
                                    </a>
                                    <?php if ($isAdmin): ?>
                                        <a href="media.php?file=<?= rawurlencode(basename((string)$existingMockup['prompt_file'])) ?>" target="_blank" rel="noopener">Prompt</a>
                                    <?php endif; ?>
                                    <button type="button" class="btn-delete-mockup" data-mockup-id="<?= h((string)$existingMockup['id']) ?>" title="Delete Mockup" style="background: transparent; border: none; color: var(--gal-danger); cursor: pointer; padding: 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">
                                        <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                                            <polyline points="3 6 5 6 21 6"></polyline>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" y1="11" x2="10" y2="17"></line>
                                            <line x1="14" y1="11" x2="14" y2="17"></line>
                                        </svg>
                                        Delete
                                    </button>
                                </div>
                            <?php else: ?>
                                <?php if ($isAutoPending): ?>
                                    <div class="inline-loader" data-auto-status="<?= h($queueStatus) ?>">
                                        <div class="spinner" aria-hidden="true"></div>
                                        <div class="inline-status">Generating mockup...</div>
                                    </div>
                                <?php elseif ($queueStatus === 'error'): ?>
                                    <div class="inline-status" style="color: var(--gal-danger); font-size: 11px; padding: 10px; text-align: center;">
                                        Error: <?= h($queueJob['error'] ?? 'Automatic generation failed.') ?>
                                    </div>
                                <?php else: ?>
                                    <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--gal-muted)" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;">
                                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                        <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                        <polyline points="21 15 16 10 5 21"></polyline>
                                    </svg>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($ctx['why'])): ?>
                            <p class="why-rationale" style="font-size: 12px; line-height: 1.45; color: var(--gal-ink); margin: 12px 0 0 0; font-style: italic;">
                                <strong>Curatorial rationale:</strong> <?= h($ctx['why']) ?>
                            </p>
                        <?php endif; ?>

                        <form class="inline-mockup-form" action="generate_mockup.php" method="post">
                            <input type="hidden" name="image" value="<?= h(basename($imagePath)) ?>">
                            <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                            <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                            <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="camera_override_touched" value="0">
                            <input type="hidden" name="time_override_touched" value="0">
                            <input type="hidden" name="human_override_touched" value="0">
                            <input type="hidden" name="distance_override_touched" value="0">
                            <input type="hidden" name="size_override_touched" value="0">
                            <?php if ($existingMockup): ?>
                                <input type="hidden" name="current_mockup_file" value="<?= h(basename((string)$existingMockup['mockup_file'])) ?>">
                            <?php endif; ?>

                            <?php
                            $defaultCamera = 'custom';
                            $cameraGroup = $ctx['camera_group'] ?? '';
                            $cameraRaw = trim((string)($ctx['camera_view'] ?? $ctx['camera'] ?? $ctx['camera_angle'] ?? ''));
                            $cameraRawLower = strtolower($cameraRaw);
                            if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE) {
                                if (in_array($cameraRawLower, ['front', 'front view', 'frontal'], true)) {
                                    $defaultCamera = 'front';
                                } elseif (in_array($cameraRawLower, ['3_4_left', 'three_quarter_left', 'three-quarter left', 'three quarter left', '3/4 left'], true)) {
                                    $defaultCamera = '3_4_left';
                                } elseif (in_array($cameraRawLower, ['3_4_right', 'three_quarter_right', 'three-quarter right', 'three quarter right', '3/4 right'], true)) {
                                    $defaultCamera = '3_4_right';
                                } else {
                                    $defaultCamera = 'custom';
                                }
                            } else {
                                $defaultCamera = 'front';
                                if (str_contains($cameraGroup, 'left') || str_contains($cameraRaw, 'left')) {
                                    $defaultCamera = '3_4_left';
                                } elseif (str_contains($cameraGroup, 'right') || str_contains($cameraRaw, 'right')) {
                                    $defaultCamera = '3_4_right';
                                }
                            }
                            if (in_array(($selectorState['camera_override'] ?? ''), ['front', '3_4_left', '3_4_right'], true)) {
                                $defaultCamera = (string)$selectorState['camera_override'];
                            }

                            $defaultTime = 'sunny_day';
                            $timeOfDay = strtolower($ctx['time_of_day'] ?? '');
                            if (str_contains($timeOfDay, 'cloudy') || str_contains($timeOfDay, 'nublado') || str_contains($timeOfDay, 'overcast')) {
                                $defaultTime = 'cloudy_day';
                            } elseif (str_contains($timeOfDay, 'afternoon') || str_contains($timeOfDay, 'sunset') || str_contains($timeOfDay, 'tarde') || str_contains($timeOfDay, 'golden')) {
                                $defaultTime = 'afternoon';
                            } elseif (str_contains($timeOfDay, 'night') || str_contains($timeOfDay, 'evening') || str_contains($timeOfDay, 'noche') || str_contains($timeOfDay, 'nocturnal')) {
                                $defaultTime = 'night';
                            }
                            if (in_array(($selectorState['time_override'] ?? ''), ['sunny_day', 'cloudy_day', 'afternoon', 'night'], true)) {
                                $defaultTime = (string)$selectorState['time_override'];
                            }

                            $defaultHuman = 'none';
                            $humanProfile = $ctx['human_profile'] ?? null;
                            if ($humanProfile === 'male_180') {
                                $defaultHuman = 'male_180';
                            } elseif ($humanProfile === 'female_155') {
                                $defaultHuman = 'female_155';
                            } elseif (!empty($ctx['with_human'])) {
                                $defaultHuman = 'female_155'; // Fallback
                            }
                            if (in_array(($selectorState['human_override'] ?? ''), ['none', 'female_155', 'male_180'], true)) {
                                $defaultHuman = (string)$selectorState['human_override'];
                            }

                            $defaultSizeOverride = report_normalize_size_override((string)($selectorState['size_override'] ?? '0'));
                            ?>

                            <div class="customizer-options">
                                <div class="opt-group">
                                    <label>Camera Angle <?= (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && $defaultCamera === 'custom') ? '(<span style="color:#4caf50; font-weight:normal;">Custom camera from prompt: ' . h($cameraRaw) . '</span>)' : '' ?></label>
                                    <div class="selector-icons camera-selector">
                                        <button type="button" class="icon-btn <?= $defaultCamera === 'front' ? 'active' : '' ?>" data-value="front" title="Frontal">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <rect x="4" y="6" width="16" height="12" rx="1.5"></rect>
                                                <line x1="12" y1="6" x2="12" y2="18" stroke-dasharray="2 2"></line>
                                            </svg>
                                            <span>Frontal</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultCamera === '3_4_left' ? 'active' : '' ?>" data-value="3_4_left" title="3/4 izquierdo">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M20 8l-10-3v14l10-3z"></path>
                                            </svg>
                                            <span>3/4 izquierdo</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultCamera === '3_4_right' ? 'active' : '' ?>" data-value="3_4_right" title="3/4 derecho">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M4 8l10-3v14l-10-3z"></path>
                                            </svg>
                                            <span>3/4 derecho</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="camera_override" value="">
                                </div>
                                <div class="opt-group">
                                    <label>Atmosphere / Lighting</label>
                                    <div class="selector-icons time-selector">
                                        <button type="button" class="icon-btn <?= $defaultTime === 'sunny_day' ? 'active' : '' ?>" data-value="sunny_day" title="Sunny day">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="5"></circle>
                                                <line x1="12" y1="1" x2="12" y2="3"></line>
                                                <line x1="12" y1="21" x2="12" y2="23"></line>
                                                <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>
                                                <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>
                                                <line x1="1" y1="12" x2="3" y2="12"></line>
                                                <line x1="21" y1="12" x2="23" y2="12"></line>
                                                <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>
                                                <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>
                                            </svg>
                                            <span>Sunny day</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'cloudy_day' ? 'active' : '' ?>" data-value="cloudy_day" title="Cloudy day">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path>
                                            </svg>
                                            <span>Cloudy day</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'afternoon' ? 'active' : '' ?>" data-value="afternoon" title="Afternoon">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M17 18a5 5 0 0 0-10 0"></path>
                                                <line x1="12" y1="2" x2="12" y2="9"></line>
                                                <line x1="2" y1="18" x2="22" y2="18"></line>
                                                <line x1="2" y1="21" x2="22" y2="21"></line>
                                            </svg>
                                            <span>Afternoon</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'night' ? 'active' : '' ?>" data-value="night" title="Night">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                                            </svg>
                                            <span>Night</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="time_override" value="">
                                </div>
                                <div class="opt-group full-width">
                                    <label>Figura humana</label>
                                    <div class="selector-icons human-selector">
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'none' ? 'active' : '' ?>" data-value="none" title="Sin figura humana">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <path d="M18 18c0-1.7-1.3-3-3-3H9c-1.7 0-3 1.3-3 3"></path>
                                                <circle cx="12" cy="7" r="4"></circle>
                                                <line x1="2" y1="2" x2="22" y2="22"></line>
                                            </svg>
                                            <span>Sin figura</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'female_155' ? 'active' : '' ?>" data-value="female_155" title="Figura humana femenina">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="6" r="3.5"></circle>
                                                <path d="M12 9.5l-4 8h8z"></path>
                                                <line x1="10" y1="17.5" x2="10" y2="21"></line>
                                                <line x1="14" y1="17.5" x2="14" y2="21"></line>
                                            </svg>
                                            <span>Femenina</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'male_180' ? 'active' : '' ?>" data-value="male_180" title="Figura humana masculina">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="5.5" r="2.5"></circle>
                                                <path d="M9 9.5h6v7h-2v5h-2v-5H9z"></path>
                                            </svg>
                                            <span>Masculina</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="human_override" value="">
                                </div>

                                <div class="opt-group full-width" style="margin-top: 8px;">
                                    <label>CAMERA DISTANCE</label>
                                    <div class="selector-icons distance-selector">
                                        <button type="button" class="icon-btn <?= ($selectorState['distance_override'] ?? 'medium') === 'close' ? 'active' : '' ?>" data-value="close" title="Acercar (Zoom In)">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="8"></circle>
                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                <line x1="11" y1="8" x2="11" y2="14"></line>
                                                <line x1="8" y1="11" x2="14" y2="11"></line>
                                            </svg>
                                            <span>Acercar (Zoom In)</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= ($selectorState['distance_override'] ?? 'medium') === 'medium' ? 'active' : '' ?>" data-value="medium" title="Alejar (Zoom Out)">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="11" cy="11" r="8"></circle>
                                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                                <line x1="8" y1="11" x2="14" y2="11"></line>
                                            </svg>
                                            <span>Alejar (Zoom Out)</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="distance_override" value="">
                                </div>

                                <div class="opt-group full-width scale-customizer-container" style="margin-top: 8px;">
                                    <label style="display:block; margin-bottom:6px;">ARTWORK SIZE</label>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <button type="button" class="size-adjust-btn size-minus" style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); color: var(--gal-ink);">-</button>
                                        <span class="scale-badge neutral" style="font-family: monospace; font-size: 13px; font-weight: 600; min-width: 140px; text-align: center; display: inline-block;">No correction (0%)</span>
                                        <button type="button" class="size-adjust-btn size-plus" style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); color: var(--gal-ink);">+</button>
                                    </div>
                                    <input type="hidden" name="size_override" value="<?= h((string)$defaultSizeOverride) ?>" class="premium-size-override">
                                </div>
                            </div>

                            <button type="submit" style="margin-top: 18px;" <?= $isAutoPending ? 'disabled' : '' ?>>
                                <?= $existingMockup ? 'Regenerar' : ($isAutoPending ? 'Generating...' : 'Generate Mockup') ?>
                            </button>
                        </form>

                        <?php if ($isAdmin): ?>
                            <details style="margin-top: 10px;">
                                <summary style="font-size: 11px; font-weight: 600; cursor: pointer; color: var(--gal-muted);">View Technical Prompt</summary>
                                <textarea style="width: 100%; min-height: 100px; font-size: 10px; font-family: monospace; background: var(--gal-bg); color: var(--gal-ink); margin-top: 6px;" readonly><?= h($prompt) ?></textarea>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <!-- Row 2: Pending proposals -->
                <?php if (!empty($pendingContexts)): ?>
                    <?php foreach (array_chunk($pendingContexts, 3) as $batchIndex => $pendingBatch): ?>
                    <?php
                        $batchContextIds = array_values(array_map(
                            fn(array $entry): string => (string)($entry['ctx']['id'] ?? ('ctx_' . ((int)$entry['i'] + 1))),
                            $pendingBatch
                        ));
                        $batchHasActiveJob = false;
                        foreach ($pendingBatch as $batchEntry) {
                            $batchCtxId = (string)($batchEntry['ctx']['id'] ?? ('ctx_' . ((int)$batchEntry['i'] + 1)));
                            foreach ($dbQueueJobs as $job) {
                                if ((string)$job['context_id'] === $batchCtxId && in_array((string)$job['status'], ['queued', 'processing'], true)) {
                                    $batchHasActiveJob = true;
                                    break 2;
                                }
                            }
                        }
                    ?>
                    <?php if ($isAdmin): ?>
                    <div class="contexts-row-header" style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-top: 20px; margin-bottom: 4px;">
                        <button type="button"
                                class="batch-generate-button"
                                data-context-ids="<?= h(implode(',', $batchContextIds)) ?>"
                                <?= $batchHasActiveJob ? 'disabled' : '' ?>
                                style="width: auto; padding: 8px 12px; font-size: 10px; letter-spacing: .08em; border: 1px solid var(--gal-accent); background: var(--gal-accent); color: #fff; border-radius: var(--gal-radius); text-transform: uppercase; font-weight: 700; cursor: pointer;">
                            <?= $batchHasActiveJob ? 'Generating...' : 'Legacy Batch Generator' ?>
                        </button>
                        <span style="font-size: 9px; text-transform: uppercase; letter-spacing: 0.12em; font-weight: 700; color: var(--gal-muted);">Pending Proposals · Batch <?= h($batchIndex + 2) ?></span>
                        <div style="flex: 1; height: 1px; background: var(--gal-border);"></div>
                        <span style="font-size: 9px; color: var(--gal-muted); font-style: italic;">Ready for parallel generation</span>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($pendingBatch as $entry): ?>
                    <?php $i = $entry['i']; $ctx = $entry['ctx']; ?>
                    <?php
                        $prompt = $ctx['prompt'] ?? '';
                        $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                        $humanProfile = $ctx['human_profile'] ?? null;
                        $humanText = match ($humanProfile) {
                            'male_180' => 'male 1.80 m',
                            'female_155' => 'female 1.55 m',
                            default => (!empty($ctx['with_human']) ? 'discreet figure' : 'none'),
                        };

                        $existingMockup = null;
                        foreach ($dbMockups as $m) {
                            if ((string)$m['context_id'] === (string)$ctxId) {
                                $existingMockup = $m;
                                break;
                            }
                        }
                        $selectorState = [];
                        if ($existingMockup && !empty($existingMockup['selector_state_json'])) {
                            $decodedSelectorState = json_decode((string)$existingMockup['selector_state_json'], true);
                            $selectorState = is_array($decodedSelectorState) ? $decodedSelectorState : [];
                        }

                        $queueJob = null;
                        foreach ($dbQueueJobs as $job) {
                            if ((string)$job['context_id'] === (string)$ctxId) {
                                $queueJob = $job;
                                break;
                            }
                        }
                        $queueStatus = $queueJob ? (string)$queueJob['status'] : '';
                        $isAutoPending = !$existingMockup && in_array($queueStatus, ['queued', 'processing'], true);

                        $defaultCamera = 'custom';
                        $cameraGroup = $ctx['camera_group'] ?? '';
                        $cameraRaw = trim((string)($ctx['camera_view'] ?? $ctx['camera'] ?? $ctx['camera_angle'] ?? ''));
                        $cameraRawLower = strtolower($cameraRaw);
                        if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE) {
                            if (in_array($cameraRawLower, ['front', 'front view', 'frontal'], true)) {
                                $defaultCamera = 'front';
                            } elseif (in_array($cameraRawLower, ['3_4_left', 'three_quarter_left', 'three-quarter left', 'three quarter left', '3/4 left'], true)) {
                                $defaultCamera = '3_4_left';
                            } elseif (in_array($cameraRawLower, ['3_4_right', 'three_quarter_right', 'three-quarter right', 'three quarter right', '3/4 right'], true)) {
                                $defaultCamera = '3_4_right';
                            } else {
                                $defaultCamera = 'custom';
                            }
                        } else {
                            $defaultCamera = 'front';
                            if (str_contains($cameraGroup, 'left') || str_contains($cameraRaw, 'left')) {
                                $defaultCamera = '3_4_left';
                            } elseif (str_contains($cameraGroup, 'right') || str_contains($cameraRaw, 'right')) {
                                $defaultCamera = '3_4_right';
                            }
                        }
                        if (in_array(($selectorState['camera_override'] ?? ''), ['front', '3_4_left', '3_4_right'], true)) {
                            $defaultCamera = (string)$selectorState['camera_override'];
                        }
                        $defaultTime = 'sunny_day';
                        $timeOfDay = strtolower($ctx['time_of_day'] ?? '');
                        if (str_contains($timeOfDay, 'cloudy') || str_contains($timeOfDay, 'nublado') || str_contains($timeOfDay, 'overcast')) {
                            $defaultTime = 'cloudy_day';
                        } elseif (str_contains($timeOfDay, 'afternoon') || str_contains($timeOfDay, 'sunset') || str_contains($timeOfDay, 'tarde') || str_contains($timeOfDay, 'golden')) {
                            $defaultTime = 'afternoon';
                        } elseif (str_contains($timeOfDay, 'night') || str_contains($timeOfDay, 'evening') || str_contains($timeOfDay, 'noche') || str_contains($timeOfDay, 'nocturnal')) {
                            $defaultTime = 'night';
                        }
                        if (in_array(($selectorState['time_override'] ?? ''), ['sunny_day', 'cloudy_day', 'afternoon', 'night'], true)) {
                            $defaultTime = (string)$selectorState['time_override'];
                        }
                        $defaultHuman = 'none';
                        if ($humanProfile === 'male_180') {
                            $defaultHuman = 'male_180';
                        } elseif ($humanProfile === 'female_155') {
                            $defaultHuman = 'female_155';
                        } elseif (!empty($ctx['with_human'])) {
                            $defaultHuman = 'female_155';
                        }
                        if (in_array(($selectorState['human_override'] ?? ''), ['none', 'female_155', 'male_180'], true)) {
                            $defaultHuman = (string)$selectorState['human_override'];
                        }
                        $defaultSizeOverride = report_normalize_size_override((string)($selectorState['size_override'] ?? '0'));
                    ?>

                    <div class="card <?= $existingMockup ? 'generated' : '' ?> <?= $isAutoPending ? 'auto-pending' : '' ?>" id="context-<?= h($ctxId) ?>" data-context-id="<?= h($ctxId) ?>" style="opacity: 0.85;">
                        <div class="number">Proposal <?= $i + 1 ?></div>
                        <h3><?= h($ctx['name'] ?? 'Context') ?></h3>
                        <span class="purpose"><?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?></span>

                        <div class="inline-result" aria-live="polite">
                            <?php if ($isAutoPending): ?>
                                <div class="inline-loader" data-auto-status="<?= h($queueStatus) ?>">
                                    <div class="spinner" aria-hidden="true"></div>
                                    <div class="inline-status">Generating mockup...</div>
                                </div>
                            <?php elseif ($queueStatus === 'error'): ?>
                                <div class="inline-status" style="color: var(--gal-danger); font-size: 11px; padding: 10px; text-align: center;">
                                    Error: <?= h($queueJob['error'] ?? 'Automatic generation failed.') ?>
                                </div>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--gal-muted)" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($ctx['why'])): ?>
                            <p class="why-rationale" style="font-size: 12px; line-height: 1.45; color: var(--gal-ink); margin: 12px 0 0 0; font-style: italic;">
                                <strong>Curatorial rationale:</strong> <?= h($ctx['why']) ?>
                            </p>
                        <?php endif; ?>

                        <form class="inline-mockup-form" action="generate_mockup.php" method="post">
                            <input type="hidden" name="image" value="<?= h(basename($imagePath)) ?>">
                            <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                            <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                            <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
                            <input type="hidden" name="ajax" value="1">
                            <input type="hidden" name="camera_override_touched" value="0">
                            <input type="hidden" name="time_override_touched" value="0">
                            <input type="hidden" name="human_override_touched" value="0">
                            <input type="hidden" name="distance_override_touched" value="0">
                            <input type="hidden" name="size_override_touched" value="0">

                            <div class="customizer-options">
                                <div class="opt-group">
                                    <label>Camera Angle <?= (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && $defaultCamera === 'custom') ? '(<span style="color:#4caf50; font-weight:normal;">Custom camera from prompt: ' . h($cameraRaw) . '</span>)' : '' ?></label>
                                    <div class="selector-icons camera-selector">
                                        <button type="button" class="icon-btn <?= $defaultCamera === 'front' ? 'active' : '' ?>" data-value="front" title="Frontal">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="1.5"></rect><line x1="12" y1="6" x2="12" y2="18" stroke-dasharray="2 2"></line></svg>
                                            <span>Frontal</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultCamera === '3_4_left' ? 'active' : '' ?>" data-value="3_4_left" title="3/4 izquierdo">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 8l-10-3v14l10-3z"></path></svg>
                                            <span>3/4 izquierdo</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultCamera === '3_4_right' ? 'active' : '' ?>" data-value="3_4_right" title="3/4 derecho">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8l10-3v14l-10-3z"></path></svg>
                                            <span>3/4 derecho</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="camera_override" value="">
                                </div>
                                <div class="opt-group">
                                    <label>Atmosphere / Lighting</label>
                                    <div class="selector-icons time-selector">
                                        <button type="button" class="icon-btn <?= $defaultTime === 'sunny_day' ? 'active' : '' ?>" data-value="sunny_day" title="Sunny day">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                                            <span>Sunny day</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'cloudy_day' ? 'active' : '' ?>" data-value="cloudy_day" title="Cloudy day">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>
                                            <span>Cloudy day</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'afternoon' ? 'active' : '' ?>" data-value="afternoon" title="Afternoon">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 18a5 5 0 0 0-10 0"></path><line x1="12" y1="2" x2="12" y2="9"></line><line x1="2" y1="18" x2="22" y2="18"></line><line x1="2" y1="21" x2="22" y2="21"></line></svg>
                                            <span>Afternoon</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultTime === 'night' ? 'active' : '' ?>" data-value="night" title="Night">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                                            <span>Night</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="time_override" value="">
                                </div>
                                <div class="opt-group full-width">
                                    <label>Figura humana</label>
                                    <div class="selector-icons human-selector">
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'none' ? 'active' : '' ?>" data-value="none" title="Sin figura humana">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 18c0-1.7-1.3-3-3-3H9c-1.7 0-3 1.3-3 3"></path><circle cx="12" cy="7" r="4"></circle><line x1="2" y1="2" x2="22" y2="22"></line></svg>
                                            <span>Sin figura</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'female_155' ? 'active' : '' ?>" data-value="female_155" title="Figura humana femenina">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="6" r="3.5"></circle><path d="M12 9.5l-4 8h8z"></path><line x1="10" y1="17.5" x2="10" y2="21"></line><line x1="14" y1="17.5" x2="14" y2="21"></line></svg>
                                            <span>Femenina</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= $defaultHuman === 'male_180' ? 'active' : '' ?>" data-value="male_180" title="Figura humana masculina">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5.5" r="2.5"></circle><path d="M9 9.5h6v7h-2v5h-2v-5H9z"></path></svg>
                                            <span>Masculina</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="human_override" value="">
                                </div>
                                <div class="opt-group full-width" style="margin-top: 8px;">
                                    <label>CAMERA DISTANCE</label>
                                    <div class="selector-icons distance-selector">
                                        <button type="button" class="icon-btn <?= ($selectorState['distance_override'] ?? 'medium') === 'close' ? 'active' : '' ?>" data-value="close" title="Acercar (Zoom In)">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                            <span>Acercar (Zoom In)</span>
                                        </button>
                                        <button type="button" class="icon-btn <?= ($selectorState['distance_override'] ?? 'medium') === 'medium' ? 'active' : '' ?>" data-value="medium" title="Alejar (Zoom Out)">
                                            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>
                                            <span>Alejar (Zoom Out)</span>
                                        </button>
                                    </div>
                                    <input type="hidden" name="distance_override" value="">
                                </div>
                                <div class="opt-group full-width scale-customizer-container" style="margin-top: 8px;">
                                    <label style="display:block; margin-bottom:6px;">ARTWORK SIZE</label>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <button type="button" class="size-adjust-btn size-minus" style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); color: var(--gal-ink);">-</button>
                                        <span class="scale-badge neutral" style="font-family: monospace; font-size: 13px; font-weight: 600; min-width: 140px; text-align: center; display: inline-block;">No correction (0%)</span>
                                        <button type="button" class="size-adjust-btn size-plus" style="width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: bold; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); color: var(--gal-ink);">+</button>
                                    </div>
                                    <input type="hidden" name="size_override" value="<?= h((string)$defaultSizeOverride) ?>" class="premium-size-override">
                                </div>
                            </div>

                            <button type="submit" style="margin-top: 18px;" <?= $isAutoPending ? 'disabled' : '' ?>>
                                <?= $isAutoPending ? 'Generating...' : 'Generate Mockup' ?>
                            </button>
                        </form>

                        <?php if ($isAdmin): ?>
                            <details style="margin-top: 10px;">
                                <summary style="font-size: 11px; font-weight: 600; cursor: pointer; color: var(--gal-muted);">View Technical Prompt</summary>
                                <textarea style="width: 100%; min-height: 100px; font-size: 10px; font-family: monospace; background: var(--gal-bg); color: var(--gal-ink); margin-top: 6px;" readonly><?= h($prompt) ?></textarea>
                            </details>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

            <div class="back">
                <?php if ($artworkId): ?>
                    <a href="artwork_details.php?id=<?= (int)$artworkId ?>">Back to step 4 (Artwork Details)</a>
                    &nbsp;·&nbsp;
                <?php endif; ?>
                <a href="artwork_new.php">Back to step 1</a>
                &nbsp;·&nbsp;
                <a href="dashboard.php">Dashboard</a>
            </div>
        </div>
    </div>
        </div>
    </main>
</div>


<script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const batchStatusUrl = 'mockup_batch_status.php?image=<?= rawurlencode(basename($imagePath)) ?>';
    const batchGenerateUrl = 'generate_mockup_batch.php';
    const batchImage = <?= json_encode(basename($imagePath), JSON_UNESCAPED_SLASHES) ?>;
    const reportBackUrl = <?= json_encode($reportBackUrl, JSON_UNESCAPED_SLASHES) ?>;

    document.querySelectorAll('.admin-copy-mockup-prompt').forEach((button) => {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.target);
            if (!target) return;

            target.select();
            target.setSelectionRange(0, target.value.length);

            try {
                await navigator.clipboard.writeText(target.value);
            } catch (e) {
                document.execCommand('copy');
            }

            const originalText = button.textContent;
            button.textContent = 'Copied';
            setTimeout(() => {
                button.textContent = originalText;
            }, 1400);
        });
    });

    function renderGeneratedMockup(card, resultBox, data) {
        card.classList.add('generated');
        card.classList.remove('auto-pending');

        const promptLink = isAdmin && data.prompt_url
            ? `<a href="${escapeAttribute(data.prompt_url)}" target="_blank" rel="noopener">Prompt</a>`
            : '';

        const contextAnchor = data.context_id ? `#context-${encodeURIComponent(String(data.context_id))}` : '';
        const backUrl = reportBackUrl + contextAnchor;
        const viewerUrl = data.viewer_url
            ? `${data.viewer_url}${String(data.viewer_url).includes('?') ? '&' : '?'}back=${encodeURIComponent(backUrl)}`
            : data.image_url;

        const mockupId = data.mockup_id || data.id;
        const deleteButton = mockupId
            ? `<button type="button" class="btn-delete-mockup" data-mockup-id="${escapeAttribute(mockupId)}" title="Delete Mockup" style="background: transparent; border: none; color: var(--gal-danger); cursor: pointer; padding: 4px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">
                <svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                    <line x1="10" y1="11" x2="10" y2="17"></line>
                    <line x1="14" y1="11" x2="14" y2="17"></line>
                </svg>
                Delete
               </button>`
            : '';

        resultBox.innerHTML = `
            <a class="inline-thumb" href="${escapeAttribute(viewerUrl)}" aria-label="Open generated mockup">
                <img src="${escapeAttribute(data.image_url)}" alt="Generated mockup">
            </a>
            <div class="inline-actions" style="display: flex; gap: 10px; align-items: center; margin-top: 8px;">
                <a href="${escapeAttribute(data.download_url)}" aria-label="Download mockup" title="Download">
                    <span class="download-icon" aria-hidden="true"></span>
                </a>
                ${promptLink}
                ${deleteButton}
            </div>
        `;

        const button = card.querySelector('.inline-mockup-form button[type="submit"]');
        if (button) {
            button.disabled = false;
            button.innerHTML = 'Regenerate';
        }

        const form = card.querySelector('.inline-mockup-form');
        if (form && data.mockup_file) {
            let currentInput = form.querySelector('input[name="current_mockup_file"]');
            if (!currentInput) {
                currentInput = document.createElement('input');
                currentInput.type = 'hidden';
                currentInput.name = 'current_mockup_file';
                form.appendChild(currentInput);
            }
            currentInput.value = data.mockup_file;
        }
    }

    // Toggle active class and update hidden inputs in customizer groups
    document.querySelectorAll('.selector-icons .icon-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const group = btn.closest('.selector-icons');
            const parent = btn.closest('.opt-group');
            const hiddenInput = parent.querySelector('input[type="hidden"]');
            
            group.querySelectorAll('.icon-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hiddenInput.value = btn.dataset.value;

            // Set touched flag
            const name = hiddenInput.getAttribute('name');
            if (name) {
                const form = btn.closest('form');
                const touchedInput = form.querySelector(`input[name="${name}_touched"]`);
                if (touchedInput) {
                    touchedInput.value = "1";
                }
            }
        });
    });

    let mockupGenerationQueue = Promise.resolve();
    let pendingManualGenerations = 0;

    async function runMockupGeneration(form, formData, card, resultBox, button, originalHtml) {
        resultBox.innerHTML = `
            <div class="inline-loader">
                <div class="spinner" aria-hidden="true"></div>
                <div class="inline-status">Generating mockup...</div>
            </div>
        `;
        button.innerHTML = '<svg class="spinner-btn" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" style="animation: spin 0.85s linear infinite; display: inline-block; vertical-align: middle;"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"></circle><path d="M12 2a10 10 0 0 1 10 10"></path></svg>';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const rawText = await response.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseError) {
                const readable = rawText
                    .replace(/<br\s*\/?>/gi, '\n')
                    .replace(/<[^>]+>/g, ' ')
                    .replace(/\s+/g, ' ')
                    .trim();
                throw new Error(readable || 'The server returned an invalid response while generating the mockup.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not generate mockup.');
            }

            renderGeneratedMockup(card, resultBox, data);
        } catch (error) {
            resultBox.innerHTML = `<div class="inline-status" style="color: var(--gal-danger); font-size: 11px; padding: 10px; text-align: center;">Error: ${escapeHtml(error.message)}</div>`;
            button.innerHTML = originalHtml;
            button.disabled = false;
        } finally {
            pendingManualGenerations = Math.max(0, pendingManualGenerations - 1);
            if (!card.classList.contains('generated')) {
                button.disabled = false;
            }
        }
    }

    document.querySelectorAll('.inline-mockup-form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const card = form.closest('.card');
            const resultBox = card.querySelector('.inline-result');
            const button = form.querySelector('button[type="submit"]');
            const originalHtml = button.innerHTML;
            const formData = new FormData(form);

            card.classList.remove('generated');
            resultBox.classList.add('active');
            button.disabled = true;
            pendingManualGenerations++;

            resultBox.innerHTML = `
                <div class="inline-loader">
                    <div class="spinner" aria-hidden="true"></div>
                <div class="inline-status">${pendingManualGenerations > 1 ? 'Waiting in queue...' : 'Generating mockup...'}</div>
                </div>
            `;

            mockupGenerationQueue = mockupGenerationQueue
                .catch(() => undefined)
                .then(() => runMockupGeneration(form, formData, card, resultBox, button, originalHtml));
        });
    });

    // Update size buttons dynamically
    document.querySelectorAll('.scale-customizer-container').forEach((container) => {
        const minusBtn = container.querySelector('.size-minus');
        const plusBtn = container.querySelector('.size-plus');
        const badge = container.querySelector('.scale-badge');
        const hiddenInput = container.querySelector('.premium-size-override');
        
        const updateBadge = (val) => {
            const numVal = parseInt(val, 10) || 0;
            if (numVal === 0) {
                badge.textContent = 'No correction (0%)';
                badge.className = 'scale-badge neutral';
            } else if (numVal > 0) {
                badge.textContent = `Larger (+${numVal}%)`;
                badge.className = 'scale-badge positive';
            } else {
                badge.textContent = `Smaller (${numVal}%)`;
                badge.className = 'scale-badge negative';
            }
            hiddenInput.value = numVal;
        };

        // Initialize
        updateBadge(hiddenInput.value);

        minusBtn.addEventListener('click', () => {
            let val = parseInt(hiddenInput.value, 10) || 0;
            val = Math.max(-50, val - 5);
            updateBadge(val);
            const form = container.closest('form');
            const touchedInput = form.querySelector('input[name="size_override_touched"]');
            if (touchedInput) {
                touchedInput.value = "1";
            }
        });

        plusBtn.addEventListener('click', () => {
            let val = parseInt(hiddenInput.value, 10) || 0;
            val = Math.min(50, val + 5);
            updateBadge(val);
            const form = container.closest('form');
            const touchedInput = form.querySelector('input[name="size_override_touched"]');
            if (touchedInput) {
                touchedInput.value = "1";
            }
        });
    });

    // Generic copy buttons
    document.querySelectorAll('.copy-button').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const text = btn.dataset.copy;
            if (!text) return;
            const original = btn.innerHTML;
            try {
                await navigator.clipboard.writeText(text);
                btn.innerHTML = 'Copiado';
                btn.classList.add('success');
                setTimeout(() => {
                    btn.innerHTML = original;
                    btn.classList.remove('success');
                }, 1200);
            } catch (e) {
                btn.innerHTML = 'Error';
                setTimeout(() => {
                    btn.innerHTML = original;
                }, 1200);
            }
        });
    });

    let batchPollingActive = Boolean(document.querySelector('[data-auto-status]'));
    let batchPollingTimer = null;

    document.querySelectorAll('.batch-generate-button').forEach((button) => {
        button.addEventListener('click', async () => {
            const contextIds = String(button.dataset.contextIds || '')
                .split(',')
                .map((value) => value.trim())
                .filter(Boolean);

            if (contextIds.length === 0) {
                return;
            }

            button.disabled = true;
            button.textContent = 'Generating...';

            contextIds.forEach((contextId) => {
                const card = document.querySelector(`.card[data-context-id="${CSS.escape(String(contextId))}"]`);
                if (!card) {
                    return;
                }

                const resultBox = card.querySelector('.inline-result');
                const submitButton = card.querySelector('.inline-mockup-form button[type="submit"]');
                card.classList.add('auto-pending');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = 'Generating...';
                }
                if (resultBox) {
                    resultBox.innerHTML = `
                        <div class="inline-loader" data-auto-status="processing">
                            <div class="spinner" aria-hidden="true"></div>
                            <div class="inline-status">Generating mockup...</div>
                        </div>
                    `;
                }
            });

            const formData = new FormData();
            formData.append('image', batchImage);
            contextIds.forEach((contextId) => formData.append('context_ids[]', contextId));

            try {
                const response = await fetch(batchGenerateUrl, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.error || 'Could not start this batch.');
                }

                batchPollingActive = true;
                if (batchPollingTimer) {
                    window.clearTimeout(batchPollingTimer);
                }
                batchPollingTimer = window.setTimeout(pollBatchStatus, 1000);
            } catch (error) {
                button.disabled = false;
                button.textContent = 'Legacy Batch Generator';
                contextIds.forEach((contextId) => {
                    const card = document.querySelector(`.card[data-context-id="${CSS.escape(String(contextId))}"]`);
                    if (!card) {
                        return;
                    }
                    const resultBox = card.querySelector('.inline-result');
                    const submitButton = card.querySelector('.inline-mockup-form button[type="submit"]');
                    card.classList.remove('auto-pending');
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = 'Generate Mockup';
                    }
                    if (resultBox) {
                        resultBox.innerHTML = `<div class="inline-status" style="color: var(--gal-danger); font-size: 11px; padding: 10px; text-align: center;">Error: ${escapeHtml(error.message)}</div>`;
                    }
                });
            }
        });
    });

    async function pollBatchStatus() {
        if (!batchPollingActive) {
            return;
        }

        try {
            const response = await fetch(batchStatusUrl, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Could not read automatic mockup status.');
            }

            let hasPending = false;
            (data.jobs || []).forEach((job) => {
                const card = document.querySelector(`.card[data-context-id="${CSS.escape(String(job.context_id))}"]`);
                if (!card) {
                    return;
                }

                const resultBox = card.querySelector('.inline-result');
                const button = card.querySelector('.inline-mockup-form button[type="submit"]');

                if (job.status === 'done' && job.image_url) {
                    renderGeneratedMockup(card, resultBox, job);
                    return;
                }

                if (job.status === 'queued' || job.status === 'processing') {
                    hasPending = true;
                    card.classList.add('auto-pending');
                    if (button) {
                        button.disabled = true;
                        button.innerHTML = 'Generating...';
                    }
                    resultBox.innerHTML = `
                        <div class="inline-loader" data-auto-status="${escapeAttribute(job.status)}">
                            <div class="spinner" aria-hidden="true"></div>
                            <div class="inline-status">Generating mockup...</div>
                        </div>
                    `;
                    return;
                }

                if (job.status === 'error') {
                    card.classList.remove('auto-pending');
                    if (button) {
                        button.disabled = false;
                        button.innerHTML = 'Generate Mockup';
                    }
                    resultBox.innerHTML = `<div class="inline-status" style="color: var(--gal-danger); font-size: 11px; padding: 10px; text-align: center;">Error: ${escapeHtml(job.error || 'Automatic generation failed.')}</div>`;
                }
            });

            batchPollingActive = hasPending;
        } catch (error) {
            batchPollingActive = false;
            console.warn(error);
        }

        if (batchPollingActive) {
            batchPollingTimer = window.setTimeout(pollBatchStatus, 3500);
        } else if (batchPollingTimer) {
            window.clearTimeout(batchPollingTimer);
        }
    }

    if (batchPollingActive) {
        window.setTimeout(pollBatchStatus, 1200);
    }

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function escapeAttribute(value) {
        return escapeHtml(value);
    }

    document.addEventListener('click', async (event) => {
        const deleteBtn = event.target.closest('.btn-delete-mockup');
        if (!deleteBtn) return;

        if (!confirm('Are you sure you want to delete this mockup?')) {
            return;
        }

        const mockupId = deleteBtn.getAttribute('data-mockup-id');
        const card = deleteBtn.closest('.card') || deleteBtn.closest('article');
        
        deleteBtn.disabled = true;

        try {
            const response = await fetch('delete_mockup.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'mockup_id=' + encodeURIComponent(mockupId)
            });

            const data = await response.json();
            if (data.ok) {
                if (card) {
                    if (card.classList.contains('card')) {
                        // Reset card UI in report.php
                        card.classList.remove('generated');
                        const resultBox = card.querySelector('.inline-result');
                        if (resultBox) {
                            resultBox.innerHTML = `
                                <svg viewBox="0 0 24 24" width="32" height="32" stroke="var(--gal-muted)" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round" style="opacity: 0.5;">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                            `;
                        }
                        const button = card.querySelector('.inline-mockup-form button[type="submit"]');
                        if (button) {
                            button.disabled = false;
                            button.innerHTML = 'Generate Mockup';
                        }
                        const currentInput = card.querySelector('input[name="current_mockup_file"]');
                        if (currentInput) {
                            currentInput.remove();
                        }
                    } else {
                        card.remove();
                    }
                }
            } else {
                alert('Error: ' + (data.error || 'No se pudo eliminar el mockup.'));
                deleteBtn.disabled = false;
            }
        } catch (err) {
            alert('Error de red al intentar eliminar.');
            deleteBtn.disabled = false;
        }
    });
</script>

</body>
</html>
