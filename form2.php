<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$currentUser = Auth::requireUser();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
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

function assert_root_owner(string $imagePath, array $user): void
{
    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo(basename($imagePath), PATHINFO_FILENAME) . '.meta.json';

    if (!is_file($metaPath)) {
        http_response_code(403);
        die('No se encontro metadata de propiedad para esta obra.');
    }

    $data = json_decode((string)file_get_contents($metaPath), true);

    if (is_array($data) && (int)($data['user_id'] ?? 0) !== (int)$user['id']) {
        http_response_code(403);
        die('No tienes acceso a esta obra.');
    }
}

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
    die('Falta la imagen. Usa: form2.php?image=nombre_imagen.png');
}

$imagePath = find_file($image);

if (!$imagePath) {
    die('No se encontró la imagen: ' . h($image));
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

$contextsForValidation = $analysis['recommended_contexts'] ?? [];
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
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_left')) >= 3 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'three_quarter_right')) >= 3 &&
    count(array_filter($cameraGroupsForValidation, fn($v) => $v === 'front_close')) >= 2 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'day')) >= 4 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'afternoon')) >= 3 &&
    count(array_filter($timesForValidation, fn($v) => $v === 'night')) >= 3 &&
    count($humanProfilesForValidation) >= 4 &&
    in_array('male_180', $humanProfilesForValidation, true) &&
    in_array('female_155', $humanProfilesForValidation, true);
$hasExpectedMockupQuotas = $expectedContextCount >= 10 ? $hasFullMockupQuotas : $hasBasicContextShape;

if (
    !$analysis ||
    empty($contextsForValidation) ||
    count($contextsForValidation) !== $expectedContextCount ||
    str_contains((string)$firstPromptForValidation, 'Prototype prompt generated locally') ||
    !$hasDynamicScaleText ||
    !$hasScaleAnchors ||
    !$hasExpectedMockupQuotas ||
    !$hasCurrentArtistProfileInAnalysis ||
    ($hasRootMeta && !$analysisWidthCm)
) {
    if (isset($_GET['json']) && $_GET['json'] !== '') {
        http_response_code(500);
        die('No se pudo generar un analisis valido para Formulario 2. Revisa el JSON de analisis: ' . h((string)$_GET['json']));
    }

    $analyzeUrl = 'analyze.php?image=' . rawurlencode(basename($imagePath)) . '&redirect=1';
    header('Location: ' . $analyzeUrl);
    exit;
}

$profile = $analysis['artwork_profile'] ?? [];
$contexts = $analysis['recommended_contexts'] ?? [];
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

$sizeText = '';

