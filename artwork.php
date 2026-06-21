<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

// Support loading by artwork ID or fallback to root image filename
$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0 && isset($_GET['image'])) {
    $imageFile = basename((string)$_GET['image']);
    $stmt = $pdo->prepare('SELECT id FROM artworks WHERE root_file = :root_file AND user_id = :user_id LIMIT 1');
    $stmt->execute(['root_file' => $imageFile, 'user_id' => (int)$user['id']]);
    $id = (int)$stmt->fetchColumn();
}

if ($id <= 0) {
    // Attempt load latest artwork
    $stmt = $pdo->prepare("SELECT id FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
    $stmt->execute(['user_id' => (int)$user['id']]);
    $id = (int)$stmt->fetchColumn();
}

if ($id <= 0) {
    http_response_code(404);
    die('Artwork not found. Start by uploading an artwork.');
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file, bool $download = false): string
{
    if (!$file) {
        return '';
    }
    $url = 'media.php?file=' . rawurlencode(basename($file));
    return $download ? $url . '&download=1' : $url;
}

function read_json_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function words_from($value): array
{
    if (is_array($value)) {
        $items = [];
        foreach ($value as $item) {
            $items = array_merge($items, words_from($item));
        }
        return $items;
    }
    $parts = preg_split('/[,;|\/\n]+/', strtolower((string)$value));
    return array_values(array_filter(array_map(
        fn($part) => trim(preg_replace('/\s+/', ' ', (string)$part)),
        $parts ?: []
    )));
}

function unique_limited(array $items, int $limit, array $fallback = []): array
{
    $out = [];
    foreach (array_merge($items, $fallback) as $item) {
        $item = trim(preg_replace('/\s+/', ' ', (string)$item));
        if ($item === '') {
            continue;
        }
        $key = strtolower($item);
        if (!isset($out[$key])) {
            $out[$key] = $item;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return array_values($out);
}

function sentence_from(array $items, string $fallback): string
{
    $items = unique_limited($items, 4);
    return $items ? implode(', ', $items) : $fallback;
}

function slugify(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $value), '-'));
    return $slug !== '' ? $slug : 'artwork';
}

function first_sentence(string $value): string
{
    $value = trim((string)preg_replace('/\s+/', ' ', $value));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(.+?[.!?])\s+/', $value . ' ', $match)) {
        return trim($match[1]);
    }
    return $value;
}

function looks_spanish(string $value): bool
{
    return (bool)preg_match('/\b(obra|artista|coleccion|coleccionistas|galerias|galerias|arquitectos|interioristas|decoradores|compradores|personas|lenguaje|visual|construye|partir|silenciosas|simbolicas|metafisicas|territorio|austeridad)\b/i', $value);
}

function clean_public_copy(string $copy, array $artistProfile): string
{
    $forbidden = [
        'This artwork is presented as',
        'This version positions the piece',
        'collector-grade silence',
        'curatorial narrative',
        'commercial presentation',
        'publication-ready',
        'for galleries, curators and interior designers',
        'overly academic language',
        'generic marketplace filler text'
    ];
    foreach ($forbidden as $phrase) {
        $copy = str_ireplace((string)$phrase, '', $copy);
    }
    $copy = preg_replace('/\s+([,.])/', '$1', $copy);
    $copy = preg_replace('/\s{2,}/', ' ', (string)$copy);
    return trim((string)$copy);
}

function build_artwork_package_v2(array $artwork, array $analysis, array $artistProfile): array
{
    $artist = trim((string)($artistProfile['artist_name'] ?? ''));
    $rootSuggestedTitles = is_array($analysis['suggested_titles'] ?? null) ? $analysis['suggested_titles'] : [];
    $isNewSchema = array_key_exists('suggested_titles', $analysis) || array_key_exists('contextual_proposals', $analysis);
    $profile = $analysis['artwork_analysis'] ?? $analysis['artwork_profile'] ?? [];

    $titles = [];
    $titleSubtitles = [];
    $titleDescriptions = [];

    $legacySuggestedTitles = is_array($profile['publishing_metadata']['suggested_titles'] ?? null)
        ? $profile['publishing_metadata']['suggested_titles']
        : [];
    if (isset($rootSuggestedTitles['title'])) {
        $rootSuggestedTitles = [$rootSuggestedTitles];
    }
    if (isset($legacySuggestedTitles['title'])) {
        $legacySuggestedTitles = [$legacySuggestedTitles];
    }
    $rawSuggestedTitles = $rootSuggestedTitles ?: $legacySuggestedTitles;

    if (is_array($rawSuggestedTitles)) {
        foreach ($rawSuggestedTitles as $idx => $tObj) {
            if (is_array($tObj)) {
                $title = trim((string)($tObj['title'] ?? ''));
                $sub = trim((string)($tObj['subtitle'] ?? ''));
                $desc = trim((string)($tObj['description'] ?? ''));

                if (!$isNewSchema && $desc === '') {
                    foreach (['curatorial_description', 'commercial_description', 'short_description', 'description'] as $k) {
                        if (trim((string)($tObj[$k] ?? '')) !== '') {
                            $desc = trim((string)$tObj[$k]);
                            break;
                        }
                    }
                }
                if ($title !== '') {
                    $titles[] = $title;
                    $titleSubtitles[$title] = $sub;
                    $titleDescriptions[$title] = $desc;
                }
            } elseif (is_string($tObj)) {
                $title = trim((string)$tObj);
                if ($title !== '') {
                    $titles[] = $title;
                    $titleSubtitles[$title] = '';
                    $titleDescriptions[$title] = '';
                }
            }
        }
    }

    if (empty($titles)) {
        $titles = ['Untitled'];
        $titleSubtitles = ['Untitled' => ''];
        $titleDescriptions = ['Untitled' => ''];
    }

    $storedTitle = trim((string)($artwork['final_title'] ?? ''));
    $storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
    $titleForCopy = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $titles[0];
    
    $suggestedSubtitle = $titleSubtitles[$titleForCopy] ?? '';
    $subtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $suggestedSubtitle;
    $titleLine = $titleForCopy . ($subtitle !== '' ? ': ' . $subtitle : '');
    $fileSlug = slugify(($artist !== '' ? $artist . '-' : '') . $titleForCopy);

    $description = '';
    if (!empty($titleDescriptions[$titleForCopy])) {
        $description = clean_public_copy($titleDescriptions[$titleForCopy], $artistProfile);
    } elseif (!empty($titles[0]) && !empty($titleDescriptions[$titles[0]])) {
        $description = clean_public_copy($titleDescriptions[$titles[0]], $artistProfile);
    }

    $premiumDescriptions = [];
    foreach ($titles as $titleOption) {
        $copy = $titleDescriptions[$titleOption] ?? '';
        if ($copy === '' && !$isNewSchema) {
            $copy = $description;
        }
        $premiumDescriptions[$titleOption] = trim(clean_public_copy($copy, $artistProfile));
    }

    return [
        'root_alt' => 'Clean root image of ' . $titleForCopy,
        'root_caption' => $titleLine,
        'titles' => $titles,
        'title_subtitles' => $titleSubtitles,
        'premium_descriptions' => $premiumDescriptions,
        'suggested_subtitle' => $suggestedSubtitle,
        'description' => $description,
        'curatorial_reading' => first_sentence($description),
        'seo_slug' => $fileSlug,
        'file_names' => [
            $fileSlug . '-root-artwork.jpg',
        ],
    ];
}

function normalize_artwork_contexts(array $contexts, array $dbContexts = []): array
{
    $idByName = [];
    $promptByName = [];
    foreach ($dbContexts as $dbCtx) {
        $name = trim((string)($dbCtx['context_name'] ?? ''));
        if ($name !== '') {
            $idByName[$name] = (string)($dbCtx['id'] ?? '');
            $promptByName[$name] = (string)($dbCtx['prompt'] ?? '');
        }
    }

    $normalized = [];
    foreach ($contexts as $index => $context) {
        if (!is_array($context)) {
            continue;
        }
        $name = (string)($context['name'] ?? $context['context_name'] ?? $context['title'] ?? ('Context ' . ($index + 1)));
        $prompt = (string)($context['prompt'] ?? $promptByName[$name] ?? $context['mockup_prompt'] ?? '');
        $normalized[] = [
            'id' => $idByName[$name] ?? (string)($context['id'] ?? $context['context_id'] ?? ('ctx_' . ($index + 1))),
            'name' => $name,
            'purpose' => (string)($context['context_role'] ?? $context['purpose'] ?? ''),
            'why' => (string)($context['why'] ?? $context['curatorial_reason'] ?? $context['commercial_reason'] ?? ''),
            'camera_group' => (string)($context['camera_group'] ?? $context['camera_view'] ?? ''),
            'time_of_day' => (string)($context['time_of_day'] ?? ''),
            'prompt' => $prompt,
        ];
    }
    return $normalized;
}

