<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

$id = (int)($_GET['id'] ?? 0);
$file = basename((string)($_GET['file'] ?? ''));

if ($id > 0) {
    $stmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND id = :id
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'id' => $id,
    ]);
} else {
    $stmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND mockup_file = :file
        LIMIT 1
    ');
    $stmt->execute([
        'user_id' => (int)$user['id'],
        'file' => $file,
    ]);
}

$mockup = $stmt->fetch();
$isStandaloneFile = false;
$standaloneFiles = [];

if (!$mockup) {
    $standaloneFile = $file;
    $standalonePath = $standaloneFile !== '' ? RESULTS_DIR . DIRECTORY_SEPARATOR . $standaloneFile : '';
    $artwork = null;
    if ($standaloneFile !== '' && is_file($standalonePath)) {
        $stmt = $pdo->prepare('
            SELECT *
            FROM artworks
            WHERE user_id = :user_id
            AND (root_file = :file OR main_file = :file)
            LIMIT 1
        ');
        $stmt->execute([
            'user_id' => (int)$user['id'],
            'file' => $standaloneFile,
        ]);
        $artwork = $stmt->fetch();

        if (!$artwork && preg_match('/^base_artwork_gemini_job_(\d+_\d+)_v\d+\.(png|jpe?g|webp)$/i', $standaloneFile, $matches)) {
            $jobId = 'job_' . $matches[1];
            $stmt = $pdo->prepare('
                SELECT *
                FROM artworks
                WHERE user_id = :user_id
                AND job_id = :job_id
                LIMIT 1
            ');
            $stmt->execute([
                'user_id' => (int)$user['id'],
                'job_id' => $jobId,
            ]);
            $artwork = $stmt->fetch();
        }
    }

    if (!is_array($artwork)) {
        http_response_code(404);
        exit('Image not found.');
    }

    $isStandaloneFile = true;
    $mockup = [
        'id' => 0,
        'artwork_file' => (string)($artwork['root_file'] ?: $artwork['main_file'] ?: $standaloneFile),
        'mockup_file' => $standaloneFile,
        'context_id' => 'Root artwork',
        'created_at' => (string)($artwork['updated_at'] ?? $artwork['created_at'] ?? date('c')),
    ];
}

$backUrl = 'mockups.php';
$requestedBack = trim((string)($_GET['back'] ?? ''));
if ($requestedBack !== '' && preg_match('/^(form2\.php|artwork\.php|artwork_details\.php|mockups\.php|mockup_combination_results\.php|dashboard\.php)(\?|#|$)/', $requestedBack)) {
    $backUrl = $requestedBack;
}
if (!isset($artwork) || !is_array($artwork)) {
    $artworkStmt = $pdo->prepare('
        SELECT *
        FROM artworks
        WHERE user_id = :user_id
        AND (root_file = :artwork_file OR main_file = :artwork_file)
        LIMIT 1
    ');
    $artworkStmt->execute([
        'user_id' => (int)$user['id'],
        'artwork_file' => (string)$mockup['artwork_file'],
    ]);
    $artwork = $artworkStmt->fetch();
}
$artworkId = is_array($artwork) ? (int)$artwork['id'] : 0;

if ($artworkId && $requestedBack === '') {
    $backUrl = 'artwork.php?id=' . rawurlencode((string)$artworkId);
}
$viewerBackParam = $backUrl !== '' ? '&back=' . rawurlencode($backUrl) : '';

$prevHref = '';
$nextHref = '';
if ($isStandaloneFile) {
    $currentFile = basename((string)$mockup['mockup_file']);
    $prefix = '';
    if (preg_match('/^(.*)_v\d+\.(png|jpe?g|webp)$/i', $currentFile, $matches)) {
        $prefix = (string)$matches[1];
    }
    if ($prefix !== '') {
        foreach ([1, 2, 3] as $version) {
            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                $candidate = $prefix . '_v' . $version . '.' . $ext;
                if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $candidate)) {
                    $standaloneFiles[] = $candidate;
                    break;
                }
            }
        }
        $index = array_search($currentFile, $standaloneFiles, true);
        if ($index !== false) {
            if (isset($standaloneFiles[$index - 1])) {
                $prevHref = 'viewer.php?file=' . rawurlencode($standaloneFiles[$index - 1]) . $viewerBackParam;
            }
            if (isset($standaloneFiles[$index + 1])) {
                $nextHref = 'viewer.php?file=' . rawurlencode($standaloneFiles[$index + 1]) . $viewerBackParam;
            }
        }
    }
} else {
    $prevStmt = $pdo->prepare('
        SELECT id
        FROM mockups
        WHERE user_id = :user_id
        AND (
            created_at > :created_at
            OR (created_at = :created_at AND id > :id)
        )
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ');
    $prevStmt->execute([
        'user_id' => (int)$user['id'],
        'created_at' => (string)$mockup['created_at'],
        'id' => (int)$mockup['id'],
    ]);
    $prevId = $prevStmt->fetchColumn();
    $prevHref = $prevId ? 'viewer.php?id=' . rawurlencode((string)$prevId) . $viewerBackParam : '';

    $nextStmt = $pdo->prepare('
        SELECT id
        FROM mockups
        WHERE user_id = :user_id
        AND (
            created_at < :created_at
            OR (created_at = :created_at AND id < :id)
        )
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ');
    $nextStmt->execute([
        'user_id' => (int)$user['id'],
        'created_at' => (string)$mockup['created_at'],
        'id' => (int)$mockup['id'],
    ]);
    $nextId = $nextStmt->fetchColumn();
    $nextHref = $nextId ? 'viewer.php?id=' . rawurlencode((string)$nextId) . $viewerBackParam : '';
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function media_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) : '';
}

function download_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) . '&download=1' : '';
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