if ($widthCm && $heightCm) {
    $sizeText = $widthCm . ' × ' . $heightCm . ' cm';

    if ($depthCm) {
        $sizeText .= ' × ' . $depthCm . ' cm';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Formulario 2 - Dirección curatorial</title>
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
            max-width: 1440px;
            margin: 0 auto;
            padding: 0;
        }

        /* Split Curatorial Layout */
        .curatorial-layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 48px;
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

        /* Context cards grid */
        .contexts {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 24px;
            display: flex;
            flex-direction: column;
            min-height: 580px;
            box-shadow: var(--gal-shadow);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            border-radius: var(--gal-radius);
            position: relative;
            overflow: hidden;
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
            font-size: 22px;
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
            border: 1px solid var(--gal-border);
            padding: 10px;
            border-radius: var(--gal-radius);
        }

        .inline-result img {
            width: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
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
            align-items: flex-start;
            gap: 12px;
        }

        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid var(--gal-border);
            border-top-color: var(--gal-accent);
            border-radius: 50%;
            animation: spin 0.85s linear infinite;
            flex-shrink: 0;
            margin-top: 2px;
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

        @media (max-width: 768px) {
            .contexts {
                grid-template-columns: 1fr;
            }
            body {
                padding: 0;
            }
            .workspace {
                padding: 20px 16px 40px;
            }
        }
    </style>
</head>

<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="dashboard.php">ARTMOCK <span class="brand-mark"></span></a>
        </div>

        <div class="sidebar-action">
            <a class="button-link" href="artwork_new.php">+ Nueva obra</a>
        </div>

        <ul class="nav">
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a href="artwork_new.php">Crear obra raiz</a></li>
            <li><a href="artist_profile.php">Perfil de artista</a></li>
            <?php if ($isAdmin): ?>
                <li><a href="admin_prompts.php">Admin prompts</a></li>
                <li><a href="admin_api_keys.php">API keys</a></li>
            <?php endif; ?>
            <li><a class="active" href="form2.php?image=<?= rawurlencode(basename($imagePath)) ?>">Direccion artistica</a></li>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($currentUser['email']) ?></a>
        </header>

        <div class="alert-strip">
            Formulario 2: selecciona una direccion curatorial para generar el mockup.
        </div>

        <div class="workspace">
            <div class="wrap">

    <div class="curatorial-layout">
        <!-- Sidebar: Sticky Curatorial Info -->
        <aside class="curatorial-sidebar">
            <div class="artwork-box">
                <img src="<?= h($imagePublic) ?>" alt="Imagen raíz">
                <div class="artwork-dimensions">
                    <span>Medidas físicas</span>
                    <strong><?= h($sizeText ?: 'No especificadas') ?></strong>
                </div>
            </div>

            <div class="profile">
                <h2>Lectura Curatorial</h2>
                <?php if (!empty($profile['one_line_curatorial_read'])): ?>
                    <div class="curatorial-read-line">
                        "<?= h($profile['one_line_curatorial_read']) ?>"
                    </div>
                <?php endif; ?>

                <div class="profile-details">
                    <div class="detail-block">
                        <strong>Estilo Artístico</strong>
                        <p><?= h($profile['style_summary'] ?? '-') ?></p>
                        <div class="curatorial-tags">
                            <?php foreach (($profile['style_tags'] ?? []) as $tag): ?>
                                <span class="curatorial-tag"><?= h($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="detail-block">
                        <strong>Paleta Cromática</strong>
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
                                Familia: <?= h(implode(', ', $profile['palette_family'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="detail-block">
                        <strong>Atmósfera y Emoción</strong>
                        <div class="curatorial-tags">
                            <?php foreach (($profile['mood_tags'] ?? []) as $tag): ?>
                                <span class="curatorial-tag"><?= h($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="meta-mini-grid">
                            <div><span>Luminosidad</span> <strong><?= h($profile['luminosity'] ?? '-') ?></strong></div>
                            <div><span>Saturación</span> <strong><?= h($profile['saturation'] ?? '-') ?></strong></div>
                            <div><span>Temperatura</span> <strong><?= h($emotionalTemperature ?: '-') ?></strong></div>
                            <div><span>Presencia Onírica</span> <strong><?= h($dreamlikeLevel ?: '-') ?></strong></div>
                        </div>
                    </div>

                    <div class="detail-block">
                        <strong>Estrategia Comercial</strong>
                        <div class="meta-mini-grid">
                            <div><span>Público</span> <strong><?= h($audience ?: '-') ?></strong></div>
                            <div><span>Temporada</span> <strong><?= h($season ?: '-') ?></strong></div>
                        </div>
                        <div style="margin-top: 12px;">
                            <strong>Espacios sugeridos</strong>
                            <div class="curatorial-tags">
                                <?php foreach (($profile['commercial_fit'] ?? []) as $space): ?>
                                    <span class="curatorial-tag tag-commercial"><?= h($space) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($profile['avoid'])): ?>
                        <div class="detail-block">
                            <strong>Evitar en Presentación</strong>
                            <div class="curatorial-tags">
                                <?php foreach ($profile['avoid'] as $avoidItem): ?>
                                    <span class="curatorial-tag tag-avoid"><?= h($avoidItem) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($profile['materiality_strategy']['show'])): ?>
                        <div class="detail-block">
                            <strong>Destacar Texturas / Materialidad</strong>
                            <div class="curatorial-tags">
                                <?php foreach ($profile['materiality_strategy']['show'] as $matItem): ?>
                                    <span class="curatorial-tag tag-materiality"><?= h($matItem) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- Main Workspace Area -->
        <div class="contexts-area">
            <div class="contexts-header">
                <h1>Dirección artística para mockups</h1>
                <div class="subtitle">
                    Esta pantalla lee la imagen raiz y propone diez direcciones curatoriales para presentar la obra con intencion, escala y contexto.
                </div>
                
                <?php if ($mockNotice): ?>
                    <div class="mock-warning">
                        <?= h($mockNotice) ?>
                    </div>
                <?php endif; ?>
            </div>

            <h2><?= h(count($contexts)) ?> propuestas de contexto para esta obra</h2>

            <div class="contexts">
                <?php foreach ($contexts as $i => $ctx): ?>
                    <?php
                        $prompt = $ctx['prompt'] ?? '';
                        $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                        $humanProfile = $ctx['human_profile'] ?? null;
                        $humanText = match ($humanProfile) {
                            'male_180' => 'hombre 1,80 m',
                            'female_155' => 'mujer 1,55 m',
                            default => (!empty($ctx['with_human']) ? 'figura discreta' : 'no'),
                        };
                    ?>

                    <div class="card">
                        <div class="number">Propuesta <?= $i + 1 ?></div>

                        <h3><?= h($ctx['name'] ?? 'Contexto') ?></h3>

                        <span class="purpose">
                            <?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?>
                        </span>

                        <div class="inline-result" aria-live="polite">
                            <div class="inline-status">Esperando generador...</div>
                        </div>

                        <div class="card-copy">
                            <div class="meta-grid">
                                <div class="meta-item"><span>Cámara</span><strong><?= h($ctx['camera'] ?? '-') ?></strong></div>
                                <div class="meta-item"><span>Momento</span><strong><?= h($ctx['time_of_day'] ?? '-') ?></strong></div>
                                <div class="meta-item"><span>Colocación</span><strong><?= h($ctx['placement'] ?? '-') ?></strong></div>
                                <div class="meta-item"><span>Escala Humana</span><strong><?= h($humanText) ?></strong></div>
                                <div class="meta-item" style="grid-column: span 2;">
                                    <span>Ajuste Curatorial</span>
                                    <div class="score-indicator">
                                        <strong><?= h($ctx['score'] ?? '-') ?> pts</strong>
                                        <div class="score-bar-track">
                                            <?php
                                            $scoreVal = (int)($ctx['score'] ?? 0);
                                            $scorePct = min(100, max(0, ($scoreVal / 25) * 100));
                                            ?>
                                            <div class="score-bar-fill" style="width: <?= $scorePct ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="scene-text">
                                <strong>Escena</strong>
                                <?= h($ctx['scene'] ?? '-') ?>
                            </div>

                            <div class="lighting-text">
                                <strong>Luz e Iluminación</strong>
                                <?= h($ctx['lighting'] ?? '-') ?>
                            </div>

                            <?php if (!empty($ctx['why'])): ?>
                                <div class="why">
                                    <?= h($ctx['why']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form class="inline-mockup-form" action="generate_mockup.php" method="post">
                            <input type="hidden" name="image" value="<?= h(basename($imagePath)) ?>">
                            <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                            <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                            <input type="hidden" name="prompt" value="<?= h($prompt) ?>">
                            <input type="hidden" name="ajax" value="1">

                            <button type="submit">
                                Generar este mockup
                            </button>
                        </form>

                        <?php if ($isAdmin): ?>
                            <details>
                                <summary>Ver prompt técnico</summary>
                                <textarea readonly><?= h($prompt) ?></textarea>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="back">
                <a href="artwork_new.php">Volver al formulario inicial</a>
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

    document.querySelectorAll('.inline-mockup-form').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const card = form.closest('.card');
            const resultBox = card.querySelector('.inline-result');
            const button = form.querySelector('button');
            const originalText = button.textContent;

            card.classList.remove('generated');
            resultBox.classList.add('active');
            resultBox.innerHTML = `
                <div class="inline-loader">
                    <div class="spinner" aria-hidden="true"></div>
                    <div class="inline-status">
                        Generando mockup. Puedes seguir revisando las otras propuestas.
                        <div class="loader-track" aria-hidden="true"></div>
                    </div>
                </div>
            `;
            button.disabled = true;
            button.textContent = 'Generando...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const data = await response.json();

                if (!response.ok || !data.ok) {
                    throw new Error(data.error || 'No se pudo generar el mockup.');
                }

                card.classList.add('generated');
                const promptLink = isAdmin
                    ? `<a href="${escapeAttribute(data.prompt_url)}" target="_blank" rel="noopener">Prompt</a>`
                    : '';
                resultBox.innerHTML = `
                    <a class="inline-thumb" href="${escapeAttribute(data.viewer_url)}" aria-label="Abrir mockup generado">
                        <img src="${escapeAttribute(data.image_url)}" alt="Mockup generado">
                    </a>
                    <div class="inline-actions">
                        <a href="${escapeAttribute(data.download_url)}" aria-label="Descargar mockup" title="Descargar">
                            <span class="download-icon" aria-hidden="true"></span>
                        </a>
                        ${promptLink}
                    </div>
                `;
                button.textContent = 'Generar otra vez';
            } catch (error) {
                resultBox.innerHTML = '<div class="inline-status">Error: ' + escapeHtml(error.message) + '</div>';
                button.textContent = originalText;
            } finally {
                button.disabled = false;
            }
        });
    });

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
</script>

</body>
</html>