// Handle metadata sheet updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sheet') {
    $update = $pdo->prepare('
        UPDATE artworks
        SET final_title = :final_title,
            subtitle = :subtitle,
            medium = :medium,
            artwork_year = :artwork_year,
            series = :series,
            updated_at = :updated_at
        WHERE id = :id
        AND user_id = :user_id
    ');
    $update->execute([
        'final_title' => trim((string)($_POST['final_title'] ?? '')),
        'subtitle' => trim((string)($_POST['subtitle'] ?? '')),
        'medium' => trim((string)($_POST['medium'] ?? '')),
        'artwork_year' => trim((string)($_POST['artwork_year'] ?? '')),
        'series' => trim((string)($_POST['series'] ?? '')),
        'updated_at' => date('c'),
        'id' => $id,
        'user_id' => (int)$user['id'],
    ]);

    header('Location: artwork.php?id=' . $id . '&saved=1');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$stmt->execute(['id' => $id, 'user_id' => (int)$user['id']]);
$artwork = $stmt->fetch();

if (!is_array($artwork)) {
    http_response_code(404);
    die('Artwork not found.');
}

$rootFile = basename((string)($artwork['root_file'] ?? ''));
$rootPath = $rootFile ? RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile : '';
$rootBase = $rootFile ? pathinfo($rootFile, PATHINFO_FILENAME) : '';
$meta = $rootBase ? read_json_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.meta.json') : [];
$analysis = $rootBase ? read_json_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json') : [];

$analysisStmt = $pdo->prepare('SELECT * FROM artwork_analysis WHERE artwork_id = :artwork_id ORDER BY id DESC LIMIT 1');
$analysisStmt->execute(['artwork_id' => $id]);
$dbAnalysis = $analysisStmt->fetch();

if (!$analysis && is_array($dbAnalysis)) {
    $analysisData = json_decode((string)$dbAnalysis['analysis_json'], true);
    if (is_array($analysisData)) {
        $analysis = array_key_exists('suggested_titles', $analysisData) || array_key_exists('contextual_proposals', $analysisData)
            ? $analysisData
            : ['artwork_profile' => $analysisData];
    }
}

$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
if (!$profile && is_array($analysis['artwork_analysis'] ?? null)) {
    $profile = $analysis['artwork_analysis'];
}
$rootContextualProposals = is_array($analysis['contextual_proposals'] ?? null) ? $analysis['contextual_proposals'] : [];
$legacyRecommendedContexts = is_array($analysis['recommended_contexts'] ?? null) ? $analysis['recommended_contexts'] : [];

$dbContexts = [];
try {
    $contextStmt = $pdo->prepare('SELECT * FROM mockup_contexts WHERE artwork_id = :artwork_id ORDER BY id ASC');
    $contextStmt->execute(['artwork_id' => $id]);
    $dbContexts = $contextStmt->fetchAll();
} catch (Throwable $e) {
    $dbContexts = [];
}

$contexts = normalize_artwork_contexts($rootContextualProposals ?: $legacyRecommendedContexts, $dbContexts);

if (empty($contexts) && !empty($dbContexts)) {
    foreach ($dbContexts as $contextRow) {
        $contextJson = json_decode((string)$contextRow['context_json'], true);
        $contextJson = is_array($contextJson) ? $contextJson : [];
        $contexts[] = [
            'id' => (string)$contextRow['id'],
            'name' => $contextRow['context_name'],
            'purpose' => $contextJson['context_role'] ?? $contextJson['purpose'] ?? '',
            'why' => $contextJson['curatorial_reason'] ?? $contextJson['why'] ?? $contextRow['why'] ?? '',
            'camera_group' => $contextJson['camera_view'] ?? $contextJson['camera_group'] ?? '',
            'time_of_day' => $contextJson['time_of_day'] ?? '',
            'prompt' => $contextRow['prompt'],
        ];
    }
}

$hasValidNewSchema = is_array($analysis['suggested_titles'] ?? null)
    && is_array($analysis['contextual_proposals'] ?? null)
    && count(array_filter($analysis['suggested_titles'], static function ($titleOption): bool {
        return is_array($titleOption)
            && trim((string)($titleOption['title'] ?? '')) !== ''
            && trim((string)($titleOption['subtitle'] ?? '')) !== ''
            && trim((string)($titleOption['description'] ?? '')) !== '';
    })) === count((array)$analysis['suggested_titles']);
$analysisNeedsRefresh = !$hasValidNewSchema && !empty($contexts);

$mockupStmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id = :user_id AND artwork_file = :artwork_file ORDER BY created_at DESC');
$mockupStmt->execute(['user_id' => (int)$user['id'], 'artwork_file' => $rootFile]);
$mockups = $mockupStmt->fetchAll();

$dbQueueJobs = MockupBatchQueue::rowsForArtwork($id);

$measurement = $meta['measurements'] ?? [];
$unit = (string)($measurement['unit'] ?? $artwork['unit'] ?? 'cm');
$width = $measurement['width'] ?? $artwork['width'] ?? '';
$height = $measurement['height'] ?? $artwork['height'] ?? '';
$depth = $measurement['depth'] ?? $artwork['depth'] ?? '';
$sizeText = trim((string)$width) !== '' && trim((string)$height) !== ''
    ? trim((string)$width . ' × ' . (string)$height . ($depth !== '' && $depth !== null ? ' × ' . (string)$depth : '') . ' ' . $unit)
    : 'No dimensions specified';

$artistProfile = ArtistProfile::findForUser((int)$user['id']);
$package = build_artwork_package_v2($artwork, $analysis, $artistProfile);
$storedTitle = trim((string)($artwork['final_title'] ?? ''));
$storedSubtitle = trim((string)($artwork['subtitle'] ?? ''));
$selectedTitle = ($storedTitle !== '' && !looks_spanish($storedTitle)) ? $storedTitle : $package['titles'][0];
$selectedSubtitle = ($storedSubtitle !== '' && !looks_spanish($storedSubtitle)) ? $storedSubtitle : $package['suggested_subtitle'];
$selectedPublicationDescription = $package['premium_descriptions'][$selectedTitle] ?? trim($package['description']);
$curatorialReading = $package['curatorial_reading'];

$copyIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
$downloadIconSvg = '<svg viewBox="0 0 24 24" width="14" height="14" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';

$imagePublic = media_url($rootFile);
$jsonPublic = $rootBase ? $rootBase . '.analysis.json' : '';
$reportBackUrl = 'artwork.php?id=' . $id;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Artwork Details - The Artwork Curator</title>
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
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .button-link:hover {
            background: var(--gal-accent-hover);
            border-color: var(--gal-accent-hover);
        }

        .button-link.secondary {
            background: transparent;
            color: var(--gal-accent) !important;
            border-color: var(--gal-border);
        }

        .button-link.secondary:hover {
            background: var(--gal-surface-soft);
            color: var(--gal-accent-hover) !important;
        }

        .nav a {
            color: var(--gal-muted) !important;
            border-bottom: 1px solid var(--gal-border);
        }

        .curatorial-layout {
            display: grid;
            grid-template-columns: minmax(280px, 360px) 1fr;
            gap: 32px;
            align-items: start;
        }

        .curatorial-sidebar {
            position: sticky;
            top: 30px;
            max-height: calc(100vh - 60px);
            overflow-y: auto;
            padding-right: 4px;
        }

        .artwork-box {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 16px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
        }

        .artwork-box img {
            width: 100%;
            height: auto;
            display: block;
            border-radius: 2px;
            background: var(--gal-surface-soft);
        }

        .artwork-dimensions {
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid var(--gal-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }

        .artwork-dimensions span {
            color: var(--gal-muted);
        }

        .artwork-dimensions strong {
            font-weight: 600;
        }

        .profile {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 24px;
            margin-top: 20px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
        }

        .profile h2 {
            font-family: var(--font-serif);
            font-size: 20px;
            margin: 0 0 12px;
            font-weight: 500;
            letter-spacing: 0.02em;
        }

        .curatorial-read-line {
            font-family: var(--font-serif);
            font-size: 16px;
            line-height: 1.5;
            color: var(--gal-ink);
            font-style: italic;
        }

        .details-panel {
            margin-top: 12px;
            border: 1px solid var(--gal-border);
            background: var(--gal-surface);
            border-radius: var(--gal-radius);
            padding: 14px;
            font-size: 12px;
            box-shadow: var(--gal-shadow);
        }

        .details-panel summary {
            font-weight: 600;
            cursor: pointer;
            color: var(--gal-ink);
            user-select: none;
        }

        .pin-field {
            padding: 8px 10px;
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            margin-bottom: 8px;
        }

        .pin-field label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--gal-muted);
            margin-bottom: 2px;
            display: block;
        }

        .pin-field span {
            font-size: 12px;
            line-height: 1.4;
            color: var(--gal-ink);
            word-break: break-all;
        }

        .contexts-area {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .publishing-panel {
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 20px;
        }

        .publishing-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .publishing-card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 14px;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .publishing-card.selected {
            border-color: var(--gal-accent);
            box-shadow: 0 0 0 2px var(--gal-accent-light);
        }

        .publishing-label {
            font-size: 11px;
            text-transform: uppercase;
            color: var(--gal-muted);
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .field-item {
            display: flex;
            flex-direction: column;
        }

        .field-item strong {
            font-size: 10px;
            text-transform: uppercase;
            color: var(--gal-muted);
            font-weight: 700;
            letter-spacing: 0.05em;
        }

        .field-item p {
            font-size: 13px;
            line-height: 1.5;
            color: var(--gal-ink);
            margin: 0;
        }

        .field-item h3 {
            font-size: 16px;
            font-weight: 600;
            color: var(--gal-ink);
            line-height: 1.4;
            margin: 0;
        }

        .field-item .subtitle-val {
            font-size: 13px;
            color: var(--gal-accent);
            font-family: var(--font-serif);
            line-height: 1.4;
        }

        .contexts {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 24px;
        }

        .card {
            background: var(--gal-surface);
            border: 1px solid var(--gal-border);
            padding: 24px;
            border-radius: var(--gal-radius);
            box-shadow: var(--gal-shadow);
            display: flex;
            flex-direction: column;
            position: relative;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: var(--gal-shadow-hover);
        }

        .card.generated {
            border-top: 3px solid var(--gal-accent);
        }

        .card .number {
            font-size: 10px;
            color: var(--gal-muted);
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.08em;
            margin-bottom: 4px;
        }

        .card h3 {
            font-family: var(--font-serif);
            font-size: 20px;
            margin: 0 0 4px;
            font-weight: 500;
            color: var(--gal-ink);
        }

        .card .purpose {
            font-size: 10px;
            background: var(--gal-surface-soft);
            color: var(--gal-muted);
            padding: 3px 8px;
            border-radius: 12px;
            align-self: flex-start;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.04em;
            margin-bottom: 16px;
        }

        .inline-result {
            background: var(--gal-surface-soft);
            border: 1px dashed var(--gal-border);
            border-radius: var(--gal-radius);
            aspect-ratio: 4/3;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
            margin-bottom: 12px;
        }

        .inline-thumb {
            width: 100%;
            height: 100%;
            display: block;
        }

        .inline-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .card.auto-pending .inline-loader {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .spinner {
            width: 28px;
            height: 28px;
            border: 2px solid var(--gal-border);
            border-top-color: var(--gal-accent);
            border-radius: 50%;
            animation: spin 0.85s linear infinite;
        }

        .inline-status {
            font-size: 11px;
            color: var(--gal-muted);
            font-weight: 500;
        }

        .card-details-toggle {
            margin-top: 14px;
            border-top: 1px solid var(--gal-border);
            padding-top: 8px;
        }

        .card-details-summary {
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
            list-style: none;
            user-select: none;
        }

        .card-details-summary::-webkit-details-marker {
            display: none;
        }

        .card-details-summary .chevron-icon {
            width: 14px;
            height: 14px;
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            color: var(--gal-muted);
        }

        .card-details-toggle[open] .card-details-summary .chevron-icon {
            transform: rotate(180deg);
            color: var(--gal-accent);
        }

        .card-details-content {
            animation: slideDown 0.3s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            padding-top: 8px;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .customizer-options {
            display: grid;
            grid-template-columns: 1fr;
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
        }

        .icon-btn {
            flex: 1;
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
            margin: 0;
            width: auto;
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
        }

        .icon-btn:hover {
            color: var(--gal-ink);
            background: var(--gal-surface-soft);
        }

        .icon-btn.active {
            color: var(--gal-surface) !important;
            background: var(--gal-accent) !important;
        }

        .scale-badge {
            font-size: 10px;
            color: var(--gal-accent);
            background: var(--gal-accent-light);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 2px 6px;
        }

        .scale-badge.neutral {
            color: var(--gal-muted);
            background: var(--gal-surface-soft);
        }

        .scale-badge.positive {
            color: #2b704a;
            background: #e8f5e9;
        }

        .scale-badge.negative {
            color: #a83232;
            background: #ffebee;
        }

        .admin-mockup-prompts {
            background: var(--gal-surface-soft);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            padding: 20px;
        }

        .admin-mockup-prompts h3 {
            margin: 0 0 10px 0;
            font-family: var(--font-serif);
            font-size: 18px;
        }

        .admin-mockup-prompt {
            border: 1px solid var(--gal-border);
            background: var(--gal-surface);
            border-radius: var(--gal-radius);
            margin-bottom: 10px;
            padding: 10px;
        }

        .admin-mockup-prompt summary {
            font-weight: 600;
            cursor: pointer;
            font-size: 12px;
        }

        .admin-mockup-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 10px;
            color: var(--gal-muted);
            margin-top: 8px;
        }

        .admin-mockup-actions {
            margin-top: 8px;
        }

        .admin-mockup-prompt textarea {
            width: 100%;
            height: 80px;
            font-family: monospace;
            font-size: 10px;
            margin-top: 8px;
            box-sizing: border-box;
            background: var(--gal-bg);
            border: 1px solid var(--gal-border);
            border-radius: var(--gal-radius);
            color: var(--gal-muted);
        }

        .back {
            margin-top: 30px;
            font-size: 12px;
            color: var(--gal-muted);
        }

        .back a {
            color: var(--gal-accent);
            text-decoration: none;
        }

        .back a:hover {
            color: var(--gal-accent-hover);
        }

        .notice {
            background: #e8f5e9;
            color: #2b704a;
            border: 1px solid #c8e6c9;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: var(--gal-radius);
            font-size: 13px;
        }

        .notice.error {
            background: #ffebee;
            color: #c62828;
            border-color: #ffcdd2;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>

<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Artwork Details & Curation: Review suggest titles, generate visual mockups, and control technical metadata.
        </div>

        <div class="workspace">
            <div class="wrap">

                <div class="curatorial-layout">
                    <!-- Left Sidebar Column: Root Artwork image and form metrics -->
                    <aside class="curatorial-sidebar">
                        <div class="artwork-box">
                            <?php if ($rootFile && is_file($rootPath)): ?>
                                <a href="<?= h(media_url($rootFile)) ?>" target="_blank" title="Click to open full size">
                                    <img src="<?= h(media_url($rootFile)) ?>" alt="<?= h($package['root_alt']) ?>">
                                </a>
                            <?php else: ?>
                                <div class="empty-state" style="padding: 40px; text-align: center; color: var(--gal-muted);">No root image.</div>
                            <?php endif; ?>
                            <div class="artwork-dimensions">
                                <span>Physical dimensions</span>
                                <strong><?= h($sizeText) ?></strong>
                            </div>
                        </div>

                        <!-- Slug Customizer -->
                        <div style="background: var(--gal-surface); border: 1px solid var(--gal-border); padding: 16px; border-radius: var(--gal-radius); box-shadow: var(--gal-shadow); margin-top: 20px;">
                            <label style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--gal-ink); letter-spacing: 0.05em; display: block; margin-bottom: 6px;">Slug / Filename Customizer</label>
                            <input type="text" id="seo_slug_input" class="form-control" value="<?= h($package['seo_slug']) ?>" style="width: 100%; box-sizing: border-box; padding: 8px 10px; font-size: 13px; font-family: monospace; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); background: var(--gal-bg); color: var(--gal-ink);">
                            <small style="margin: 6px 0 0 0; color: var(--gal-muted); font-size: 11px; line-height: 1.35; display: block;">Modifies all download file names dynamically.</small>
                        </div>

                        <!-- Root Image Caption & Alt -->
                        <details class="details-panel" style="margin-top: 20px;" open>
                            <summary>Root Caption & Alt</summary>
                            <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 10px;">
                                <div>
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Caption</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span style="font-size: 12px; line-height: 1.4; color: var(--gal-ink);"><?= h($package['root_caption']) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_caption']) ?>" aria-label="Copy Caption" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                                <div style="border-top: 1px dashed var(--gal-border); padding-top: 8px;">
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Alt Text</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span style="font-size: 12px; line-height: 1.4; color: var(--gal-ink);"><?= h($package['root_alt']) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['root_alt']) ?>" aria-label="Copy Alt Text" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                                <div style="border-top: 1px dashed var(--gal-border); padding-top: 8px;">
                                    <label style="font-size: 9px; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 2px; font-weight: 700; letter-spacing: 0.05em;">Suggested Filename</label>
                                    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                        <span id="suggested_filename_display" style="font-family: monospace; font-size: 11px; color: var(--gal-ink); word-break: break-all;"><?= h($package['file_names'][0]) ?></span>
                                        <button class="copy-button secondary" type="button" data-copy="<?= h($package['file_names'][0]) ?>" aria-label="Copy Filename" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                    </div>
                                </div>
                            </div>
                        </details>

                        <!-- Lectura Curatorial -->
                        <details class="details-panel" style="margin-top: 20px;" open>
                            <summary>Lectura Curatorial</summary>
                            <div style="margin-top: 10px; line-height: 1.5; font-size: 13px; color: var(--gal-ink);">
                                <p style="margin: 0; font-style: italic; font-family: var(--font-serif); font-size: 14px;">"<?= h($curatorialReading) ?>"</p>
                                <div style="margin-top: 8px; text-align: right;">
                                    <button class="copy-button secondary" type="button" data-copy="<?= h($curatorialReading) ?>" aria-label="Copy Curatorial Reading" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                </div>
                            </div>
                        </details>

                        <!-- Sheet Metadata Edit Form -->
                        <details class="details-panel" style="margin-top: 20px;" open>
                            <summary>Sheet Metadata</summary>
                            <form method="post" style="margin-top: 12px; display: flex; flex-direction: column; gap: 10px;" class="form">
                                <input type="hidden" name="action" value="save_sheet">
                                <div>
                                    <label style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 4px;">Artwork Title</label>
                                    <input type="text" name="final_title" value="<?= h($selectedTitle) ?>" style="width: 100%; padding: 8px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); font-size: 13px;">
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 4px;">Suggested Subtitle</label>
                                    <input type="text" name="subtitle" value="<?= h($selectedSubtitle) ?>" style="width: 100%; padding: 8px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); font-size: 13px;">
                                </div>
                                <div>
                                    <label style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 4px;">Medium / Technique</label>
                                    <input type="text" name="medium" value="<?= h($artwork['medium'] ?? '') ?>" placeholder="Acrylic on canvas" style="width: 100%; padding: 8px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); font-size: 13px;">
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                                    <div>
                                        <label style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 4px;">Year</label>
                                        <input type="text" name="artwork_year" value="<?= h($artwork['artwork_year'] ?? '') ?>" placeholder="2026" style="width: 100%; padding: 8px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); font-size: 13px;">
                                    </div>
                                    <div>
                                        <label style="font-size: 10px; font-weight: 600; text-transform: uppercase; color: var(--gal-muted); display: block; margin-bottom: 4px;">Series</label>
                                        <input type="text" name="series" value="<?= h($artwork['series'] ?? '') ?>" placeholder="Series name" style="width: 100%; padding: 8px; border: 1px solid var(--gal-border); border-radius: var(--gal-radius); font-size: 13px;">
                                    </div>
                                </div>
                                <button type="submit" style="background: var(--gal-accent); color: white; border: none; padding: 10px; border-radius: var(--gal-radius); cursor: pointer; font-size: 12px; text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em; margin-top: 10px;">Save Artwork Sheet</button>
                            </form>
                        </details>
                    </aside>

                    <!-- Right Main Curation Area -->
                    <div class="contexts-area">
                        <!-- Top Header with titles and recalculation options -->
                        <div class="workspace-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <div>
                                <h1 style="font-family: var(--font-serif); font-size: 32px; font-weight: 500; margin: 0; line-height: 1.2;">Curation & Metadata Panel</h1>
                                <p style="font-family: var(--font-serif); font-size: 18px; color: var(--gal-accent); margin: 4px 0 0 0; font-style: italic;"><?= h($selectedSubtitle) ?></p>
                            </div>
                            <div class="topbar-actions" style="display: flex; gap: 10px;">
                                <?php if ($rootFile): ?>
                                    <a class="button-link secondary" href="analyze_wait.php?image=<?= rawurlencode($rootFile) ?>">Recalculate Analysis</a>
                                    <a class="button-link" href="<?= h(media_url($rootFile, true)) ?>">Download Root</a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (isset($_GET['saved'])): ?>
                            <div class="notice">Artwork details saved successfully.</div>
                        <?php endif; ?>

                        <?php if ($analysisNeedsRefresh): ?>
                            <div class="notice error">
                                This analysis schema does not match the latest parameters. Click <strong>Recalculate Analysis</strong> to refresh.
                            </div>
                        <?php endif; ?>

                        <!-- (1) Top: 3 Titles Options Cards -->
                        <section class="publishing-panel" aria-label="Publishing metadata options">
                            <h2 style="font-family: var(--font-serif); font-size: 20px; font-weight: 500; margin: 0 0 16px 0;">Suggested Titles & Premium Descriptions</h2>
                            <div class="publishing-grid">
                                <?php foreach ($package['titles'] as $idx => $t): ?>
                                    <?php
                                        $sub = $package['title_subtitles'][$t] ?? '';
                                        $desc = $package['premium_descriptions'][$t] ?? '';
                                        $isSelected = ($t === $selectedTitle);
                                    ?>
                                    <article class="publishing-card <?= $isSelected ? 'selected' : '' ?>">
                                        <div style="display: flex; justify-content: space-between; align-items: center;">
                                            <div class="publishing-label">Option <?= h((string)($idx + 1)) ?></div>
                                            <?php if ($isSelected): ?>
                                                <span style="font-size: 9px; font-weight: 700; text-transform: uppercase; color: var(--gal-accent); background: var(--gal-accent-light); padding: 2px 6px; border-radius: 4px; border: 1px solid var(--gal-border);">Selected</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="field-item">
                                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                                <strong>Title</strong>
                                                <button class="copy-button secondary" type="button" data-copy="<?= h($t) ?>" aria-label="Copy Title" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                            </div>
                                            <h3><?= h($t) ?></h3>
                                        </div>

                                        <?php if ($sub !== ''): ?>
                                            <div class="field-item">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2px;">
                                                    <strong>Subtitle</strong>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($sub) ?>" aria-label="Copy Subtitle" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                                <div class="subtitle-val"><?= h($sub) ?></div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($desc !== ''): ?>
                                            <div class="field-item" style="margin-top: auto; padding-top: 10px; border-top: 1px dashed var(--gal-border);">
                                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                                                    <strong>Premium Description</strong>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($desc) ?>" aria-label="Copy Description" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-muted); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                                <p><?= h($desc) ?></p>
                                            </div>
                                        <?php endif; ?>

                                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--gal-border);">
                                            <form method="post" style="margin: 0; width: 100%;">
                                                <input type="hidden" name="action" value="save_sheet">
                                                <input type="hidden" name="final_title" value="<?= h($t) ?>">
                                                <input type="hidden" name="subtitle" value="<?= h($sub) ?>">
                                                <input type="hidden" name="medium" value="<?= h($artwork['medium'] ?? '') ?>">
                                                <input type="hidden" name="artwork_year" value="<?= h($artwork['artwork_year'] ?? '') ?>">
                                                <input type="hidden" name="series" value="<?= h($artwork['series'] ?? '') ?>">
                                                <button type="submit" class="button" style="font-size: 11px; padding: 6px 10px; width: 100%; border-radius: var(--gal-radius); background: <?= $isSelected ? 'var(--gal-accent)' : 'var(--gal-surface-soft)' ?>; color: <?= $isSelected ? '#fff' : 'var(--gal-ink)' ?>; border: 1px solid var(--gal-border); cursor: pointer; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em;"><?= $isSelected ? 'Selected' : 'Select Title' ?></button>
                                            </form>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>

                        <!-- (2) Center: Mockup Proposals -->
                        <section style="display: flex; justify-content: space-between; align-items: center; margin-bottom: -10px;">
                            <h2 style="font-family: var(--font-serif); font-size: 24px; font-weight: 500; margin: 0;"><?= h(count($contexts)) ?> Mockup Proposals</h2>
                        </section>

                        <?php
                        // Pre-calculate generated vs pending contexts
                        $generatedContexts = [];
                        $pendingContexts = [];
                        
                        // Extract all active context IDs
                        $activeContextIds = [];
                        foreach ($contexts as $ctx) {
                            if (isset($ctx['id'])) {
                                $activeContextIds[] = (string)$ctx['id'];
                            }
                        }
                        
                        // Extract unique orphaned context IDs from mockups
                        $orphanContextIds = [];
                        foreach ($mockups as $m) {
                            $mCtxId = (string)$m['context_id'];
                            if (!in_array($mCtxId, $activeContextIds, true) && !in_array($mCtxId, $orphanContextIds, true)) {
                                $orphanContextIds[] = $mCtxId;
                            }
                        }
                        sort($orphanContextIds, SORT_NUMERIC);

                        foreach ($contexts as $i => $ctx) {
                            $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                            $existingMockup = null;
                            
                            // 1. Direct match by active context ID or sequential index
                            foreach ($mockups as $m) {
                                if ((string)$m['context_id'] === (string)$ctxId || (string)$m['context_id'] === (string)($i + 1)) {
                                    $existingMockup = $m;
                                    break;
                                }
                            }
                            
                            // 2. Fallback to orphan context ID matching by order/index
                            if (!$existingMockup && isset($orphanContextIds[$i])) {
                                $targetOrphanId = $orphanContextIds[$i];
                                foreach ($mockups as $m) {
                                    if ((string)$m['context_id'] === $targetOrphanId) {
                                        $existingMockup = $m;
                                        break;
                                    }
                                }
                            }
                            
                            $item = ['i' => $i, 'ctx' => $ctx, 'mockup' => $existingMockup];
                            if ($existingMockup) {
                                $generatedContexts[] = $item;
                            } else {
                                $pendingContexts[] = $item;
                            }
                        }
                        ?>

                        <div class="contexts">
                            <!-- Render Generated Mockups Grid Row -->
                            <?php if (!empty($generatedContexts)): ?>
                                <div style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-bottom: 2px;">
                                    <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.12em; font-weight: 700; color: var(--gal-accent);">Generated Mockups</span>
                                    <div style="flex: 1; height: 1px; background: var(--gal-border);"></div>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($generatedContexts as $entry): ?>
                                <?php
                                    $i = $entry['i'];
                                    $ctx = $entry['ctx'];
                                    $existingMockup = $entry['mockup'];
                                    $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                                    $ctxSlug = slugify($ctx['name']);
                                    $mFile = basename((string)$existingMockup['mockup_file']);
                                    $mUrl = 'media.php?file=' . rawurlencode($mFile);
                                    $mDownloadUrl = $mUrl . '&download=1';
                                    
                                    $mAlt = 'Mockup of the artwork "' . $selectedTitle . '" presented in a ' . strtolower($ctx['name']) . ' environment.';
                                    $mCaption = '"' . $selectedTitle . '" mockup in ' . $ctx['name'] . '.';
                                    $expectedFilename = $package['seo_slug'] . '-mockup-' . $ctxSlug . '.jpg';

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

                                    $defaultCamera = 'front';
                                    $cameraGroup = $ctx['camera_group'] ?? '';
                                    if (str_contains($cameraGroup, 'left')) {
                                        $defaultCamera = '3_4_left';
                                    } elseif (str_contains($cameraGroup, 'right')) {
                                        $defaultCamera = '3_4_right';
                                    }
                                    if (in_array(($selectorState['camera_override'] ?? ''), ['front', '3_4_left', '3_4_right'], true)) {
                                        $defaultCamera = (string)$selectorState['camera_override'];
                                    }

                                    $defaultTime = 'sunny_day';
                                    $timeOfDay = strtolower($ctx['time_of_day'] ?? '');
                                    if (str_contains($timeOfDay, 'cloudy')) {
                                        $defaultTime = 'cloudy_day';
                                    } elseif (str_contains($timeOfDay, 'afternoon')) {
                                        $defaultTime = 'afternoon';
                                    } elseif (str_contains($timeOfDay, 'night')) {
                                        $defaultTime = 'night';
                                    }
                                    if (in_array(($selectorState['time_override'] ?? ''), ['sunny_day', 'cloudy_day', 'afternoon', 'night'], true)) {
                                        $defaultTime = (string)$selectorState['time_override'];
                                    }

                                    $defaultHuman = 'none';
                                    if (in_array(($selectorState['human_override'] ?? ''), ['none', 'female_155', 'male_180'], true)) {
                                        $defaultHuman = (string)$selectorState['human_override'];
                                    }

                                    $defaultDistance = $selectorState['distance_override'] ?? 'medium';
                                    $defaultSizeOverride = (int)($selectorState['size_override'] ?? 0);
                                ?>
                                <article class="card generated mockup-card-container" id="context-<?= h($ctxId) ?>" data-context-name="<?= h($ctx['name']) ?>" data-context-id="<?= h($ctxId) ?>">
                                    <div class="number">Proposal <?= $i + 1 ?></div>
                                    <h3><?= h($ctx['name']) ?></h3>
                                    <span class="purpose"><?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?></span>

                                    <!-- Mockup Image Preview -->
                                    <div class="inline-result inline-result-box">
                                        <a class="inline-thumb" href="<?= h($mUrl) ?>" target="_blank" title="Click to open full size">
                                            <img src="<?= h($mUrl) ?>" alt="Generated mockup">
                                        </a>
                                    </div>

                                    <!-- Mockup Actions (Always visible for generated) -->
                                    <div class="generated-actions" style="display: flex; flex-direction: column; gap: 8px;">
                                        <div style="display: flex; gap: 6px;">
                                            <a href="<?= h($mDownloadUrl) ?>" class="download-mockup-link button-link" data-base-file="<?= h($mFile) ?>" data-context="<?= h($ctxSlug) ?>" style="flex: 1; font-size: 11px; padding: 8px;">Download</a>
                                            <a href="social_video.php?id=<?= (int)$id ?>&mockup=<?= rawurlencode($mFile) ?>" class="button-link secondary" style="flex: 1; font-size: 11px; padding: 8px;">Generate Video</a>
                                        </div>
                                        <button type="button" class="btn-delete-mockup button secondary danger" data-mockup-id="<?= h((string)$existingMockup['id']) ?>" style="background: transparent; border: 1px solid var(--gal-border); color: var(--gal-danger); padding: 8px; border-radius: var(--gal-radius); font-size: 11px; font-weight: 600; text-transform: uppercase; cursor: pointer; width: 100%;">Delete Mockup</button>
                                    </div>

                                    <div class="ungenerated-form" style="display: none;">
                                        <form class="inline-mockup-form" action="generate_mockup.php" method="post" style="margin: 0; width: 100%;">
                                            <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                            <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                                            <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                                            <input type="hidden" name="prompt" value="<?= h($ctx['prompt'] ?? '') ?>">
                                            <input type="hidden" name="ajax" value="1">
                                            <button type="submit" class="button-link" style="width: 100%; padding: 8px; font-size: 11px; border: none; cursor: pointer;">Generate Mockup</button>
                                        </form>
                                    </div>

                                    <!-- Dropdown for rationale and sliders -->
                                    <details class="card-details-toggle">
                                        <summary class="card-details-summary">
                                            <span> Curation & Customizer</span>
                                            <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <polyline points="6 9 12 15 18 9"></polyline>
                                            </svg>
                                        </summary>
                                        <div class="card-details-content">
                                            <!-- Curatorial rationale -->
                                            <?php if (!empty($ctx['why'])): ?>
                                                <p style="font-size: 12px; color: var(--gal-ink); margin: 0 0 14px 0; font-style: italic; line-height: 1.45; border-left: 2px solid var(--gal-accent); padding-left: 8px;">
                                                    <strong>Curatorial rationale:</strong> <?= h($ctx['why']) ?>
                                                </p>
                                            <?php endif; ?>

                                            <!-- Read-only Filename, Alt text and Caption displays -->
                                            <div class="pin-field">
                                                <label>Expected Filename</label>
                                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                    <span class="seo-mockup-filename" data-context-slug="<?= h($ctxSlug) ?>" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($expectedFilename) ?></span>
                                                    <button class="copy-button secondary copy-mockup-filename-btn" type="button" data-copy="<?= h($expectedFilename) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <div class="pin-field">
                                                <label>Alt Text</label>
                                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                    <span class="mockup-alt-text"><?= h($mAlt) ?></span>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($mAlt) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <div class="pin-field">
                                                <label>Caption</label>
                                                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                    <span class="mockup-caption-text"><?= h($mCaption) ?></span>
                                                    <button class="copy-button secondary" type="button" data-copy="<?= h($mCaption) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                </div>
                                            </div>

                                            <!-- Customizer controls for regeneration -->
                                            <form class="inline-mockup-form" action="generate_mockup.php" method="post" style="margin-top: 14px;">
                                                <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                                <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                                                <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                                                <input type="hidden" name="prompt" value="<?= h($ctx['prompt'] ?? '') ?>">
                                                <input type="hidden" name="ajax" value="1">
                                                <input type="hidden" name="current_mockup_file" value="<?= h($mFile) ?>">

                                                <div class="customizer-options">
                                                    <div class="opt-group">
                                                        <label>Camera Angle</label>
                                                        <div class="selector-icons camera-selector">
                                                            <button type="button" class="icon-btn <?= $defaultCamera === 'front' ? 'active' : '' ?>" data-value="front" title="Frontal">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="1.5"></rect><line x1="12" y1="6" x2="12" y2="18" stroke-dasharray="2 2"></line></svg>
                                                                <span>Frontal</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultCamera === '3_4_left' ? 'active' : '' ?>" data-value="3_4_left" title="3/4 izquierdo">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 8l-10-3v14l10-3z"></path></svg>
                                                                <span>3/4 Izq</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultCamera === '3_4_right' ? 'active' : '' ?>" data-value="3_4_right" title="3/4 derecho">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8l10-3v14l-10-3z"></path></svg>
                                                                <span>3/4 Der</span>
                                                            </button>
                                                        </div>
                                                        <input type="hidden" name="camera_override" value="<?= $defaultCamera ?>">
                                                    </div>

                                                    <div class="opt-group">
                                                        <label>Atmosphere / Lighting</label>
                                                        <div class="selector-icons time-selector">
                                                            <button type="button" class="icon-btn <?= $defaultTime === 'sunny_day' ? 'active' : '' ?>" data-value="sunny_day" title="Sunny day">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle></svg>
                                                                <span>Sunny</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultTime === 'cloudy_day' ? 'active' : '' ?>" data-value="cloudy_day" title="Cloudy day">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>
                                                                <span>Cloudy</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultTime === 'afternoon' ? 'active' : '' ?>" data-value="afternoon" title="Afternoon">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 18a5 5 0 0 0-10 0"></path></svg>
                                                                <span>Golden</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultTime === 'night' ? 'active' : '' ?>" data-value="night" title="Night">
                                                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                                                                <span>Night</span>
                                                            </button>
                                                        </div>
                                                        <input type="hidden" name="time_override" value="<?= $defaultTime ?>">
                                                    </div>

                                                    <div class="opt-group">
                                                        <label>Figura Humana</label>
                                                        <div class="selector-icons human-selector">
                                                            <button type="button" class="icon-btn <?= $defaultHuman === 'none' ? 'active' : '' ?>" data-value="none" title="Sin figura">
                                                                <span>None</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultHuman === 'female_155' ? 'active' : '' ?>" data-value="female_155" title="Femenina 1.55m">
                                                                <span>Woman</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultHuman === 'male_180' ? 'active' : '' ?>" data-value="male_180" title="Masculina 1.80m">
                                                                <span>Man</span>
                                                            </button>
                                                        </div>
                                                        <input type="hidden" name="human_override" value="<?= $defaultHuman ?>">
                                                    </div>

                                                    <div class="opt-group">
                                                        <label>Camera Distance</label>
                                                        <div class="selector-icons distance-selector">
                                                            <button type="button" class="icon-btn <?= $defaultDistance === 'close' ? 'active' : '' ?>" data-value="close" title="Close Up">
                                                                <span>Zoom In</span>
                                                            </button>
                                                            <button type="button" class="icon-btn <?= $defaultDistance === 'medium' ? 'active' : '' ?>" data-value="medium" title="Medium">
                                                                <span>Zoom Out</span>
                                                            </button>
                                                        </div>
                                                        <input type="hidden" name="distance_override" value="<?= h($defaultDistance) ?>">
                                                    </div>

                                                    <div class="opt-group scale-customizer-container">
                                                        <label>Artwork Size correction</label>
                                                        <div style="display: flex; align-items: center; gap: 8px;">
                                                            <button type="button" class="size-adjust-btn size-minus" style="padding: 4px 8px; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); font-weight: bold;">-</button>
                                                            <span class="scale-badge neutral" style="font-family: monospace; font-size: 11px; text-align: center; flex: 1; display: inline-block;">No correction (0%)</span>
                                                            <button type="button" class="size-adjust-btn size-plus" style="padding: 4px 8px; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); font-weight: bold;">+</button>
                                                        </div>
                                                        <input type="hidden" name="size_override" value="<?= h((string)$defaultSizeOverride) ?>" class="premium-size-override">
                                                    </div>
                                                </div>

                                                <button type="submit" class="button-link" style="width: 100%; border: none; cursor: pointer; padding: 10px; margin-top: 10px;">Regenerar Mockup</button>
                                            </form>
                                        </div>
                                    </details>
                                </article>
                            <?php endforeach; ?>

                            <!-- Render Pending Mockup Proposals Grid Row -->
                            <?php if (!empty($pendingContexts)): ?>
                                <?php foreach (array_chunk($pendingContexts, 3) as $batchIndex => $pendingBatch): ?>
                                    <?php
                                    $batchContextIds = array_values(array_map(fn($item) => (string)($item['ctx']['id'] ?? ('ctx_' . ($item['i'] + 1))), $pendingBatch));
                                    $batchHasActiveJob = false;
                                    foreach ($pendingBatch as $item) {
                                        $pendingCtxId = (string)($item['ctx']['id'] ?? ('ctx_' . ($item['i'] + 1)));
                                        foreach ($dbQueueJobs as $job) {
                                            if ((string)$job['context_id'] === $pendingCtxId && in_array((string)$job['status'], ['queued', 'processing'], true)) {
                                                $batchHasActiveJob = true;
                                                break 2;
                                            }
                                        }
                                    }
                                    ?>
                                    <div style="grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; margin-top: 16px; margin-bottom: 2px;">
                                        <button type="button" class="batch-generate-button" data-context-ids="<?= h(implode(',', $batchContextIds)) ?>" <?= $batchHasActiveJob ? 'disabled' : '' ?> style="padding: 8px 12px; font-size: 10px; border: 1px solid var(--gal-accent); background: var(--gal-accent); color: white; border-radius: var(--gal-radius); text-transform: uppercase; font-weight: 700; cursor: pointer;">
                                            <?= $batchHasActiveJob ? 'Generating...' : 'Generate Batch' ?>
                                        </button>
                                        <span style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.12em; font-weight: 700; color: var(--gal-muted);">Pending proposals · Batch <?= h($batchIndex + 1) ?></span>
                                        <div style="flex: 1; height: 1px; background: var(--gal-border);"></div>
                                    </div>

                                    <?php foreach ($pendingBatch as $entry): ?>
                                        <?php
                                            $i = $entry['i'];
                                            $ctx = $entry['ctx'];
                                            $ctxId = $ctx['id'] ?? ('ctx_' . ($i + 1));
                                            $ctxSlug = slugify($ctx['name']);
                                            
                                            $mAlt = 'Mockup of the artwork "' . $selectedTitle . '" presented in a ' . strtolower($ctx['name']) . ' environment.';
                                            $mCaption = '"' . $selectedTitle . '" mockup in ' . $ctx['name'] . '.';
                                            $expectedFilename = $package['seo_slug'] . '-mockup-' . $ctxSlug . '.jpg';

                                            $queueJob = null;
                                            foreach ($dbQueueJobs as $job) {
                                                if ((string)$job['context_id'] === (string)$ctxId) {
                                                    $queueJob = $job;
                                                    break;
                                                }
                                            }
                                            $queueStatus = $queueJob ? (string)$queueJob['status'] : '';
                                            $isAutoPending = in_array($queueStatus, ['queued', 'processing'], true);

                                            $defaultCamera = 'front';
                                            $cameraGroup = $ctx['camera_group'] ?? '';
                                            if (str_contains($cameraGroup, 'left')) {
                                                $defaultCamera = '3_4_left';
                                            } elseif (str_contains($cameraGroup, 'right')) {
                                                $defaultCamera = '3_4_right';
                                            }
                                            $defaultTime = 'sunny_day';
                                            $timeOfDay = strtolower($ctx['time_of_day'] ?? '');
                                            if (str_contains($timeOfDay, 'cloudy')) {
                                                $defaultTime = 'cloudy_day';
                                            } elseif (str_contains($timeOfDay, 'afternoon')) {
                                                $defaultTime = 'afternoon';
                                            } elseif (str_contains($timeOfDay, 'night')) {
                                                $defaultTime = 'night';
                                            }
                                            $defaultHuman = 'none';
                                            $defaultDistance = 'medium';
                                            $defaultSizeOverride = 0;
                                        ?>
                                        <article class="card mockup-card-container <?= $isAutoPending ? 'auto-pending' : '' ?>" id="context-<?= h($ctxId) ?>" data-context-name="<?= h($ctx['name']) ?>" data-context-id="<?= h($ctxId) ?>" style="opacity: 0.85;">
                                            <div class="number">Proposal <?= $i + 1 ?></div>
                                            <h3><?= h($ctx['name']) ?></h3>
                                            <span class="purpose"><?= h(str_replace('_', ' ', $ctx['purpose'] ?? '')) ?></span>

                                            <!-- Thumbnail Placeholder / Loading status -->
                                            <div class="inline-result inline-result-box">
                                                <?php if ($isAutoPending): ?>
                                                    <div class="inline-loader" data-auto-status="<?= h($queueStatus) ?>">
                                                        <div class="spinner"></div>
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

                                            <!-- Single generate form (Visible for pending) -->
                                            <div class="generated-actions" style="display: none; flex-direction: column; gap: 8px;">
                                                <div style="display: flex; gap: 6px;">
                                                    <a href="#" class="download-mockup-link button-link" data-base-file="" data-context="<?= h($ctxSlug) ?>" style="flex: 1; font-size: 11px; padding: 8px;">Download</a>
                                                    <a href="social_video.php?id=<?= (int)$id ?>&mockup=" class="button-link secondary" style="flex: 1; font-size: 11px; padding: 8px;">Generate Video</a>
                                                </div>
                                                <button type="button" class="btn-delete-mockup button secondary danger" data-mockup-id="" style="background: transparent; border: 1px solid var(--gal-border); color: var(--gal-danger); padding: 8px; border-radius: var(--gal-radius); font-size: 11px; font-weight: 600; text-transform: uppercase; cursor: pointer; width: 100%;">Delete Mockup</button>
                                            </div>

                                            <div class="ungenerated-form" style="<?= $isAutoPending ? 'display: none;' : '' ?>">
                                                <form class="inline-mockup-form" action="generate_mockup.php" method="post" style="margin: 0; width: 100%;">
                                                    <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                                    <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                                                    <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                                                    <input type="hidden" name="prompt" value="<?= h($ctx['prompt'] ?? '') ?>">
                                                    <input type="hidden" name="ajax" value="1">
                                                    <button type="submit" class="button-link" style="width: 100%; padding: 8px; font-size: 11px; border: none; cursor: pointer;">Generate Mockup</button>
                                                </form>
                                            </div>

                                            <!-- Dropdown for rationale and customizer -->
                                            <details class="card-details-toggle">
                                                <summary class="card-details-summary">
                                                    <span> Curation & Customizer</span>
                                                    <svg class="chevron-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <polyline points="6 9 12 15 18 9"></polyline>
                                                    </svg>
                                                </summary>
                                                <div class="card-details-content">
                                                    <?php if (!empty($ctx['why'])): ?>
                                                        <p style="font-size: 12px; color: var(--gal-ink); margin: 0 0 14px 0; font-style: italic; line-height: 1.45; border-left: 2px solid var(--gal-accent); padding-left: 8px;">
                                                            <strong>Curatorial rationale:</strong> <?= h($ctx['why']) ?>
                                                        </p>
                                                    <?php endif; ?>

                                                    <!-- Read-only Filename, Alt text and Caption displays -->
                                                    <div class="pin-field">
                                                        <label>Expected Filename</label>
                                                        <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                                            <span class="seo-mockup-filename" data-context-slug="<?= h($ctxSlug) ?>" style="font-family: monospace; font-size: 11px; font-weight: 500;"><?= h($expectedFilename) ?></span>
                                                            <button class="copy-button secondary copy-mockup-filename-btn" type="button" data-copy="<?= h($expectedFilename) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                        </div>
                                                    </div>

                                                    <div class="pin-field">
                                                        <label>Alt Text</label>
                                                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                            <span class="mockup-alt-text"><?= h($mAlt) ?></span>
                                                            <button class="copy-button secondary" type="button" data-copy="<?= h($mAlt) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                        </div>
                                                    </div>

                                                    <div class="pin-field">
                                                        <label>Caption</label>
                                                        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 8px;">
                                                            <span class="mockup-caption-text"><?= h($mCaption) ?></span>
                                                            <button class="copy-button secondary" type="button" data-copy="<?= h($mCaption) ?>" style="padding: 2px; border: none; background: transparent; cursor: pointer; color: var(--gal-accent); margin: 0; min-height: unset; line-height: 1;"><?= $copyIconSvg ?></button>
                                                        </div>
                                                    </div>

                                                    <form class="inline-mockup-form" action="generate_mockup.php" method="post" style="margin-top: 14px;">
                                                        <input type="hidden" name="image" value="<?= h($rootFile) ?>">
                                                        <input type="hidden" name="json" value="<?= h($jsonPublic) ?>">
                                                        <input type="hidden" name="context_id" value="<?= h($ctxId) ?>">
                                                        <input type="hidden" name="prompt" value="<?= h($ctx['prompt'] ?? '') ?>">
                                                        <input type="hidden" name="ajax" value="1">

                                                        <div class="customizer-options">
                                                            <div class="opt-group">
                                                                <label>Camera Angle</label>
                                                                <div class="selector-icons camera-selector">
                                                                    <button type="button" class="icon-btn <?= $defaultCamera === 'front' ? 'active' : '' ?>" data-value="front" title="Frontal">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="6" width="16" height="12" rx="1.5"></rect><line x1="12" y1="6" x2="12" y2="18" stroke-dasharray="2 2"></line></svg>
                                                                        <span>Frontal</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultCamera === '3_4_left' ? 'active' : '' ?>" data-value="3_4_left" title="3/4 izquierdo">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 8l-10-3v14l10-3z"></path></svg>
                                                                        <span>3/4 Izq</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultCamera === '3_4_right' ? 'active' : '' ?>" data-value="3_4_right" title="3/4 derecho">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8l10-3v14l-10-3z"></path></svg>
                                                                        <span>3/4 Der</span>
                                                                    </button>
                                                                </div>
                                                                <input type="hidden" name="camera_override" value="<?= $defaultCamera ?>">
                                                            </div>

                                                            <div class="opt-group">
                                                                <label>Atmosphere / Lighting</label>
                                                                <div class="selector-icons time-selector">
                                                                    <button type="button" class="icon-btn <?= $defaultTime === 'sunny_day' ? 'active' : '' ?>" data-value="sunny_day" title="Sunny day">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"></circle></svg>
                                                                        <span>Sunny</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultTime === 'cloudy_day' ? 'active' : '' ?>" data-value="cloudy_day" title="Cloudy day">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"></path></svg>
                                                                        <span>Cloudy</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultTime === 'afternoon' ? 'active' : '' ?>" data-value="afternoon" title="Afternoon">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 18a5 5 0 0 0-10 0"></path></svg>
                                                                        <span>Golden</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultTime === 'night' ? 'active' : '' ?>" data-value="night" title="Night">
                                                                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                                                                        <span>Night</span>
                                                                    </button>
                                                                </div>
                                                                <input type="hidden" name="time_override" value="<?= $defaultTime ?>">
                                                            </div>

                                                            <div class="opt-group">
                                                                <label>Figura Humana</label>
                                                                <div class="selector-icons human-selector">
                                                                    <button type="button" class="icon-btn <?= $defaultHuman === 'none' ? 'active' : '' ?>" data-value="none" title="Sin figura">
                                                                        <span>None</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultHuman === 'female_155' ? 'active' : '' ?>" data-value="female_155" title="Femenina 1.55m">
                                                                        <span>Woman</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultHuman === 'male_180' ? 'active' : '' ?>" data-value="male_180" title="Masculina 1.80m">
                                                                        <span>Man</span>
                                                                    </button>
                                                                </div>
                                                                <input type="hidden" name="human_override" value="<?= $defaultHuman ?>">
                                                            </div>

                                                            <div class="opt-group">
                                                                <label>Camera Distance</label>
                                                                <div class="selector-icons distance-selector">
                                                                    <button type="button" class="icon-btn <?= $defaultDistance === 'close' ? 'active' : '' ?>" data-value="close" title="Close Up">
                                                                        <span>Zoom In</span>
                                                                    </button>
                                                                    <button type="button" class="icon-btn <?= $defaultDistance === 'medium' ? 'active' : '' ?>" data-value="medium" title="Medium">
                                                                        <span>Zoom Out</span>
                                                                    </button>
                                                                </div>
                                                                <input type="hidden" name="distance_override" value="<?= h($defaultDistance) ?>">
                                                            </div>

                                                            <div class="opt-group scale-customizer-container">
                                                                <label>Artwork Size correction</label>
                                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                                    <button type="button" class="size-adjust-btn size-minus" style="padding: 4px 8px; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); font-weight: bold;">-</button>
                                                                    <span class="scale-badge neutral" style="font-family: monospace; font-size: 11px; text-align: center; flex: 1; display: inline-block;">No correction (0%)</span>
                                                                    <button type="button" class="size-adjust-btn size-plus" style="padding: 4px 8px; cursor: pointer; border: 1px solid var(--gal-border); background: var(--gal-surface-soft); border-radius: var(--gal-radius); font-weight: bold;">+</button>
                                                                </div>
                                                                <input type="hidden" name="size_override" value="<?= h((string)$defaultSizeOverride) ?>" class="premium-size-override">
                                                            </div>
                                                        </div>

                                                        <button type="submit" class="button-link" style="width: 100%; border: none; cursor: pointer; padding: 10px; margin-top: 10px;">Generate Mockup</button>
                                                    </form>
                                                </div>
                                            </details>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- (4) Folded Mockup Prompts for Admin -->
                        <?php if ($isAdmin && !empty($contexts)): ?>
                            <details class="details-panel" style="margin-top: 10px; background: var(--gal-surface-soft);">
                                <summary style="font-family: var(--font-serif); font-size: 18px; font-weight: 500;">Admin - Mockup Prompts (Folded)</summary>
                                <div style="margin-top: 12px; display: flex; flex-direction: column; gap: 10px;">
                                    <?php foreach ($contexts as $promptIndex => $promptContext): ?>
                                        <?php
                                            $adminPromptId = 'adminMockupPrompt' . $promptIndex;
                                            $adminCtxId = (string)($promptContext['id'] ?? ('ctx_' . ($promptIndex + 1)));
                                            $adminPromptText = (string)($promptContext['prompt'] ?? '');
                                        ?>
                                        <div class="admin-mockup-prompt">
                                            <strong>Proposal <?= h((string)($promptIndex + 1)) ?> - <?= h($promptContext['name']) ?></strong>
                                            <div class="admin-mockup-meta">
                                                <span>Context ID: <?= h($adminCtxId) ?></span>
                                                <span>Camera: <?= h((string)($promptContext['camera_group'] ?? '')) ?></span>
                                                <span>Time: <?= h((string)($promptContext['time_of_day'] ?? '')) ?></span>
                                            </div>
                                            <div style="margin-top: 8px;">
                                                <button type="button" class="button-link secondary admin-copy-mockup-prompt" data-target="<?= h($adminPromptId) ?>" style="font-size: 10px; padding: 4px 8px;">Copy Prompt</button>
                                            </div>
                                            <textarea id="<?= h($adminPromptId) ?>" readonly style="width: 100%; height: 80px; font-family: monospace; font-size: 10px; margin-top: 6px; box-sizing: border-box; background: var(--gal-bg); border: 1px solid var(--gal-border); border-radius: var(--gal-radius); color: var(--gal-muted);"><?= h($adminPromptText) ?></textarea>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>

                        <!-- (5) At the very bottom: View Raw AI Analysis JSON collapsible panel -->
                        <?php
                        $rawAnalysisJson = '';
                        if (is_array($dbAnalysis) && !empty($dbAnalysis['analysis_json'])) {
                            $rawAnalysisJson = (string)$dbAnalysis['analysis_json'];
                        } elseif ($rootBase && is_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json')) {
                            $rawAnalysisJson = (string)file_get_contents(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json');
                        }
                        if ($rawAnalysisJson !== ''):
                            $decodedJson = json_decode($rawAnalysisJson, true);
                            if (is_array($decodedJson)) {
                                $rawAnalysisJson = json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            }
                        ?>
                            <details class="details-panel" style="margin-top: 10px; margin-bottom: 20px;">
                                <summary style="font-family: var(--font-serif); font-size: 18px; font-weight: 500;">View Raw AI Analysis (JSON)</summary>
                                <div style="margin-top: 12px;">
                                    <pre style="background: var(--gal-surface-soft); border: 1px solid var(--gal-border); padding: 12px; border-radius: var(--gal-radius); overflow-x: auto; font-family: monospace; font-size: 11px; margin: 0; max-height: 400px; color: var(--gal-ink);"><code class="json"><?= h($rawAnalysisJson) ?></code></pre>
                                </div>
                            </details>
                        <?php endif; ?>

                        <div class="back">
                            <a href="artwork_new.php">Back to step 1</a>
                            &nbsp;·&nbsp;
                            <a href="dashboard.php">Dashboard</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<script>
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const batchStatusUrl = 'mockup_batch_status.php?image=<?= rawurlencode($rootFile) ?>';
    const batchGenerateUrl = 'generate_mockup_batch.php';
    const batchImage = <?= json_encode($rootFile, JSON_UNESCAPED_SLASHES) ?>;
    const reportBackUrl = <?= json_encode($reportBackUrl, JSON_UNESCAPED_SLASHES) ?>;
    const socialVideoBaseUrl = <?= json_encode('social_video.php?id=' . (int)$id . '&mockup=', JSON_UNESCAPED_SLASHES) ?>;

    const seoInput = document.getElementById('seo_slug_input');
    
    function updateDownloadLinks() {
        if (!seoInput) return;
        const slug = seoInput.value.trim().toLowerCase().replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-');
        
        const rootLink = document.getElementById('download_root_link');
        if (rootLink) {
            const baseFile = rootLink.getAttribute('data-base-file');
            rootLink.href = `media.php?file=${encodeURIComponent(baseFile)}&download=1&name=${encodeURIComponent(slug + '-root-artwork')}`;
        }
        
        document.querySelectorAll('.download-mockup-link').forEach((link) => {
            const baseFile = link.getAttribute('data-base-file');
            const context = link.getAttribute('data-context');
            if (baseFile) {
                link.href = `media.php?file=${encodeURIComponent(baseFile)}&download=1&name=${encodeURIComponent(slug + '-mockup-' + context)}`;
            }
        });

        const filenameDisplay = document.getElementById('suggested_filename_display');
        if (filenameDisplay) {
            filenameDisplay.textContent = slug + '-root-artwork.jpg';
        }

        document.querySelectorAll('.seo-mockup-filename').forEach((span) => {
            const ctxSlug = span.getAttribute('data-context-slug');
            span.textContent = slug + '-mockup-' + ctxSlug + '.jpg';
        });
    }
    
    if (seoInput) {
        seoInput.addEventListener('input', updateDownloadLinks);
        updateDownloadLinks();
    }

    // Copy prompt text in Admin
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

    // Customizer tabs/icons toggler
    document.querySelectorAll('.selector-icons .icon-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const group = btn.closest('.selector-icons');
            const parent = btn.closest('.opt-group');
            const hiddenInput = parent.querySelector('input[type="hidden"]');
            group.querySelectorAll('.icon-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hiddenInput.value = btn.dataset.value;
        });
    });

    // Customizer size controls
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

        updateBadge(hiddenInput.value);

        minusBtn.addEventListener('click', () => {
            let val = parseInt(hiddenInput.value, 10) || 0;
            val = Math.max(-50, val - 5);
            updateBadge(val);
        });

        plusBtn.addEventListener('click', () => {
            let val = parseInt(hiddenInput.value, 10) || 0;
            val = Math.min(50, val + 5);
            updateBadge(val);
        });
    });

    // Copy fields buttons
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
                setTimeout(() => { btn.innerHTML = original; }, 1200);
            }
        });
    });

    // Copy filenames copy buttons (retrieve sibling span text dynamically)
    document.querySelectorAll('.copy-mockup-filename-btn').forEach((button) => {
        button.addEventListener('click', async (e) => {
            e.stopPropagation();
            const span = button.previousElementSibling;
            if (span) {
                const original = button.innerHTML;
                try {
                    await navigator.clipboard.writeText(span.textContent.trim());
                    button.innerHTML = 'Copied';
                    setTimeout(() => button.innerHTML = original, 1200);
                } catch (error) {
                    button.innerHTML = 'Copy failed';
                    setTimeout(() => button.innerHTML = original, 1200);
                }
            }
        });
    });

    // Single mockup AJAX generation queue
    let mockupGenerationQueue = Promise.resolve();
    let pendingManualGenerations = 0;

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
            ? `<button type="button" class="btn-delete-mockup button secondary danger" data-mockup-id="${escapeAttribute(mockupId)}" style="width:100%; padding: 8px;">Delete Mockup</button>`
            : '';

        resultBox.innerHTML = `
            <a class="inline-thumb" href="${escapeAttribute(viewerUrl)}" aria-label="Open generated mockup" target="_blank">
                <img src="${escapeAttribute(data.image_url)}" alt="Generated mockup">
            </a>
        `;

        const actionBox = card.querySelector('.generated-actions');
        if (actionBox) {
            actionBox.style.display = 'flex';
            actionBox.innerHTML = `
                <div style="display: flex; gap: 6px; width: 100%;">
                    <a href="${escapeAttribute(data.download_url)}" class="download-mockup-link button-link" data-base-file="${escapeAttribute(data.mockup_file || '')}" data-context="${escapeAttribute(card.getAttribute('id').replace('context-', ''))}" style="flex: 1; font-size: 11px; padding: 8px;">Download</a>
                    <a href="${escapeAttribute(socialVideoBaseUrl + encodeURIComponent(data.mockup_file || ''))}" class="button-link secondary" style="flex: 1; font-size: 11px; padding: 8px;">Generate Video</a>
                </div>
                ${deleteButton}
            `;
        }

        const ungenForm = card.querySelector('.ungenerated-form');
        if (ungenForm) ungenForm.style.display = 'none';

        const submitBtn = card.querySelector('form.inline-mockup-form button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = 'Regenerar Mockup';
        }

        const form = card.querySelector('form.inline-mockup-form');
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

    async function runMockupGeneration(form, formData, card, resultBox, button, originalHtml) {
        resultBox.innerHTML = `
            <div class="inline-loader">
                <div class="spinner" aria-hidden="true"></div>
                <div class="inline-status">Generating mockup...</div>
            </div>
        `;
        button.innerHTML = 'Generating...';

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
                const readable = rawText.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                throw new Error(readable || 'Invalid response from server.');
            }

            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Mockup generation failed.');
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

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('.inline-mockup-form');
        if (!form) return;
        event.preventDefault();

        const card = form.closest('.card');
        const resultBox = card.querySelector('.inline-result');
        const button = form.querySelector('button[type="submit"]');
        const originalHtml = button.innerHTML;
        const formData = new FormData(form);

        card.classList.remove('generated');
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

    // Batch mockup generation handlers & status polling
    let batchPollingActive = Boolean(document.querySelector('[data-auto-status]'));
    let batchPollingTimer = null;

    document.querySelectorAll('.batch-generate-button').forEach((button) => {
        button.addEventListener('click', async () => {
            const contextIds = String(button.dataset.contextIds || '').split(',').map(v => v.trim()).filter(Boolean);
            if (contextIds.length === 0) return;

            button.disabled = true;
            button.textContent = 'Generating...';

            contextIds.forEach((contextId) => {
                const card = document.querySelector(`.card[data-context-id="${CSS.escape(String(contextId))}"]`);
                if (!card) return;

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
            contextIds.forEach(id => formData.append('context_ids[]', id));

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
                    throw new Error(data.error || 'Could not start batch generation.');
                }
                batchPollingActive = true;
                if (batchPollingTimer) window.clearTimeout(batchPollingTimer);
                batchPollingTimer = window.setTimeout(pollBatchStatus, 1000);
            } catch (error) {
                button.disabled = false;
                button.textContent = 'Generate Batch';
                contextIds.forEach((contextId) => {
                    const card = document.querySelector(`.card[data-context-id="${CSS.escape(String(contextId))}"]`);
                    if (!card) return;
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
        if (!batchPollingActive) return;
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
                if (!card) return;

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

    // AJAX mockup deletion listener
    document.addEventListener('click', async (event) => {
        const deleteBtn = event.target.closest('.btn-delete-mockup');
        if (!deleteBtn) return;
        event.preventDefault();

        if (!confirm('Are you sure you want to delete this mockup?')) return;
        const mockupId = deleteBtn.getAttribute('data-mockup-id');
        const card = deleteBtn.closest('.card') || deleteBtn.closest('.mockup-card-container');
        deleteBtn.disabled = true;

        try {
            const response = await fetch('delete_mockup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'mockup_id=' + encodeURIComponent(mockupId)
            });
            const data = await response.json();
            if (data.ok) {
                if (card) {
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
                    const genActions = card.querySelector('.generated-actions');
                    const ungenForm = card.querySelector('.ungenerated-form');
                    if (genActions) genActions.style.display = 'none';
                    if (ungenForm) ungenForm.style.display = 'block';

                    const formSubmitBtn = card.querySelector('form.inline-mockup-form button[type="submit"]');
                    if (formSubmitBtn) {
                        formSubmitBtn.disabled = false;
                        formSubmitBtn.innerHTML = 'Generate Mockup';
                    }
                    const currentInput = card.querySelector('input[name="current_mockup_file"]');
                    if (currentInput) currentInput.remove();
                }
            } else {
                alert('Error: ' + (data.error || 'Mockup deletion failed.'));
                deleteBtn.disabled = false;
            }
        } catch (err) {
            alert('Network error while deleting mockup.');
            deleteBtn.disabled = false;
        }
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