function english_term(string $value): string
{
    $value = str_replace(
        ['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ', 'á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
        ['A', 'E', 'I', 'O', 'U', 'U', 'N', 'a', 'e', 'i', 'o', 'u', 'u', 'n'],
        $value
    );
    $value = strtolower(trim(preg_replace('/[_-]+/', ' ', $value)));
    $map = [
        'abstracto' => 'abstract',
        'contemporaneo' => 'contemporary',
        'geometrico' => 'geometric',
        'arquitectonico' => 'architectural',
        'organico' => 'organic',
        'minimalista' => 'minimal',
        'figurativo' => 'figurative',
        'expresivo' => 'expressive',
        'estructural' => 'structural',
        'simbolico' => 'symbolic',
        'metafisico' => 'metaphysical',
        'silencio' => 'silence',
        'territorio' => 'territory',
        'austeridad' => 'austerity',
        'monolitos' => 'monoliths',
        'coleccionistas' => 'collectors',
        'galeria' => 'gallery',
        'sutil' => 'subtle',
    ];

    return $map[$value] ?? $value;
}

function english_terms(array $items, array $fallback): array
{
    $terms = [];

    foreach ($items as $item) {
        $term = english_term((string)$item);
        if (str_word_count($term) <= 4 && strlen($term) <= 42) {
            $terms[] = $term;
        }
    }

    return unique_limited($terms, 12, $fallback);
}

function title_case_soft(string $value): string
{
    $small = ['and', 'or', 'of', 'in', 'the', 'a', 'an', 'with', 'for'];
    $words = preg_split('/\s+/', strtolower(trim($value))) ?: [];
    $words = array_map(fn(string $word): string => in_array($word, $small, true) ? $word : ucfirst($word), $words);

    if ($words) {
        $words[0] = ucfirst($words[0]);
    }

    return implode(' ', $words);
}

$rootFile = is_array($artwork) ? basename((string)($artwork['root_file'] ?? '')) : basename((string)$mockup['artwork_file']);
$rootBase = $rootFile ? pathinfo($rootFile, PATHINFO_FILENAME) : '';
$analysis = $rootBase ? read_json_file(ANALYSIS_DIR . DIRECTORY_SEPARATOR . $rootBase . '.analysis.json') : [];
$profile = is_array($analysis['artwork_profile'] ?? null) ? $analysis['artwork_profile'] : [];
$artistProfile = is_array($profile['_artist_profile'] ?? null) ? $profile['_artist_profile'] : ArtistProfile::findForUser((int)$user['id']);
$artistName = trim((string)($artistProfile['artist_name'] ?? ''));
$style = english_terms(words_from($profile['style_tags'] ?? $artistProfile['visual_language'] ?? []), ['contemporary', 'abstract', 'material']);
$mood = english_terms(words_from($profile['mood_tags'] ?? []), ['quiet intensity', 'contemplative', 'collector-grade']);
$palette = english_terms(words_from($profile['palette'] ?? $artistProfile['palette_notes'] ?? []), ['balanced palette', 'sober tones']);
$themes = english_terms(words_from($artistProfile['recurring_themes'] ?? ''), ['territory', 'silence', 'material presence']);
$contextTitle = Display::contextTitle($mockup['context_id']);
$storedTitle = is_array($artwork) ? trim((string)($artwork['final_title'] ?? '')) : '';
$baseTitle = $storedTitle !== '' ? $storedTitle : title_case_soft(($palette[0] ?? 'Balanced Palette') . ' in ' . ($themes[0] ?? 'Quiet Space'));
$subtitle = is_array($artwork) && trim((string)($artwork['subtitle'] ?? '')) !== ''
    ? trim((string)$artwork['subtitle'])
    : title_case_soft('a ' . ($style[0] ?? 'contemporary') . ' artwork for collectors');
$titleLine = $baseTitle . ': ' . $subtitle;
$sizeText = '';
if (is_array($artwork) && trim((string)($artwork['width'] ?? '')) !== '' && trim((string)($artwork['height'] ?? '')) !== '') {
    $sizeText = trim((string)$artwork['width'] . ' x ' . (string)$artwork['height'] . ' ' . (string)($artwork['unit'] ?? 'cm'));
}
$pinBoard = in_array('architectural', $style, true) || in_array('structural', $style, true)
    ? 'Architectural Minimalism'
    : 'Contemporary Abstract Art';
$pinTitle = $baseTitle . ' - Original Contemporary Abstract Artwork';
$pinDescription = $titleLine . "\n\n" .
    'This Pin features a generated curatorial mockup of an original contemporary artwork in a ' . strtolower($contextTitle) . ' setting. The image highlights the artwork scale, wall presence, color atmosphere, and gallery-ready presentation for collectors, interior designers, galleries, and buyers searching for abstract art for interiors.';
$pinAlt = 'A generated mockup showing ' . $baseTitle . ' as a contemporary artwork in a ' . strtolower($contextTitle) . ' setting, with visible wall placement, artwork scale, color presence, and surrounding interior atmosphere.';
$pinKeywords = unique_limited(array_merge([
    'contemporary abstract art',
    'original painting for sale',
    'artwork for collectors',
    'abstract painting for interiors',
    'minimalist abstract painting',
    'large wall art',
    'gallery ready artwork',
    'art for interior designers',
    'statement painting',
    'modern collector art',
], $style, $palette, $themes), 14);
$pinHashtags = array_map(
    fn($tag) => '#' . preg_replace('/[^a-z0-9]/', '', strtolower($tag)),
    unique_limited(['contemporary art', 'abstract painting', 'original artwork', 'art collectors', 'interior design art', 'large painting', 'statement art'], 8)
);
$otherSocial = [
    'Instagram' => $titleLine . "\n\n" . 'Generated curatorial mockup for ' . strtolower($contextTitle) . '. ' . title_case_soft(implode(', ', array_slice($style, 0, 3))) . ' with ' . implode(', ', array_slice($mood, 0, 2)) . ".\n\n" . implode(' ', array_slice($pinHashtags, 0, 8)),
    'Facebook' => $titleLine . "\n\n" . 'A curated mockup presentation prepared for collectors, interior designers, galleries, and marketplace publication. This image can accompany the artwork listing, auction page, or artist profile.',
    'X' => $baseTitle . ' - original contemporary artwork shown in a curated mockup for collectors, interiors, and art platforms.',
    'TikTok' => 'Use this mockup as a short reveal: start with the room context, move into the artwork surface and scale, then end with the title "' . $baseTitle . '" and the destination link.',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Viewer - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-serif: 'Cormorant Garamond', Georgia, serif;
            --font-sans: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --bg: #FAF9F6;
            --surface: #FFFFFF;
            --surface-soft: #F4F3EE;
            --line: #E5E3DD;
            --ink: #141412;
            --muted: #7A7872;
            --accent: #9A7B56;
            --accent-hover: #7E6342;
            --radius: 4px;
            --shadow: 0 4px 30px rgba(20, 20, 18, 0.03);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--font-sans);
            zoom: 1;
        }

        .viewer-top {
            position: sticky;
            z-index: 5;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 22px;
            background: rgba(250, 249, 246, .94);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--line);
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--ink);
            font-family: var(--font-serif);
            font-size: 18px;
            letter-spacing: 0.12em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .viewer-left {
            display: inline-flex;
            align-items: center;
            gap: 22px;
        }

        .icon-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            color: var(--ink);
            text-decoration: none;
            opacity: .84;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .icon-link.back::before {
            content: '';
            width: 12px;
            height: 12px;
            border-left: 3px solid currentColor;
            border-bottom: 3px solid currentColor;
            transform: rotate(45deg);
            margin-left: 4px;
        }

        .icon-link.download::before {
            content: '';
            width: 3px;
            height: 18px;
            background: currentColor;
            margin-top: -4px;
        }

        .icon-link.download::after {
            content: '';
            position: absolute;
            width: 11px;
            height: 11px;
            border-left: 3px solid currentColor;
            border-bottom: 3px solid currentColor;
            transform: rotate(-45deg);
            margin-top: 8px;
        }

        .icon-link.download .download-base {
            position: absolute;
            width: 18px;
            height: 3px;
            background: currentColor;
            bottom: 5px;
        }

        .icon-link:hover {
            opacity: 1;
            color: var(--accent);
        }

        .brand-mark {
            width: 12px;
            height: 12px;
            border: 3px solid var(--accent);
            display: inline-block;
        }

        .viewer-actions {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .viewer-actions a {
            color: var(--ink);
            text-decoration: none;
            font-weight: 500;
            font-size: 13px;
            border-bottom: 1px solid transparent;
            transition: all 0.2s ease;
        }

        .viewer-actions a:hover {
            color: var(--accent);
            border-color: var(--accent);
        }

        .viewer-actions .icon-link {
            border-bottom: 0;
            position: relative;
        }

        .stage {
            min-height: calc(100vh - 64px);
            display: grid;
            place-items: center;
            padding: 24px 56px;
            background: #111;
        }

        .stage img {
            max-width: 100%;
            max-height: calc(100vh - 112px);
            object-fit: contain;
            box-shadow: 0 28px 80px rgba(0,0,0,.6);
            border-radius: 4px;
        }

        .nav-arrow {
            position: fixed;
            z-index: 4;
            top: 50%;
            transform: translateY(-50%);
            width: 54px;
            height: 86px;
            display: grid;
            place-items: center;
            color: #fff;
            text-decoration: none;
            font-size: 58px;
            line-height: 1;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            transition: all 0.2s ease;
        }

        .nav-arrow:hover {
            background: var(--accent);
            border-color: var(--accent);
        }

        .nav-arrow.prev {
            left: 22px;
        }

        .nav-arrow.next {
            right: 22px;
        }

        .viewer-caption {
            position: static;
            z-index: 5;
            padding: 18px 24px;
            display: flex;
            justify-content: center;
            gap: 18px;
            color: var(--muted);
            background: var(--surface);
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .publication {
            max-width: 1240px;
            margin: 0 auto;
            padding: 44px 24px 70px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 28px;
            margin-bottom: 24px;
        }

        .panel.pinterest {
            background: linear-gradient(180deg, var(--surface) 0%, #fbf8f2 100%);
            border-color: rgba(154, 123, 86, 0.32);
        }

        .section-heading {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 18px;
            border-bottom: 1px dashed var(--line);
            padding-bottom: 14px;
            margin-bottom: 20px;
        }

        h1,
        h2,
        h3 {
            font-family: var(--font-serif);
            font-weight: 500;
            line-height: 1.2;
            margin: 0;
        }

        h2 {
            font-size: 30px;
        }

        h3 {
            font-size: 22px;
            margin-bottom: 10px;
        }

        p,
        small {
            color: var(--muted);
        }

        .pin-fields,
        .social-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .pin-field,
        .social-card {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 16px;
        }

        .pin-field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--ink);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        textarea,
        input[type="text"] {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            border-radius: var(--radius);
            padding: 12px 14px;
            font: inherit;
            font-size: 13px;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .copy-block {
            white-space: pre-wrap;
            color: var(--ink);
            line-height: 1.7;
        }

        .keyword-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .keyword-chip {
            display: inline-flex;
            align-items: center;
            border: 1px solid var(--line);
            background: var(--surface);
            color: var(--ink);
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
        }

        .copy-button {
            display: inline-block;
            width: auto;
            margin: 12px 8px 0 0;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--ink);
            padding: 8px 10px;
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            font-size: 10px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            border-radius: var(--radius);
        }

        .copy-button:hover {
            background: #fbf7ef;
            border-color: var(--accent);
        }

        @media (max-width: 760px) {
            .stage {
                padding: 28px 18px;
                min-height: 60vh;
            }

            .nav-arrow {
                width: 44px;
                height: 68px;
                font-size: 42px;
            }

            .nav-arrow.prev {
                left: 8px;
            }

            .nav-arrow.next {
                right: 8px;
            }

            .viewer-caption {
                display: block;
                text-align: center;
            }

            .pin-fields,
            .social-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <header class="viewer-top">
        <div class="viewer-left">
            <a class="brand" href="dashboard.php">The Artwork Curator <span class="brand-mark"></span></a>
        </div>
        <nav class="viewer-actions">
            <a class="icon-link back" href="<?= h($backUrl) ?>" aria-label="Back to details" title="Back to details"></a>
            <a href="mockups.php">Mockups</a>
            <a class="icon-link download" href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Download mockup" title="Download mockup"><span class="download-base" aria-hidden="true"></span></a>
        </nav>
    </header>

    <?php if ($prevHref !== ''): ?>
        <a class="nav-arrow prev" href="<?= h($prevHref) ?>" aria-label="Previous image">&lsaquo;</a>
    <?php endif; ?>

    <main class="stage">
        <img src="<?= h(media_url($mockup['mockup_file'])) ?>" alt="Mockup">
    </main>

    <?php if ($nextHref !== ''): ?>
        <a class="nav-arrow next" href="<?= h($nextHref) ?>" aria-label="Next image">&rsaquo;</a>
    <?php endif; ?>

    <footer class="viewer-caption">
        <span><?= h(Display::contextTitle($mockup['context_id'])) ?></span>
        <span><?= h(date('m/d/Y H:i', strtotime((string)$mockup['created_at']))) ?></span>
    </footer>

    <section class="publication">
        <section class="panel pinterest">
            <div class="section-heading">
                <div>
                    <h2>Pinterest Pin Content</h2>
                    <p>Optimized fields for this exact mockup image.</p>
                </div>
                <p><?= h($contextTitle) ?></p>
            </div>

            <div class="pin-fields">
                <div class="pin-field">
                    <label>Pinterest Board / Category Suggestion</label>
                    <p class="copy-block"><?= h($pinBoard) ?></p>
                </div>

                <div class="pin-field">
                    <label>Pin Title</label>
                    <p class="copy-block"><?= h($pinTitle) ?></p>
                    <button class="copy-button" type="button" data-copy="<?= h($pinTitle) ?>">Copy Pin Title</button>
                </div>

                <div class="pin-field full">
                    <label>Pin Description</label>
                    <textarea readonly><?= h($pinDescription) ?></textarea>
                    <button class="copy-button" type="button" data-copy="<?= h($pinDescription) ?>">Copy Pin Description</button>
                </div>

                <div class="pin-field full">
                    <label>Alt Text / Accessibility Text</label>
                    <textarea readonly><?= h($pinAlt) ?></textarea>
                    <small>Use this for Pinterest's “Explain what people can see in the Pin” field.</small>
                    <button class="copy-button" type="button" data-copy="<?= h($pinAlt) ?>">Copy Alt Text</button>
                </div>

                <div class="pin-field full">
                    <label>Destination Link</label>
                    <input id="destination_link" type="text" placeholder="Paste Saatchi Art, Catawiki, Artsy, gallery, marketplace or artist website URL">
                    <small>Add the final sales page, auction page, artwork page, artist profile, or gallery listing before publishing.</small>
                    <button class="copy-button" type="button" data-copy-source="destination_link">Copy Destination Link</button>
                </div>

                <div class="pin-field">
                    <label>Suggested Pinterest Keywords</label>
                    <div class="keyword-wrap">
                        <?php foreach ($pinKeywords as $keyword): ?>
                            <span class="keyword-chip"><?= h($keyword) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <button class="copy-button" type="button" data-copy="<?= h(implode(', ', $pinKeywords)) ?>">Copy Keywords</button>
                </div>

                <div class="pin-field">
                    <label>Suggested Hashtags</label>
                    <p class="copy-block"><?= h(implode(' ', $pinHashtags)) ?></p>
                    <button class="copy-button" type="button" data-copy="<?= h(implode(' ', $pinHashtags)) ?>">Copy Hashtags</button>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="section-heading">
                <div>
                    <h2>Other Social Media Content</h2>
                    <p>Captions prepared for this exact mockup image.</p>
                </div>
                <p>Instagram, Facebook, X and TikTok</p>
            </div>

            <div class="social-grid">
                <?php foreach ($otherSocial as $platform => $copy): ?>
                    <article class="social-card">
                        <h3><?= h($platform) ?></h3>
                        <p class="copy-block"><?= h($copy) ?></p>
                        <button class="copy-button" type="button" data-copy="<?= h($copy) ?>">Copy</button>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    </section>

    <script>
        document.querySelectorAll('[data-copy]').forEach((button) => {
            button.addEventListener('click', async () => {
                const original = button.textContent;
                try {
                    await navigator.clipboard.writeText(button.dataset.copy || '');
                    button.textContent = 'Copied';
                    setTimeout(() => button.textContent = original, 1200);
                } catch (error) {
                    button.textContent = 'Copy failed';
                    setTimeout(() => button.textContent = original, 1200);
                }
            });
        });

        document.querySelectorAll('[data-copy-source]').forEach((button) => {
            button.addEventListener('click', async () => {
                const original = button.textContent;
                const source = document.getElementById(button.dataset.copySource || '');
                try {
                    await navigator.clipboard.writeText(source ? source.value : '');
                    button.textContent = 'Copied';
                    setTimeout(() => button.textContent = original, 1200);
                } catch (error) {
                    button.textContent = 'Copy failed';
                    setTimeout(() => button.textContent = original, 1200);
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowLeft') {
                const prev = document.querySelector('.nav-arrow.prev');
                if (prev) window.location.href = prev.href;
            }

            if (event.key === 'ArrowRight') {
                const next = document.querySelector('.nav-arrow.next');
                if (next) window.location.href = next.href;
            }

            if (event.key === 'Escape') {
                window.location.href = <?= json_encode($backUrl, JSON_UNESCAPED_SLASHES) ?>;
            }
        });
    </script>
</body>
</html>
