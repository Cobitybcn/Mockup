<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Native snapshot updater to keep regression tests in sync
(function() {
    $engine = new MockupCombinationEngine();
    $active = $engine->activeCameraSlots();
    $snapshotData = [];
    foreach ($active as $slotId => $slot) {
        $snapshotData[$slotId] = [
            'slot_name' => $slot['slot_name'],
            'enabled' => (bool)($slot['enabled'] ?? false),
            'camera_slot_geometry' => $slot['camera_slot_geometry'],
        ];
    }
    ksort($snapshotData);
    $snapshotPath = __DIR__ . '/tests/fixtures/camera_slots_snapshot.json';
    @file_put_contents($snapshotPath, json_encode($snapshotData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
})();

$user = Auth::requireUser();
$pdo = Database::connection();

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
    http_response_code(404);
    die('Artwork ID is missing.');
}

$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$artwork = $stmt->fetch();
if (!$artwork) {
    http_response_code(404);
    die('Artwork not found.');
}
if ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
    http_response_code(403);
    die('Access denied.');
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function get_friendly_camera_name(string $slug): string
{
    $mapping = [
        'corte-agresivo-de-esquina-de-obra-loft' => 'Loft - Close-up Corner',
        'corte-agresivo-de-esquina-de-obra-loft-1' => 'Loft - Close-up Corner A',
        'corte-agresivo-de-esquina-de-obra-loft-2' => 'Loft - Close-up Corner B',
        'frontal-close-up-loft' => 'Loft - Frontal Close-up',
        'frontal-close-up-loft-1' => 'Loft - Frontal Close-up A',
        'frontal-close-up-loft-2' => 'Loft - Frontal Close-up B',
        'borde-de-canvas-close-up-loft' => 'Loft - Canvas Edge Detail',
        'contrapicado-78-loft' => 'Loft - Low Angle 7/8',
        'frontal-lejos-loft' => 'Loft - Frontal Wide View'
    ];
    
    if (isset($mapping[$slug])) {
        return $mapping[$slug];
    }
    
    // Clean up slug
    $clean = str_replace(['-', '_'], ' ', $slug);
    $clean = str_replace(['de obra', 'de', 'para'], '', $clean);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return ucwords(trim($clean));
}

function world_mother_image_url(string $file): string
{
    $file = str_replace('\\', '/', trim($file));
    if ($file === '' || !str_starts_with($file, 'storage/world_mothers/')) {
        return '';
    }

    return 'world_mother_media.php?file=' . rawurlencode($file);
}

function world_mother_favorites_path(int $userId): string
{
    return __DIR__ . '/storage/world_mother_favorites/user_' . $userId . '.json';
}

function world_mother_favorites(int $userId): array
{
    $path = world_mother_favorites_path($userId);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn (string $slug): bool => $slug !== ''));
}

function world_mother_search_aliases(string $slug): string
{
    $aliases = [];
    if (str_contains($slug, 'sunlit') || str_contains($slug, 'sun')) {
        $aliases[] = 'sunlight sunny sun morning light daylight';
    }
    if (str_contains($slug, 'blue_hour')) {
        $aliases[] = 'blue hour twilight dusk evening cobalt light';
    }
    if (str_contains($slug, 'low_light') || str_contains($slug, 'dark') || str_contains($slug, 'night')) {
        $aliases[] = 'low light moody evening night shadow';
    }
    if (str_contains($slug, 'atelier') || str_contains($slug, 'studio') || str_contains($slug, 'workspace')) {
        $aliases[] = 'atelier studio workspace artist workroom';
    }
    if (str_contains($slug, 'concrete') || str_contains($slug, 'brutalist')) {
        $aliases[] = 'concrete brutalist raw architecture mineral';
    }

    return implode(' ', $aliases);
}

function stable_world_mother_pool_for_artwork(array $pool, string $category, int $artworkId): array
{
    usort($pool, static function (array $a, array $b): int {
        return strcmp((string)($a['relative_path'] ?? ''), (string)($b['relative_path'] ?? ''));
    });

    usort($pool, static function (array $a, array $b) use ($category, $artworkId): int {
        $aKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($a['relative_path'] ?? '')));
        $bKey = sprintf('%u', crc32($category . '|' . $artworkId . '|' . (string)($b['relative_path'] ?? '')));
        return $aKey <=> $bKey;
    });

    return array_values($pool);
}

function variant_offset_for_world_image(int $comboIndex, int $targetPosition, int $poolCount): int
{
    if ($poolCount <= 0) {
        return 0;
    }

    $base = max(0, $comboIndex - 1);
    return ($targetPosition - ($base % $poolCount) + $poolCount) % $poolCount;
}

$selectedSlots = [];
foreach (($_GET['slot'] ?? []) as $index => $slotId) {
    $selectedSlots[(int)$index] = trim((string)$slotId);
}
$selectedWorldMotherVariants = [];
foreach (($_GET['world_variant'] ?? []) as $index => $offset) {
    $selectedWorldMotherVariants[(int)$index] = max(0, (int)$offset);
}
$selectedWorldMotherCategory = trim(str_replace(['\\', '/'], '', (string)($_GET['world_mother_category'] ?? '')));

$engine = new MockupCombinationEngine();
$review = $engine->buildForArtwork($id, $selectedSlots, [
    'selected_world_mother_category' => $selectedWorldMotherCategory,
    'world_mother_variant_offsets' => $selectedWorldMotherVariants,
]);
$combinations = $review['combinations'] ?? [];
$cameraSlots = $review['available_camera_slots'] ?? [];
$suggestedWorldMotherCategories = (array)($review['suggested_world_mother_categories'] ?? []);
$selectedWorldMotherCategory = (string)($review['selected_world_mother_category'] ?? $selectedWorldMotherCategory);
$selectedWorldMotherImages = stable_world_mother_pool_for_artwork(
    (new WorldMotherLibrary())->imagesForCategory($selectedWorldMotherCategory),
    $selectedWorldMotherCategory,
    $id
);
$favoriteWorldMotherCategories = world_mother_favorites((int)$user['id']);
$favoriteWorldMotherLookup = array_fill_keys($favoriteWorldMotherCategories, true);
$favoriteWorldMotherNormalizedLookup = [];
foreach ($favoriteWorldMotherCategories as $favoriteWorldMotherCategory) {
    $favoriteWorldMotherNormalizedLookup[WorldMotherGenerator::safeSlug($favoriteWorldMotherCategory)] = true;
}

$rootUrl = '';
$rootPath = (string)($review['root_artwork_path'] ?? '');
if ($rootPath !== '') {
    $rootUrl = 'media.php?file=' . rawurlencode(basename($rootPath));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Scenes - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .review-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }
        .workspace-header {
            align-items: center;
            gap: 16px;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .workspace-header h1 {
            font-size: 36px;
            line-height: 1.05;
            margin-bottom: 6px;
        }
        .workspace-header p {
            margin: 0;
            font-size: 13px;
        }
        .workspace-header .topbar-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            max-width: none;
        }
        .workspace-header .topbar-actions .button-link,
        .workspace-header .topbar-actions button.button-link {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: auto !important;
            min-width: 0 !important;
            height: 36px !important;
            min-height: 0 !important;
            padding: 0 16px !important;
            margin: 0 !important;
            border-radius: 4px;
            font-size: 11px !important;
            line-height: 1 !important;
            letter-spacing: .06em;
            box-shadow: none !important;
        }
        .workspace-header .topbar-actions #generate-all-btn {
            min-width: 0 !important;
            flex: 0 0 auto;
        }
        .compact-specs {
            margin: -6px 0 10px;
            color: var(--muted);
        }
        .compact-specs .specs-grid {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 6px 14px;
        }
        .compact-specs strong {
            display: inline;
            font-size: 8px;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .04em;
            margin-right: 4px;
        }
        .compact-specs code {
            font-size: 9px;
            color: var(--muted);
        }
        .combination-card {
            background: #fbfaf7;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: visible;
            position: relative;
            z-index: 1;
        }
        .combination-card:hover,
        .combination-card:focus-within {
            z-index: 30;
        }
        .combination-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            background: #f4f0e9;
            cursor: pointer;
            list-style: none;
        }
        .combination-head::-webkit-details-marker {
            display: none;
        }
        .combination-head::after {
            content: "⌄";
            flex: 0 0 auto;
            color: var(--muted);
            font-size: 18px;
            line-height: 1;
            transform: rotate(-90deg);
            transition: transform .16s ease;
            margin-top: 0;
        }
        .combination-card[open] .combination-head {
            border-bottom: 1px dashed var(--line);
        }
        .combination-card[open] .combination-head::after {
            transform: rotate(0deg);
        }
        .combination-title {
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .combination-status {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 4px;
            margin-left: auto;
        }
        .combination-body {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 8px 10px 10px;
        }
        .combination-head h3 {
            margin: 0;
            font-family: var(--font-sans);
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            word-break: break-word;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            height: 20px;
            padding: 0 6px;
            border-radius: 3px;
            border: 1px solid rgba(154, 123, 86, 0.25);
            background: var(--accent-light);
            color: var(--accent);
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge.ready {
            background: #e6fffa;
            border-color: rgba(35, 78, 82, .25);
            color: #234e52;
        }
        .badge.warn {
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .25);
            color: #8c6d1f;
        }
        .slot-id {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.25;
            word-break: break-word;
        }
        .thumb-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        .card-icon-actions {
            display: none;
        }
        .refresh-world-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: rgba(251, 250, 247, .68);
            color: var(--accent);
            text-decoration: none;
            font-size: 16px;
            line-height: 1;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(20, 20, 18, .08);
        }
        .refresh-world-btn:hover {
            background: rgba(251, 250, 247, .92);
            border-color: rgba(154, 123, 86, .35);
        }
        .thumb-box {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: visible;
            min-height: 0;
            position: relative;
        }
        .scene-thumb-picker {
            position: absolute;
            right: 0;
            bottom: 0;
            z-index: 80;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 4px;
            padding: 5px;
            width: min(520px, 86vw);
            max-height: 360px;
            overflow: auto;
            background: rgba(251, 250, 247, .94);
            border: 1px solid rgba(228, 222, 211, .8);
            border-radius: 7px;
            opacity: 0;
            pointer-events: none;
            transform: translate(8px, 8px);
            transition: opacity .16s ease, transform .16s ease;
            backdrop-filter: blur(10px);
            box-shadow: 0 10px 26px rgba(20, 20, 18, .12);
            scrollbar-width: none;
        }
        .scene-thumb-picker::-webkit-scrollbar {
            display: none;
        }
        .thumb-box:hover .scene-thumb-picker,
        .scene-thumb-picker:focus-within {
            opacity: 1;
            pointer-events: auto;
            transform: translate(0, 0);
        }
        .scene-thumb-option {
            width: 100%;
            height: 112px;
            border: 2px solid transparent;
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface);
            opacity: .86;
            transition: opacity .16s ease, transform .16s ease, border-color .16s ease;
        }
        .scene-thumb-option:hover {
            opacity: 1;
            transform: translateY(-2px);
        }
        .scene-thumb-option.active {
            border-color: var(--accent);
            opacity: 1;
        }
        .scene-thumb-option img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        @media (min-width: 1500px) {
            .scene-thumb-picker {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                width: 640px;
            }
        }
        .combination-card.edge-left .scene-thumb-picker {
            left: 0;
            right: auto;
        }
        .combination-card.edge-right .scene-thumb-picker {
            right: 0;
            left: auto;
        }
        .thumb-box img {
            display: block;
            width: 100%;
            height: 175px;
            object-fit: cover;
            background: var(--surface-soft);
            border-radius: var(--radius);
        }
        .thumb-label {
            display: block;
            padding: 8px 10px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .05em;
            color: var(--muted);
            border-top: 1px solid var(--line);
            word-break: break-word;
        }
        .meta-list {
            display: grid;
            gap: 9px;
            font-size: 13px;
            line-height: 1.45;
        }
        .meta-list strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .camera-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: end;
        }
        .camera-form select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 12px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
        }
        .prompt-preview {
            width: 100%;
            min-height: 190px;
            resize: vertical;
            font-family: monospace;
            font-size: 11px;
            line-height: 1.55;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            color: var(--ink);
        }
        .beta-hidden-stage {
            display: none !important;
        }
        .camera-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
        }
        .camera-title-row strong {
            font-size: 14px;
        }
        .camera-title-row code {
            font-size: 10px;
            color: var(--muted);
        }
        .notes {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .auto-world-panel {
            border: 1px solid rgba(35, 78, 82, .22);
            background: #f0fdfa;
            color: #234e52;
            border-radius: var(--radius);
            padding: 11px 12px;
            font-size: 12px;
            line-height: 1.45;
            word-break: break-word;
        }
        .auto-world-panel strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 4px;
        }
        .auto-world-panel a {
            color: #234e52;
            font-weight: 700;
        }
        .prepare-result {
            font-size: 12px;
            min-height: 0;
            color: var(--muted);
        }
        .prepare-result:empty {
            display: none;
        }
        .scene-browser-panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .scene-browser-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .scene-browser-head strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            color: var(--muted);
            letter-spacing: .04em;
        }
        .scene-browser-head code {
            color: var(--ink);
            font-size: 12px;
        }
        .scene-list-toggle {
            margin-top: 8px;
        }
        .scene-list-toggle:not([open]) .scene-choice-grid {
            display: none;
        }
        .scene-list-toggle summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            color: var(--muted);
            font-size: 12px;
            list-style: none;
            padding: 6px 0 0;
        }
        .scene-list-toggle summary::-webkit-details-marker {
            display: none;
        }
        .scene-list-toggle summary::after {
            content: "open";
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--accent);
        }
        .scene-list-toggle[open] summary::after {
            content: "close";
        }
        .scene-choice-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 8px;
            margin-top: 8px;
            max-height: 230px;
            overflow: auto;
            padding-right: 4px;
        }
        .scene-choice {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: 10px;
            align-items: center;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 7px 8px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
            min-height: 0;
        }
        .scene-choice.hidden { display: none; }
        .scene-choice.active {
            border-color: rgba(154, 123, 86, .55);
            background: var(--accent-light);
        }
        .favorite-scene-btn {
            width: 26px;
            height: 26px;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
        }
        .favorite-scene-btn.active {
            color: #8c6d1f;
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .35);
        }
        .scene-choice strong {
            display: block;
            font-size: 12px;
            line-height: 1.25;
            word-break: break-word;
        }
        .scene-choice span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }
        .scene-choice-meta {
            color: var(--muted);
            font-size: 11px;
            white-space: nowrap;
        }
        .scene-browser-controls {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) minmax(220px, 320px) max-content;
            gap: 8px;
            align-items: center;
        }
        .scene-browser-controls input,
        .scene-browser-controls select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 4px;
            padding: 8px 10px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
            height: 38px;
        }
        .scene-browser-controls .scene-filter-tabs {
            display: inline-flex;
            flex-wrap: nowrap;
            gap: 0;
            justify-content: flex-start;
            border: 1px solid var(--line);
            border-radius: 4px;
            overflow: hidden;
            background: var(--surface-soft);
            height: 38px;
            align-self: stretch;
        }
        .scene-browser-controls .scene-filter-tabs button {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            width: auto !important;
            min-width: 0 !important;
            height: 36px !important;
            min-height: 0 !important;
            border: 0;
            border-right: 1px solid var(--line);
            border-radius: 0;
            background: transparent;
            color: var(--muted);
            padding: 0 10px;
            margin: 0 !important;
            font-size: 10px;
            line-height: 1 !important;
            cursor: pointer;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .04em;
            box-shadow: none;
        }
        .scene-browser-controls .scene-filter-tabs button:last-child {
            border-right: 0;
        }
        .scene-browser-controls .scene-filter-tabs button.active {
            background: var(--accent-light);
            color: var(--accent);
        }
        .scene-browser-count {
            color: var(--muted);
            font-size: 12px;
        }
        .combination-card .button-link {
            background: #f1eee8;
            border-color: var(--line);
            color: var(--muted);
            box-shadow: none;
            padding: 10px 14px;
            min-height: 0;
            margin-top: 0;
        }
        .combination-card .button-link:hover {
            background: var(--accent-light);
            border-color: rgba(154, 123, 86, .35);
            color: var(--accent);
            box-shadow: none;
        }
        @media (max-width: 980px) {
            .review-grid,
            .thumb-row,
            .scene-choice-grid {
                grid-template-columns: 1fr;
            }
            .scene-browser-head {
                display: block;
            }
            .scene-browser-controls {
                grid-template-columns: 1fr;
            }
            .scene-filter-tabs {
                justify-content: flex-start;
            }
            .camera-form {
                grid-template-columns: 1fr;
            }
            .workspace-header,
            .workspace-header .topbar-actions {
                display: block;
            }
            .workspace-header .topbar-actions .button-link {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin: 6px 6px 0 0;
            }
            .compact-specs .specs-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
        @media (min-width: 981px) and (max-width: 1280px) {
            .review-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
            .thumb-box img {
                height: 180px;
            }
        }
        .breadcrumb-steps {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 600;
        }
        .breadcrumb-steps span {
            color: var(--muted);
        }
        .breadcrumb-steps span.active {
            color: var(--accent);
            border-bottom: 1.5px solid var(--accent);
            padding-bottom: 2px;
        }
        .breadcrumb-steps .step-arrow {
            color: var(--line-dark);
            font-weight: normal;
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

        <div class="workspace">
            <div class="breadcrumb-steps">
                <span>Step 1: Upload</span>
                <span class="step-arrow">&rarr;</span>
                <span>Step 2: Select View</span>
                <span class="step-arrow">&rarr;</span>
                <span class="active">Step 3: Scenes</span>
            </div>
            <div class="workspace-header">
                <div>
                    <h1>Scenes</h1>
                    <p>Choose the scene reference for this artwork, then generate the selected views.</p>
                </div>
                <div class="topbar-actions">
                    <button class="button-link" type="button" id="generate-all-btn" onclick="generateAllCombinations(this)">Generate All Scenes</button>
                    <a class="button-link secondary" href="mockup_combination_results.php?id=<?= (int)$id ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Generated Results</a>
                </div>
            </div>

            <?php if (!empty($review['validation_notes'])): ?>
                <div class="notice warning">
                    <strong>Review notes:</strong>
                    <ul style="margin: 6px 0 0 18px;">
                        <?php foreach ((array)$review['validation_notes'] as $note): ?>
                            <li><?= h($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="compact-specs">
                <div class="specs-grid">
                    <span><strong>ID</strong><code><?= (int)$id ?></code></span>
                    <span><strong>Cameras</strong><code><?= count($combinations) ?></code></span>
                    <span><strong>Worlds</strong><code><?= (int)($review['world_mother_categories_available'] ?? $review['world_mother_categories_with_images'] ?? 0) ?></code></span>
                    <span><strong>Mode</strong><code><?= h($review['generation_mode'] ?? '') ?></code></span>
                </div>
            </div>

            <div class="scene-browser-panel">
                <div class="scene-browser-head">
                    <div>
                        <strong>Scenes</strong>
                    </div>
                </div>
                <div class="scene-browser-controls">
                    <input type="search" id="scene-search" placeholder="Search scene environment..." autocomplete="off">
                    <select id="scene-select" aria-label="Select scene environment">
                        <?php foreach ($suggestedWorldMotherCategories as $scene): ?>
                            <?php
                            $slug = (string)($scene['category_slug'] ?? '');
                            $imageCount = (int)($scene['image_count'] ?? 0);
                            ?>
                            <option value="<?= h($slug) ?>" <?= $slug === $selectedWorldMotherCategory ? 'selected' : '' ?>>
                                <?= h((string)($scene['category_name'] ?? $slug)) ?> · <?= $imageCount ?> img
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="scene-filter-tabs" role="group" aria-label="Scene mother filters">
                        <button type="button" class="active" data-scene-filter="all">All</button>
                        <button type="button" data-scene-filter="with-images">With Image</button>
                        <button type="button" data-scene-filter="favorites">Favorites</button>
                    </div>
                </div>
                <details class="scene-list-toggle" id="scene-list-toggle">
                    <summary><span class="scene-browser-count" id="scene-browser-count"></span></summary>
                    <div class="scene-choice-grid">
                        <?php foreach ($suggestedWorldMotherCategories as $scene): ?>
                            <?php
                            $slug = (string)($scene['category_slug'] ?? '');
                            $url = 'mockup_combinations_review.php?id=' . (int)$id . '&world_mother_category=' . rawurlencode($slug);
                            $matchedTerms = implode(' ', array_map('strval', (array)($scene['matched_terms'] ?? [])));
                            $searchText = strtolower(trim($slug . ' ' . str_replace('_', ' ', $slug) . ' ' . (string)($scene['category_name'] ?? '') . ' ' . $matchedTerms . ' ' . world_mother_search_aliases($slug)));
                            $isFavorite = isset($favoriteWorldMotherLookup[$slug]) || isset($favoriteWorldMotherNormalizedLookup[WorldMotherGenerator::safeSlug($slug)]);
                            $imageCount = (int)($scene['image_count'] ?? 0);
                            ?>
                            <a
                                class="scene-choice <?= $slug === $selectedWorldMotherCategory ? 'active' : '' ?>"
                                href="<?= h($url) ?>"
                                data-scene-choice
                                data-slug="<?= h($slug) ?>"
                                data-name="<?= h($scene['category_name'] ?? $slug) ?>"
                                data-image-count="<?= $imageCount ?>"
                                data-favorite="<?= $isFavorite ? '1' : '0' ?>"
                                data-search="<?= h($searchText) ?>"
                            >
                                <div>
                                    <strong><?= h($scene['category_name'] ?? $slug) ?></strong>
                                    <span><code><?= h($slug) ?></code></span>
                                </div>
                                <span class="scene-choice-meta"><?= $imageCount ?> img</span>
                                <button
                                    class="favorite-scene-btn <?= $isFavorite ? 'active' : '' ?>"
                                    type="button"
                                    title="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                                    aria-label="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                                    data-favorite-scene="<?= h($slug) ?>"
                                >★</button>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>

            <div class="review-grid">
                <?php foreach ($combinations as $combo): ?>
                    <?php
                    $idx = (int)$combo['combination_index'];
                    $worldImage = (string)$combo['world_mother_image_path'];
                    $worldImageUrl = world_mother_image_url($worldImage);
                    $generatedWorldMother = (array)($combo['world_mother_selection']['generated_world_mother'] ?? []);
                    $missingWorldMother = (array)($combo['world_mother_selection']['missing_world_mother'] ?? []);
                    $isGeneratedWorldMother = !empty($generatedWorldMother);
                    $isMissingWorldMother = !empty($missingWorldMother);
                    $currentVariantOffset = max(0, (int)($combo['world_mother_variant_offset'] ?? ($selectedWorldMotherVariants[$idx] ?? 0)));
                    $refreshVariantOffsets = [];
                    foreach ($combinations as $otherComboForVariantUrl) {
                        $otherIndexForVariantUrl = (int)($otherComboForVariantUrl['combination_index'] ?? 0);
                        if ($otherIndexForVariantUrl > 0) {
                            $refreshVariantOffsets[$otherIndexForVariantUrl] = max(0, (int)($otherComboForVariantUrl['world_mother_variant_offset'] ?? ($selectedWorldMotherVariants[$otherIndexForVariantUrl] ?? 0)));
                        }
                    }
                    $refreshVariantOffsets[$idx] = $currentVariantOffset + 1;
                    $refreshParams = [
                        'id' => (int)$id,
                        'world_mother_category' => $selectedWorldMotherCategory,
                        'slot' => [],
                        'world_variant' => [],
                    ];
                    foreach ($combinations as $otherComboForUrl) {
                        $otherIndexForUrl = (int)($otherComboForUrl['combination_index'] ?? 0);
                        if ($otherIndexForUrl > 0) {
                            $refreshParams['slot'][$otherIndexForUrl] = (string)($otherComboForUrl['selected_camera_slot_id'] ?? '');
                        }
                    }
                    foreach ($refreshVariantOffsets as $variantIndex => $variantOffset) {
                        if ((int)$variantIndex > 0 && (int)$variantOffset > 0) {
                            $refreshParams['world_variant'][(int)$variantIndex] = (int)$variantOffset;
                        }
                    }
                    $refreshUrl = 'mockup_combinations_review.php?' . http_build_query($refreshParams);
                    $variantBaseParams = $refreshParams;
                    unset($variantBaseParams['world_variant'][$idx]);
                    $selectedWorldImagePath = (string)$combo['world_mother_image_path'];
                    ?>
                    <?php $columnClass = (($idx - 1) % 4) === 0 ? 'edge-left' : (((($idx - 1) % 4) === 3) ? 'edge-right' : ''); ?>
                    <details class="combination-card <?= h($columnClass) ?>" data-combination-card data-combination-row="<?= (int)floor(($idx - 1) / 4) ?>" <?= $idx <= 4 ? 'open' : '' ?>>
                        <summary class="combination-head">
                            <div class="combination-title">
                                <span class="badge">Set <?= $idx ?></span>
                                <h3><?= h(get_friendly_camera_name($combo['selected_camera_slot_id'] ?? '')) ?></h3>
                            </div>
                        </summary>

                        <div class="combination-body">
                            <div class="thumb-row">
                                <div class="thumb-box">
                                    <?php if ($rootUrl !== ''): ?>
                                        <img src="<?= h($rootUrl) ?>" alt="">
                                    <?php endif; ?>
                                </div>
                                <div class="thumb-box">
                                    <?php if ($worldImageUrl !== ''): ?>
                                        <img src="<?= h($worldImageUrl) ?>" alt="">
                                    <?php endif; ?>
                                    <?php if (count($selectedWorldMotherImages) > 1): ?>
                                        <div class="scene-thumb-picker" aria-label="Choose scene reference">
                                            <?php foreach ($selectedWorldMotherImages as $imagePosition => $sceneImage): ?>
                                                <?php
                                                $sceneImagePath = (string)($sceneImage['relative_path'] ?? '');
                                                $sceneImageUrl = world_mother_image_url($sceneImagePath);
                                                if ($sceneImageUrl === '') {
                                                    continue;
                                                }
                                                $variantUrlParams = $variantBaseParams;
                                                $variantOffset = variant_offset_for_world_image($idx, (int)$imagePosition, count($selectedWorldMotherImages));
                                                if ($variantOffset > 0) {
                                                    $variantUrlParams['world_variant'][$idx] = $variantOffset;
                                                }
                                                $variantUrl = 'mockup_combinations_review.php?' . http_build_query($variantUrlParams);
                                                ?>
                                                <a
                                                    class="scene-thumb-option <?= $sceneImagePath === $selectedWorldImagePath ? 'active' : '' ?>"
                                                    href="<?= h($variantUrl) ?>"
                                                    title="<?= h((string)($sceneImage['title'] ?? 'Scene reference')) ?>"
                                                    aria-label="Choose <?= h((string)($sceneImage['title'] ?? 'scene reference')) ?>"
                                                >
                                                    <img src="<?= h($sceneImageUrl) ?>" alt="">
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($isGeneratedWorldMother): ?>
                                <div class="auto-world-panel">
                                    <strong>Beta auto-generated scene mother</strong>
                                    This scene mother was created earlier. For this beta flow, prefer replacing it with a curated manual image if quality is not enough.
                                    <?php if ($worldImageUrl !== ''): ?>
                                        <br><a href="<?= h($worldImageUrl) ?>" target="_blank" rel="noopener">Open generated image</a>
                                    <?php endif; ?>
                                    <?php if (!empty($generatedWorldMother['audit_file'])): ?>
                                        <br>Audit: <code><?= h($generatedWorldMother['audit_file']) ?></code>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($isMissingWorldMother): ?>
                                <div class="auto-world-panel">
                                    <strong>Beta scene mother pending</strong>
                                    Add one image manually to <code><?= h($missingWorldMother['folder'] ?? ('storage/world_mothers/' . $combo['world_mother_category'])) ?></code>, then refresh. The system will not generate this scene mother automatically.
                                </div>
                            <?php endif; ?>

                            <form class="camera-form beta-hidden-stage" method="get" action="mockup_combinations_review.php">
                                <input type="hidden" name="id" value="<?= (int)$id ?>">
                                <input type="hidden" name="world_mother_category" value="<?= h($selectedWorldMotherCategory) ?>">
                                <?php foreach ($combinations as $other): ?>
                                    <?php if ((int)$other['combination_index'] !== $idx): ?>
                                        <input type="hidden" name="slot[<?= (int)$other['combination_index'] ?>]" value="<?= h($other['selected_camera_slot_id']) ?>">
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <label>
                                    <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:4px;">Selected Camera Slot</strong>
                                    <select name="slot[<?= $idx ?>]" onchange="this.form.submit()">
                                        <?php foreach ($cameraSlots as $slot): ?>
                                            <?php $slotId = (string)($slot['slot_id'] ?? ''); ?>
                                            <option value="<?= h($slotId) ?>" <?= $slotId === (string)$combo['selected_camera_slot_id'] ? 'selected' : '' ?>>
                                                <?= h(get_friendly_camera_name($slotId)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <button class="button-link secondary" type="submit">Refresh Preview</button>
                            </form>

                            <div class="beta-hidden-stage">
                                <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:6px;">Final Prompt Preview</strong>
                                <textarea class="prompt-preview" readonly><?= h($combo['final_prompt_preview']) ?></textarea>
                            </div>

                            <?php if (!empty($combo['validation_notes'])): ?>
                                <ul class="notes">
                                    <?php foreach ((array)$combo['validation_notes'] as $note): ?>
                                        <li><?= h($note) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <div>
                                <input
                                    type="hidden"
                                    class="world-mother-scale-input"
                                    id="world-mother-scale-<?= $idx ?>"
                                    value="1.0"
                                >
                                <div id="prepare-result-<?= $idx ?>" class="prepare-result"></div>
                                <button
                                    class="button-link"
                                    type="button"
                                    data-index="<?= $idx ?>"
                                    data-artwork-id="<?= (int)$id ?>"
                                    data-camera-slot="<?= h($combo['selected_camera_slot_id']) ?>"
                                    data-camera-name="<?= h(get_friendly_camera_name($combo['selected_camera_slot_id'] ?? '')) ?>"
                                    data-world-mother-category="<?= h($selectedWorldMotherCategory) ?>"
                                    data-world-mother-variant="<?= $currentVariantOffset ?>"
                                    onclick="prepareCombination(this)"
                                    <?= empty($combo['generation_ready']) ? 'disabled' : '' ?>
                                >Generate This Set</button>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<script>
function prepareCombination(btn, skipConfirm = false) {
    const cameraName = btn.getAttribute('data-camera-name') || 'selected camera';
    const cameraSlot = btn.getAttribute('data-camera-slot') || '';
    const label = cameraName + (cameraSlot ? ' [' + cameraSlot + ']' : '');
    if (!skipConfirm && !confirm('Generate this camera now?\n\n' + label + '\n\nThis may consume a real API credit when real API mode is enabled.')) {
        return;
    }
    return runCombinationGeneration(btn);
}

function runCombinationGeneration(btn) {
    const index = btn.getAttribute('data-index');
    const status = document.getElementById('prepare-result-' + index);
    const formData = new FormData();
    formData.append('artwork_id', btn.getAttribute('data-artwork-id'));
    formData.append('combination_index', index);
    formData.append('camera_slot_id', btn.getAttribute('data-camera-slot'));
    formData.append('world_mother_category', btn.getAttribute('data-world-mother-category'));
    formData.append('world_mother_variant_offset', btn.getAttribute('data-world-mother-variant') || '0');
    const scaleInput = document.getElementById('world-mother-scale-' + index);
    if (scaleInput && scaleInput.value) {
        formData.append('world_mother_scale', scaleInput.value);
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = 'Generating...';
    status.textContent = 'Generating image from root artwork, world mother reference, selected camera, and ADMIN prompt.';

    return fetch('generate_mockup_combination.php', { method: 'POST', body: formData })
        .then(response => response.text().then(text => {
            let parsed;
            try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
            return { status: response.status, body: parsed };
        }))
        .then(result => {
            if (result.status === 200 && result.body.ok) {
                status.innerHTML = (result.body.message || 'Image generated.') + ' <a href="' + result.body.results_url + '">Evaluate results</a>';
                btn.textContent = 'Generated';
                return result.body;
            } else {
                status.textContent = (result.body && result.body.error) ? result.body.error : 'Preparation failed.';
                btn.disabled = false;
                btn.textContent = originalText;
                throw new Error(status.textContent);
            }
        })
        .catch(err => {
            status.textContent = 'Preparation failed: ' + err.message;
            btn.disabled = false;
            btn.textContent = originalText;
            throw err;
        });
}

async function generateAllCombinations(btn) {
    const buttons = Array.from(document.querySelectorAll('button[data-index][data-artwork-id][data-camera-slot]:not([disabled])'));
    if (buttons.length === 0) {
        alert('No combinations are available to generate.');
        return;
    }
    if (!confirm('Generate all ' + buttons.length + ' combinations now? This may consume one real API credit per combination when real API mode is enabled.')) {
        return;
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < buttons.length; i++) {
        const comboBtn = buttons[i];
        btn.textContent = 'Generating ' + (i + 1) + ' / ' + buttons.length + '...';
        try {
            await prepareCombination(comboBtn, true);
            successCount++;
        } catch (err) {
            failCount++;
        }
    }

    btn.disabled = false;
    btn.textContent = originalText;

    if (successCount > 0) {
        const go = confirm('Generation complete. Success: ' + successCount + ', failed: ' + failCount + '. Open results now?');
        if (go) {
            window.location.href = 'mockup_combination_results.php?id=<?= (int)$id ?>';
        }
    } else {
        alert('No combinations were generated. Check the messages on each card.');
    }
}

const sceneSearchInput = document.getElementById('scene-search');
const sceneSelect = document.getElementById('scene-select');
const sceneCount = document.getElementById('scene-browser-count');
const sceneListToggle = document.getElementById('scene-list-toggle');
let activeSceneFilter = 'all';

function updateSceneBrowser() {
    const query = (sceneSearchInput ? sceneSearchInput.value : '').trim().toLowerCase();
    const cards = Array.from(document.querySelectorAll('[data-scene-choice]'));
    let visible = 0;
    for (const card of cards) {
        const hasImages = parseInt(card.getAttribute('data-image-count') || '0', 10) > 0;
        const isFavorite = card.getAttribute('data-favorite') === '1';
        const haystack = card.getAttribute('data-search') || '';
        const matchesQuery = query === '' || haystack.includes(query);
        const matchesFilter =
            activeSceneFilter === 'all'
            || (activeSceneFilter === 'with-images' && hasImages)
            || (activeSceneFilter === 'favorites' && isFavorite);
        const show = matchesQuery && matchesFilter;
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    }
    if (sceneCount) {
        sceneCount.textContent = visible + ' of ' + cards.length + ' scene environments visible';
    }
}

if (sceneSearchInput) {
    sceneSearchInput.addEventListener('input', () => {
        if (sceneListToggle && sceneSearchInput.value.trim() !== '') {
            sceneListToggle.open = true;
        }
        updateSceneBrowser();
    });
}
if (sceneSelect) {
    sceneSelect.addEventListener('change', () => {
        const slug = sceneSelect.value || '';
        if (slug !== '') {
            window.location.href = 'mockup_combinations_review.php?id=<?= (int)$id ?>&world_mother_category=' + encodeURIComponent(slug);
        }
    });
}
document.querySelectorAll('[data-scene-filter]').forEach(button => {
    button.addEventListener('click', () => {
        activeSceneFilter = button.getAttribute('data-scene-filter') || 'all';
        document.querySelectorAll('[data-scene-filter]').forEach(btn => btn.classList.toggle('active', btn === button));
        if (sceneListToggle && activeSceneFilter !== 'all') {
            sceneListToggle.open = true;
        }
        updateSceneBrowser();
    });
});
document.querySelectorAll('[data-favorite-scene]').forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const slug = button.getAttribute('data-favorite-scene') || '';
        if (slug === '') return;
        const formData = new FormData();
        formData.append('category', slug);
        fetch('toggle_world_mother_favorite.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (!result.ok) {
                    throw new Error(result.error || 'Favorite update failed.');
                }
                const favorite = result.favorite ? '1' : '0';
                button.classList.toggle('active', result.favorite);
                button.title = result.favorite ? 'Remove favorite' : 'Add favorite';
                button.setAttribute('aria-label', button.title);
                const card = button.closest('[data-scene-choice]');
                if (card) {
                    card.setAttribute('data-favorite', favorite);
                }
                updateSceneBrowser();
            })
            .catch(err => {
                alert(err.message);
            });
    });
});

document.querySelectorAll('[data-combination-card]').forEach(card => {
    card.addEventListener('toggle', () => {
        if (card.dataset.syncingRow === '1') return;
        const row = card.getAttribute('data-combination-row');
        if (row === null) return;
        document.querySelectorAll('[data-combination-card][data-combination-row="' + row + '"]').forEach(rowCard => {
            if (rowCard === card) return;
            rowCard.dataset.syncingRow = '1';
            rowCard.open = card.open;
            window.setTimeout(() => {
                delete rowCard.dataset.syncingRow;
            }, 0);
        });
    });
});

updateSceneBrowser();
</script>
</body>
</html>
