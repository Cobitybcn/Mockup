<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = Auth::requireUser();
$pdo = Database::connection();
Auth::start();
$userId = (int)$user['id'];
$seriesPreviewActive = UiPreview::isActive($user, 'series-catalog');
$seriesBilingualExperiment = (string)($_GET['bilingual_experiment'] ?? '') === '1';

ArtworkSeries::ensureSchema($pdo);
(new ArtworkGroupService($pdo))->syncUser($userId);
ArtworkSeries::syncUser($pdo, $userId);

if (empty($_SESSION['series_csrf'])) {
    $_SESSION['series_csrf'] = bin2hex(random_bytes(24));
}

$notice = '';
$error = '';

function series_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function series_media_url(?string $file, int $width = 360): string
{
    $file = basename((string)$file);
    return $file !== '' ? 'media.php?file=' . rawurlencode($file) . '&thumb=1&w=' . max(240, min(900, $width)) : '';
}

function series_year_select(string $name, mixed $selected): string
{
    $selected = $selected !== null && $selected !== '' ? (int)$selected : null;
    $currentYear = (int)date('Y');
    $html = '<select name="' . series_h($name) . '"><option value="">—</option>';
    for ($year = $currentYear + 1; $year >= ArtworkSeries::YEAR_RANGE_START; $year--) {
        $html .= '<option value="' . $year . '"' . ($selected === $year ? ' selected' : '') . '>' . $year . '</option>';
    }
    return $html . '</select>';
}

function series_year_range_label(mixed $start, mixed $end): string
{
    $start = $start !== null && $start !== '' ? (int)$start : null;
    $end = $end !== null && $end !== '' ? (int)$end : null;
    if ($start === null && $end === null) return '';
    if ($start !== null && $end !== null) return ($start === $end ? (string)$start : "$start–$end") . ' · ';
    if ($start !== null) return "{$start}–Present · ";
    return (string)$end . ' · ';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['series_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid request.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_series') {
            $title = ArtworkSeries::normalizeTitle((string)($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Series title is required.');
            }
            ArtworkSeries::getOrCreate($pdo, $userId, $title, trim((string)($_POST['description'] ?? '')));
            $notice = 'Series created.';
        } elseif ($action === 'update_series') {
            ArtworkSeries::updateContent($pdo, $userId, (int)($_POST['series_id'] ?? 0), [
                'title' => (string)($_POST['title'] ?? ''),
                'subtitle' => (string)($_POST['subtitle'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'long_description' => (string)($_POST['long_description'] ?? ''),
                'tags' => (string)($_POST['tags'] ?? ''),
                'keywords' => (string)($_POST['keywords'] ?? ''),
                'seo_description' => (string)($_POST['seo_description'] ?? ''),
                'slug' => (string)($_POST['slug'] ?? ''),
                'year_start' => (string)($_POST['year_start'] ?? ''),
                'year_end' => (string)($_POST['year_end'] ?? ''),
            ]);
            $notice = 'Series updated.';
        } elseif ($action === 'set_series_header') {
            ArtworkSeries::setHeader($pdo, $userId, (int)($_POST['series_id'] ?? 0), (string)($_POST['file'] ?? ''));
            $notice = 'Series header image updated.';
        } elseif ($action === 'upload_series_header') {
            ArtworkSeries::uploadHeader($pdo, $userId, (int)($_POST['series_id'] ?? 0), $_FILES['header_upload'] ?? []);
            $notice = 'Series header image uploaded.';
        } elseif ($action === 'set_series_header_framing') {
            ArtworkSeries::setHeaderFraming(
                $pdo, $userId, (int)($_POST['series_id'] ?? 0),
                (float)($_POST['focal_x'] ?? 50), (float)($_POST['focal_y'] ?? 50), (float)($_POST['zoom'] ?? 115)
            );
            $notice = 'Header framing updated.';
        } elseif ($action === 'publish_series') {
            ArtworkSeries::setPublished($pdo, $userId, (int)($_POST['series_id'] ?? 0), true);
            $notice = 'Series published to the website.';
        } elseif ($action === 'unpublish_series') {
            ArtworkSeries::setPublished($pdo, $userId, (int)($_POST['series_id'] ?? 0), false);
            $notice = 'Series removed from the website.';
        } elseif ($action === 'delete_series') {
            ArtworkSeries::deleteSeries($pdo, $userId, (int)($_POST['series_id'] ?? 0));
            $notice = 'Series removed. Artworks moved to NO SERIE.';
        } elseif ($action === 'assign_artwork') {
            $rawSeriesId = trim((string)($_POST['series_id'] ?? ''));
            ArtworkSeries::assignArtwork($pdo, $userId, (int)($_POST['artwork_id'] ?? 0), $rawSeriesId === '' ? null : (int)$rawSeriesId);
            $notice = 'Artwork series updated.';
        } elseif ($action === 'set_creation_number') {
            ArtworkSeries::setCreationNumber(
                $pdo,
                $userId,
                (int)($_POST['artwork_id'] ?? 0),
                (int)($_POST['creation_number'] ?? 0)
            );
            $notice = 'Artwork Creation ID updated.';
        }

        ArtworkSeries::syncUser($pdo, $userId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$seriesRows = ArtworkSeries::seriesList($pdo, $userId);

$artworkStmt = $pdo->prepare("
    SELECT a.id, g.id AS artwork_group_id, a.final_title, sh.title AS sheet_title, a.subtitle, a.root_file, a.main_file, a.width, a.height, a.unit,
           a.series_id, a.series, a.series_creation_number,
           s.title AS series_title,
           (
               SELECT COUNT(DISTINCT m.id)
               FROM mockups m
               WHERE m.user_id = g.user_id
               AND m.artwork_group_id = g.id
           ) AS mockup_count
    FROM artwork_groups g
    INNER JOIN artworks a ON a.id = g.canonical_artwork_id AND a.user_id = g.user_id
    LEFT JOIN artwork_series s ON s.id = a.series_id AND s.user_id = a.user_id
    LEFT JOIN artwork_sheets sh ON sh.id = (
        SELECT sh2.id
        FROM artwork_sheets sh2
        WHERE sh2.canonical_artwork_id = a.id
        AND sh2.user_id = a.user_id
        AND COALESCE(sh2.status, '') <> 'merged'
        ORDER BY sh2.id DESC
        LIMIT 1
    )
    WHERE g.user_id = ? AND g.status = 'active' AND a.status = ?
    ORDER BY
        CASE WHEN a.series_id IS NULL THEN 1 ELSE 0 END ASC,
        CASE WHEN s.year_start IS NULL AND s.year_end IS NULL THEN 1 ELSE 0 END ASC,
        COALESCE(s.year_start, s.year_end) DESC,
        COALESCE(s.year_end, s.year_start) DESC,
        s.created_at DESC,
        s.id DESC,
        CASE WHEN a.series_creation_number IS NULL THEN 1 ELSE 0 END ASC,
        a.series_creation_number DESC,
        g.created_at DESC,
        a.id DESC
");
$artworkStmt->execute([$userId, 'done']);
$artworks = $artworkStmt->fetchAll(PDO::FETCH_ASSOC);

function series_artwork_title(array $artwork): string
{
    return trim((string)($artwork['sheet_title'] ?: '')) ?: (trim((string)($artwork['final_title'] ?: '')) ?: 'Untitled');
}

function series_tone(int $index): string
{
    $tones = ['artwork_launch', 'series_launch', 'available_catalog', 'symbolism', 'sold_constellation', 'studio_process', 'refresh'];
    return $tones[$index % count($tones)];
}

$seriesToneById = [];
foreach ($seriesRows as $index => $seriesRow) {
    $seriesToneById[(int)$seriesRow['id']] = series_tone((int)$index);
}

$selectedSeriesId = max(0, (int)($_GET['series'] ?? 0));
$selectedSeries = null;
foreach ($seriesRows as $series) {
    if ((int)$series['id'] === $selectedSeriesId) { $selectedSeries = $series; break; }
}
$seriesMockupCandidates = $selectedSeries ? ArtworkSeries::searchMockups($pdo, $userId, '') : [];
$displayedArtworks = $selectedSeries
    ? array_values(array_filter(
        $artworks,
        static fn(array $artwork): bool => (int)($artwork['series_id'] ?? 0) === (int)$selectedSeries['id']
    ))
    : $artworks;
$seriesEditorialFields = $selectedSeries ? [
    ['es' => 'Subtítulo', 'en' => 'Subtitle', 'value' => (string)($selectedSeries['subtitle'] ?? ''), 'large' => false, 'es_placeholder' => 'Subtítulo editorial de la serie…', 'en_placeholder' => 'No English subtitle is currently available.'],
    ['es' => 'Descripción breve', 'en' => 'Short description', 'value' => (string)($selectedSeries['description'] ?? ''), 'large' => false, 'es_placeholder' => 'Una o dos frases para presentar la serie…', 'en_placeholder' => 'No English short description is currently available.'],
    ['es' => 'Texto curatorial', 'en' => 'Long description', 'value' => (string)($selectedSeries['long_description'] ?? ''), 'large' => true, 'es_placeholder' => 'Escribí el texto curatorial completo de la serie…', 'en_placeholder' => 'No English long description is currently available.'],
    ['es' => 'Etiquetas', 'en' => 'Tags', 'value' => (string)($selectedSeries['tags'] ?? ''), 'large' => false, 'es_placeholder' => 'Etiquetas editoriales…', 'en_placeholder' => 'No English tags are currently available.'],
    ['es' => 'Términos de búsqueda', 'en' => 'Long-tail keywords', 'value' => (string)($selectedSeries['keywords'] ?? ''), 'large' => false, 'es_placeholder' => 'Frases de búsqueda en español…', 'en_placeholder' => 'No English search terms are currently available.'],
    ['es' => 'Descripción SEO', 'en' => 'SEO description', 'value' => (string)($selectedSeries['seo_description'] ?? ''), 'large' => false, 'es_placeholder' => 'Descripción breve para buscadores…', 'en_placeholder' => 'No English SEO description is currently available.'],
] : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Series - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css?v=17">
    <?php if ($seriesPreviewActive): ?>
        <link rel="stylesheet" href="visual-consistency-preview.css?v=2">
    <?php endif; ?>
    <style>
        .series-bilingual-title {
            display:block;
            width:100%;
            box-sizing:border-box;
            padding:18px 20px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .series-bilingual-label {
            display:block;
            margin:0 0 15px;
            color:var(--muted);
            font-size:9px;
            font-weight:700;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .series-bilingual-heading {
            margin:0;
            padding:0 0 14px;
            border-bottom:1px solid var(--line);
            color:var(--ink);
            font:500 clamp(42px,4.5vw,58px)/1.05 var(--font-serif);
            letter-spacing:-.01em;
            overflow-wrap:anywhere;
        }

        .series-bilingual-heading:focus { outline:0; }

        .series-bilingual-title-memo {
            margin:15px 0 0;
            color:var(--accent);
            font:italic 500 21px/1.5 var(--font-serif);
        }

        .series-bilingual-editorial {
            margin-top:18px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .series-bilingual-editorial > summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            padding:18px 20px;
            cursor:pointer;
            list-style:none;
        }

        .series-bilingual-editorial > summary::-webkit-details-marker { display:none; }
        .series-bilingual-summary strong { display:block; color:var(--ink); font:500 23px/1.1 var(--font-serif); }
        .series-bilingual-summary span { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .series-bilingual-state { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
        .series-bilingual-state::after { content:'+'; display:inline-block; margin-left:14px; color:var(--accent); font:500 22px/1 var(--font-serif); vertical-align:-2px; }
        .series-bilingual-editorial[open] .series-bilingual-state::after { content:'−'; }

        .series-bilingual-spread {
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            grid-template-rows:auto repeat(6,auto);
            column-gap:12px;
            row-gap:0;
            padding:14px;
            border-top:1px solid var(--line);
        }

        .series-bilingual-page {
            display:grid;
            grid-row:1 / span 7;
            grid-template-rows:subgrid;
            min-width:0;
            padding:18px;
            border:1px solid var(--line);
            border-top:3px solid #c89aa1;
            background:var(--surface-soft);
        }

        .series-bilingual-page--source { grid-column:1; }
        .series-bilingual-page--english { grid-column:2; border-top-color:#9fb19a; }
        .series-bilingual-language { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .series-bilingual-field { min-height:96px; margin-top:16px; padding-top:13px; border-top:1px solid var(--line); }
        .series-bilingual-field--large { min-height:250px; }
        .series-bilingual-field label { display:block; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .series-bilingual-copy { min-height:62px; margin-top:10px; color:var(--ink); font-size:14px; line-height:1.65; white-space:pre-wrap; }
        .series-bilingual-field--large .series-bilingual-copy { min-height:210px; }
        .series-bilingual-copy:empty::before { content:attr(data-placeholder); color:var(--muted); font-style:italic; }
        .series-bilingual-copy:focus { outline:0; }
        .series-bilingual-memo { margin:0 14px 14px; padding:14px 6px 2px; border-top:1px solid var(--line); }
        .series-bilingual-memo summary { cursor:pointer; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .series-bilingual-memo .series-bilingual-copy { min-height:82px; }

        @media (max-width:800px) {
            .series-bilingual-spread { grid-template-columns:1fr; grid-template-rows:none; }
            .series-bilingual-page { display:block; grid-column:auto; grid-row:auto; }
        }
    </style>
</head>
<body class="series-page<?= $seriesPreviewActive ? ' ui-visual-consistency-preview' : '' ?>"<?= $seriesPreviewActive ? ' data-ui-preview="series-catalog"' : '' ?>>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= series_h($user['email']) ?></a></header>
        <div class="series-catalog">
            <?php if ($seriesPreviewActive): ?>
                <aside class="ui-preview-notice" aria-label="Visual consistency preview">
                    <span><strong>Preview</strong> Series workspace</span>
                    <a href="series.php<?= $selectedSeries ? '?series=' . (int)$selectedSeries['id'] : '' ?>">Exit preview</a>
                </aside>
            <?php endif; ?>
            <?php if (!$selectedSeries): ?>
            <div class="catalog-heading">
                <div>
                    <h1>Series</h1>
                    <p>Group artworks and mockups by series. NO SERIE stays silent in public titles.</p>
                </div>
            </div>
            <?php elseif ($seriesBilingualExperiment): ?>
            <div class="series-bilingual-title" aria-label="Título universal de la serie">
                <span class="series-bilingual-label">Título universal</span>
                <h1 class="series-bilingual-heading" contenteditable="true" role="textbox" aria-label="Título de la serie"><?= series_h($selectedSeries['title']) ?></h1>
                <p class="series-bilingual-title-memo">STRATA — LIMEN · SERIES X — NUHRĀ (ܢܘܗܪܐ) · no traducir</p>
            </div>
            <details class="series-bilingual-editorial">
                <summary>
                    <span class="series-bilingual-summary">
                        <strong>Espacio editorial</strong>
                        <span>Contenido original en español y versión publicada en inglés.</span>
                    </span>
                    <span class="series-bilingual-state">Español + English</span>
                </summary>
                <div class="series-bilingual-spread">
                    <article class="series-bilingual-page series-bilingual-page--source">
                        <span class="series-bilingual-language">Español · fuente</span>
                        <?php foreach ($seriesEditorialFields as $field): ?>
                            <section class="series-bilingual-field <?= $field['large'] ? 'series-bilingual-field--large' : '' ?>">
                                <label><?= series_h($field['es']) ?></label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?= series_h($field['es_placeholder']) ?>"></div>
                            </section>
                        <?php endforeach; ?>
                    </article>
                    <article class="series-bilingual-page series-bilingual-page--english">
                        <span class="series-bilingual-language">English · current version</span>
                        <?php foreach ($seriesEditorialFields as $field): ?>
                            <section class="series-bilingual-field <?= $field['large'] ? 'series-bilingual-field--large' : '' ?>">
                                <label><?= series_h($field['en']) ?></label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?= series_h($field['en_placeholder']) ?>"><?= series_h($field['value']) ?></div>
                            </section>
                        <?php endforeach; ?>
                    </article>
                </div>
                <details class="series-bilingual-memo">
                    <summary>Memo privado de la serie</summary>
                    <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="Ideas, decisiones y evolución conceptual de la serie…"></div>
                </details>
            </details>
            <?php else:
                $seriesMissing = ArtworkSeries::missingForPublish($selectedSeries);
                $seriesYearInline = trim(series_year_range_label($selectedSeries['year_start'] ?? null, $selectedSeries['year_end'] ?? null), " \xC2\xB7");
            ?>
            <div class="catalog-heading series-detail-heading">
                <div class="series-detail-heading__copy">
                    <div class="series-detail-title-row">
                        <h1><span class="series-title-label">Series</span><span class="series-title-name"><?= series_h($selectedSeries['title']) ?></span></h1>
                        <span class="status-pill <?= !empty($selectedSeries['published']) ? 'status-published' : 'status-pending' ?>">
                            <?= !empty($selectedSeries['published']) ? 'Published' : 'Draft' ?>
                        </span>
                    </div>
                    <p class="series-detail-summary">
                        <?php if (trim((string)($selectedSeries['subtitle'] ?? '')) !== ''): ?><strong><?= series_h($selectedSeries['subtitle']) ?></strong><span aria-hidden="true">·</span><?php endif; ?>
                        <?php if ($seriesYearInline !== ''): ?><span><?= series_h($seriesYearInline) ?></span><span aria-hidden="true">·</span><?php endif; ?>
                        <span><?= (int)$selectedSeries['artwork_count'] ?> artworks</span><span aria-hidden="true">·</span><span><?= (int)$selectedSeries['mockup_count'] ?> mockups</span>
                    </p>
                </div>
                <div class="catalog-heading__actions series-detail-actions">
                    <a class="series-create-art-decision" href="create_scenes.php?series=<?= (int)$selectedSeries['id'] ?>"><span>Create Art</span></a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?><div class="notice-card notice-ok"><?= series_h($notice) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice-card notice-error"><?= series_h($error) ?></div><?php endif; ?>

            <?php if (!$seriesBilingualExperiment || !$selectedSeries): ?>
            <section class="catalog-panel catalog-panel--compact catalog-panel--series-picker">
                <?php if ($selectedSeries && $seriesMissing): ?>
                    <div class="warning-list" style="margin-bottom: 20px;">Complete before publishing: <?= series_h(implode(' · ', $seriesMissing)) ?></div>
                <?php endif; ?>
                <?php if (!$selectedSeries): ?>
                    <div class="social-square-grid">
                        <?php foreach ($seriesRows as $index => $series): ?>
                            <?php $seriesArtworkCount = (int)($series['artwork_count'] ?? 0); ?>
                            <div class="series-series-option">
                                <a class="social-square-button series-series-tile social-square-button--<?= series_tone($index) ?>" href="series.php?series=<?= (int)$series['id'] ?><?= $seriesPreviewActive ? '&amp;design_preview=series-catalog' : '' ?>" data-series-filter-trigger data-series-filter-id="<?= (int)$series['id'] ?>"<?= !empty($series['header_file']) ? ' style="--series-tile-image: url(\'' . series_h(series_media_url($series['header_file'], 420)) . '\'); --series-tile-position: ' . (int)($series['header_focal_x'] ?? 50) . '% ' . (int)($series['header_focal_y'] ?? 50) . '%;"' : '' ?> aria-label="<?= series_h($series['title']) ?>, <?= $seriesArtworkCount ?> <?= $seriesArtworkCount === 1 ? 'artwork' : 'artworks' ?>, <?= !empty($series['published']) ? 'published' : 'draft' ?>">
                                    <span class="series-series-tile__title"><?= series_h($series['title']) ?></span>
                                    <small class="series-series-tile__meta">
                                        <?= $seriesArtworkCount > 0 ? $seriesArtworkCount . ' ' . ($seriesArtworkCount === 1 ? 'artwork' : 'artworks') : 'No artworks' ?>
                                        · <?= !empty($series['published']) ? 'Published' : 'Draft' ?>
                                    </small>
                                </a>
                                <a class="series-series-option__edit" href="series.php?series=<?= (int)$series['id'] ?><?= $seriesPreviewActive ? '&amp;design_preview=series-catalog' : '' ?>" aria-label="Edit <?= series_h($series['title']) ?>" title="Edit series">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11-4-4L4 16v4Zm9.7-13.7 4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <details class="series-create-toggle">
                            <summary class="social-square-button social-square-button--new" aria-label="New series"><span>+</span></summary>
                            <form class="series-create-form" method="post">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="create_series">
                                <input type="text" name="title" placeholder="Series title" required>
                                <input type="text" name="description" placeholder="Short internal description">
                                <button type="submit">Create</button>
                            </form>
                        </details>
                    </div>
                <?php else: $series = $selectedSeries; ?>
                    <div class="series-grid series-grid--detailed">
                        <div class="series-header-picker">
                            <?php if (!empty($series['header_file'])): ?>
                                <div class="series-header-framing">
                                    <div class="series-header-framing__stage" id="series-framing-stage"
                                         data-focal-x="<?= (int)($series['header_focal_x'] ?? 50) ?>"
                                         data-focal-y="<?= (int)($series['header_focal_y'] ?? 50) ?>"
                                         data-zoom="<?= (int)($series['header_zoom'] ?? 115) ?>">
                                        <img src="<?= series_h(series_media_url($series['header_file'], 900)) ?>" alt="Header preview" id="series-framing-img"
                                             style="object-position: <?= (int)($series['header_focal_x'] ?? 50) ?>% <?= (int)($series['header_focal_y'] ?? 50) ?>%; transform: scale(<?= ((int)($series['header_zoom'] ?? 115)) / 100 ?>);">
                                    </div>
                                    <p class="series-header-framing__hint">Drag on the image to reposition it. Use the slider to zoom. <span>(this crop only affects the website, not the admin previews)</span></p>
                                    <label class="series-header-framing__zoom">Zoom<input type="range" id="series-framing-zoom" min="115" max="400" value="<?= (int)($series['header_zoom'] ?? 115) ?>"></label>
                                    <form method="post" id="series-framing-form">
                                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                        <input type="hidden" name="action" value="set_series_header_framing">
                                        <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                        <input type="hidden" name="focal_x" id="series-framing-focal-x" value="<?= (int)($series['header_focal_x'] ?? 50) ?>">
                                        <input type="hidden" name="focal_y" id="series-framing-focal-y" value="<?= (int)($series['header_focal_y'] ?? 50) ?>">
                                        <input type="hidden" name="zoom" id="series-framing-zoom-value" value="<?= (int)($series['header_zoom'] ?? 115) ?>">
                                        <button class="button-link secondary" type="submit">Save framing</button>
                                    </form>
                                </div>
                                <script>
                                (function () {
                                    var stage = document.getElementById('series-framing-stage');
                                    var img = document.getElementById('series-framing-img');
                                    var zoomInput = document.getElementById('series-framing-zoom');
                                    var focalXField = document.getElementById('series-framing-focal-x');
                                    var focalYField = document.getElementById('series-framing-focal-y');
                                    var zoomField = document.getElementById('series-framing-zoom-value');
                                    if (!stage || !img) return;
                                    var focalX = parseInt(stage.dataset.focalX, 10) || 50;
                                    var focalY = parseInt(stage.dataset.focalY, 10) || 50;
                                    var zoom = parseInt(stage.dataset.zoom, 10) || 115;
                                    function useNaturalRatio() {
                                        if (!img.naturalWidth || !img.naturalHeight) return;
                                        stage.style.setProperty('--series-header-ratio', img.naturalWidth + ' / ' + img.naturalHeight);
                                        stage.classList.toggle('is-landscape', img.naturalWidth > img.naturalHeight);
                                    }
                                    function apply() {
                                        img.style.objectPosition = focalX + '% ' + focalY + '%';
                                        img.style.transform = 'scale(' + (zoom / 100) + ')';
                                        focalXField.value = focalX;
                                        focalYField.value = focalY;
                                        zoomField.value = zoom;
                                    }
                                    if (img.complete) useNaturalRatio();
                                    img.addEventListener('load', useNaturalRatio);
                                    zoomInput.addEventListener('input', function () { zoom = parseInt(this.value, 10); apply(); });
                                    var dragging = false;
                                    stage.addEventListener('mousedown', function () { dragging = true; });
                                    window.addEventListener('mouseup', function () { dragging = false; });
                                    stage.addEventListener('mousemove', function (event) {
                                        if (!dragging) return;
                                        var rect = stage.getBoundingClientRect();
                                        focalX = Math.max(0, Math.min(100, Math.round(((event.clientX - rect.left) / rect.width) * 100)));
                                        focalY = Math.max(0, Math.min(100, Math.round(((event.clientY - rect.top) / rect.height) * 100)));
                                        apply();
                                    });
                                })();
                                </script>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data" class="series-header-upload__form <?= empty($series['header_file']) ? 'series-header-upload__form--empty' : 'series-header-upload__form--replace' ?>" data-series-header-upload>
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="upload_series_header">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                <label class="<?= empty($series['header_file']) ? 'series-header-empty' : 'series-header-replace' ?>" data-series-header-dropzone tabindex="0" role="button">
                                    <span data-series-header-label><?= empty($series['header_file']) ? 'Upload header image' : 'Replace header image' ?></span>
                                    <?php if (empty($series['header_file'])): ?><small>Click or drop a JPG, PNG or WebP · 15 MB maximum</small><?php endif; ?>
                                    <input class="series-header-upload__input" type="file" name="header_upload" accept="image/png,image/jpeg,image/webp" required data-series-header-file>
                                </label>
                                <span class="series-header-upload__status" data-series-header-status aria-live="polite"></span>
                            </form>

                            <details class="series-header-mockups">
                                <summary>Browse generated mockups</summary>
                                <?php if (!$seriesMockupCandidates): ?>
                                    <p class="favorite-empty">No mockups have been generated yet.</p>
                                <?php else: ?>
                                    <div class="series-header-grid">
                                        <?php foreach ($seriesMockupCandidates as $mockupCandidate): ?>
                                            <div class="pin-image">
                                                <img src="<?= series_h(series_media_url($mockupCandidate['file'], 420)) ?>" alt="<?= series_h($mockupCandidate['title'] ?: 'Mockup') ?>">
                                                <form class="header-pin-form" method="post">
                                                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                                    <input type="hidden" name="action" value="set_series_header">
                                                    <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                                    <input type="hidden" name="file" value="<?= series_h($mockupCandidate['file']) ?>">
                                                    <button class="header-pin <?= ($series['header_file'] ?? '') === $mockupCandidate['file'] ? 'is-active' : '' ?>" name="submit_header" title="Set as series header" aria-label="Set as series header">
                                                        <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>

                            <script>
                            (function () {
                                var form = document.querySelector('[data-series-header-upload]');
                                var input = form && form.querySelector('[data-series-header-file]');
                                var dropzone = form && form.querySelector('[data-series-header-dropzone]');
                                var label = form && form.querySelector('[data-series-header-label]');
                                var status = form && form.querySelector('[data-series-header-status]');
                                if (!form || !input || !dropzone || !label) return;

                                function beginUpload(file, fromDrop) {
                                    if (!file) return;
                                    if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) {
                                        if (status) status.textContent = 'Choose a JPG, PNG or WebP image.';
                                        return;
                                    }
                                    if (file.size > 15 * 1024 * 1024) {
                                        if (status) status.textContent = 'The image must be 15 MB or smaller.';
                                        return;
                                    }
                                    if (fromDrop) {
                                        try {
                                            var transfer = new DataTransfer();
                                            transfer.items.add(file);
                                            input.files = transfer.files;
                                        } catch (error) {
                                            if (status) status.textContent = 'Click the image area to select this file.';
                                            return;
                                        }
                                    }
                                    form.setAttribute('aria-busy', 'true');
                                    dropzone.classList.remove('is-dragging');
                                    label.textContent = 'Uploading…';
                                    if (status) status.textContent = file.name;
                                    form.requestSubmit();
                                }

                                input.addEventListener('change', function () {
                                    beginUpload(input.files && input.files[0], false);
                                });
                                dropzone.addEventListener('keydown', function (event) {
                                    if (event.key !== 'Enter' && event.key !== ' ') return;
                                    event.preventDefault();
                                    input.click();
                                });
                                ['dragenter', 'dragover'].forEach(function (eventName) {
                                    dropzone.addEventListener(eventName, function (event) {
                                        event.preventDefault();
                                        dropzone.classList.add('is-dragging');
                                    });
                                });
                                dropzone.addEventListener('dragleave', function () {
                                    dropzone.classList.remove('is-dragging');
                                });
                                dropzone.addEventListener('drop', function (event) {
                                    event.preventDefault();
                                    beginUpload(event.dataTransfer && event.dataTransfer.files[0], true);
                                });
                            }());
                            </script>
                        </div>
                        <article class="series-card series-card--detailed">
                            <form class="series-delete-form" method="post" id="delete-series-form" onsubmit="return confirm('Remove this series? Artworks will move to NO SERIE.');" style="display:none;">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="delete_series">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                            </form>

                            <form method="post" class="catalog-edit-form">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="update_series">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                
                                <div class="catalog-edit-form__row">
                                    <label>Title<input type="text" name="title" value="<?= series_h($series['title']) ?>" required></label>
                                    <label>Subtitle<input type="text" name="subtitle" value="<?= series_h($series['subtitle'] ?? '') ?>" placeholder="Short tagline shown with the title"></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label>Years from<?= series_year_select('year_start', $series['year_start'] ?? null) ?></label>
                                    <label>Years to<?= series_year_select('year_end', $series['year_end'] ?? null) ?></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label>URL Slug<input type="text" name="slug" value="<?= series_h($series['slug']) ?>" placeholder="auto-generated-from-title"></label>
                                    <label>Tags<input type="text" name="tags" value="<?= series_h($series['tags'] ?? '') ?>" placeholder="Comma separated, e.g. abstract, painting"></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label>Short Description<textarea name="description" rows="3" placeholder="One or two sentences used in previews and cards"><?= series_h($series['description'] ?? '') ?></textarea></label>
                                    <label>SEO Meta Description<textarea name="seo_description" rows="3" placeholder="Meta description shown in search results"><?= series_h($series['seo_description'] ?? '') ?></textarea></label>
                                </div>

                                <label>Long Description<textarea name="long_description" rows="8" placeholder="Full curatorial text for the series page"><?= series_h($series['long_description'] ?? '') ?></textarea></label>
                                <label>Long-Tail Keywords<textarea name="keywords" rows="2" placeholder="Comma separated, e.g. structural abstract painting large scale"><?= series_h($series['keywords'] ?? '') ?></textarea></label>

                                <p><?= series_h(series_year_range_label($series['year_start'] ?? null, $series['year_end'] ?? null)) ?><?= (int)$series['artwork_count'] ?> artworks · <?= (int)$series['mockup_count'] ?> mockups</p>
                                
                                <div class="catalog-edit-form__actions">
                                    <button class="button-link" type="submit">Save changes</button>
                                    <button class="button-link secondary danger" type="submit" form="delete-series-form">Delete series</button>
                                </div>
                            </form>
                        </article>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section class="catalog-panel catalog-panel--compact catalog-panel--series-artworks" id="artwork-assignment">
                <div class="detail-heading">
                    <div class="series-artwork-heading-copy">
                        <h2><?= $selectedSeries ? 'Works in this series' : 'Artwork assignment' ?></h2>
                        <?php if ($selectedSeries): ?>
                            <p><?= count($displayedArtworks) ?> <?= count($displayedArtworks) === 1 ? 'artwork belongs' : 'artworks belong' ?> to <?= series_h($selectedSeries['title']) ?>. Drag the images to change their order; changing a series saves immediately.</p>
                        <?php else: ?>
                            <p>Assign each canonical artwork to its series. All root views and mockups inherit the same relationship.</p>
                        <?php endif; ?>
                    </div>
                    <div class="series-artwork-heading-tools">
                        <?php if (!$selectedSeries && $displayedArtworks): ?>
                            <label class="series-artwork-filter">
                                <span>View by series</span>
                                <select data-series-artwork-filter aria-label="Filter artwork assignment by series">
                                    <option value="all">All series</option>
                                    <?php foreach ($seriesRows as $series): ?>
                                        <option value="<?= (int)$series['id'] ?>"><?= series_h($series['title']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="none">No series</option>
                                </select>
                            </label>
                        <?php endif; ?>
                        <span class="series-dependent-count" data-series-visible-count><?= count($displayedArtworks) ?> <?= count($displayedArtworks) === 1 ? 'artwork' : 'artworks' ?></span>
                    </div>
                </div>
                <p class="series-mobile-order-hint" data-series-order-hint<?= $selectedSeries ? '' : ' hidden' ?>>Hold an artwork image to change its order.</p>
                <?php if (!$displayedArtworks): ?>
                    <div class="empty-state series-dependent-empty">
                        <strong>No artworks are associated with <?= $selectedSeries ? series_h($selectedSeries['title']) : 'a series' ?> yet.</strong>
                        <?php if ($selectedSeries): ?><a href="series.php">Assign artworks from the Series overview</a><?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="series-artwork-list" data-series-order-list<?= !$selectedSeries ? ' data-series-filter-controlled="true"' : '' ?> data-series-order-endpoint="reorder_series_artworks.php" data-series-order-csrf="<?= series_h($_SESSION['series_csrf']) ?>">
                        <?php $seriesOrderCounters = []; ?>
                        <?php foreach ($displayedArtworks as $artwork): ?>
                            <?php
                            $title = series_artwork_title($artwork);
                            $seriesTitle = ArtworkSeries::display((string)($artwork['series_title'] ?: $artwork['series']));
                            $cardSeriesId = (int)($artwork['series_id'] ?? 0);
                            $orderPosition = 0;
                            if ($cardSeriesId > 0) {
                                $seriesOrderCounters[$cardSeriesId] = ($seriesOrderCounters[$cardSeriesId] ?? 0) + 1;
                                $orderPosition = $seriesOrderCounters[$cardSeriesId];
                            }
                            $cardSeriesTone = $seriesToneById[$cardSeriesId] ?? '';
                            $file = (string)($artwork['root_file'] ?: $artwork['main_file']);
                            $size = trim((string)($artwork['width'] ?? '')) !== '' && trim((string)($artwork['height'] ?? '')) !== ''
                                ? trim((string)$artwork['width']) . ' x ' . trim((string)$artwork['height']) . ' ' . (trim((string)($artwork['unit'] ?? 'cm')) ?: 'cm')
                                : '';
                            ?>
                            <article class="series-artwork-row<?= $cardSeriesTone !== '' ? ' series-artwork-row--' . series_h($cardSeriesTone) : '' ?>" data-series-artwork-id="<?= (int)$artwork['id'] ?>" data-series-id="<?= $cardSeriesId ?>">
                                <a class="series-artwork-thumb" data-series-drag-thumb href="artwork_details.php?id=<?= (int)$artwork['id'] ?>">
                                    <img src="<?= series_h(series_media_url($file, 420)) ?>" alt="<?= series_h($title) ?>" loading="lazy" draggable="false">
                                    <?php if ($orderPosition > 0): ?><span class="series-artwork-order" data-series-order-position><?= str_pad((string)$orderPosition, 2, '0', STR_PAD_LEFT) ?></span><?php endif; ?>
                                </a>
                                <div class="series-artwork-main">
                                    <h3><?= series_h($title) ?></h3>
                                    <?php if ($seriesTitle !== ''): ?><p class="series-artwork-series"><?= series_h($seriesTitle) ?></p><?php endif; ?>
                                    <p class="series-artwork-meta">
                                        <span><?= $size !== '' ? series_h($size) . ' · ' : '' ?><?= (int)$artwork['mockup_count'] ?> mockups</span>
                                    </p>
                                </div>
                                <div class="series-artwork-controls">
                                    <form class="series-assign-form" method="post">
                                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                        <input type="hidden" name="action" value="assign_artwork">
                                        <input type="hidden" name="artwork_id" value="<?= (int)$artwork['id'] ?>">
                                        <select name="series_id" aria-label="Artwork series" onchange="this.form.requestSubmit()">
                                            <option value="">NO SERIE</option>
                                            <?php foreach ($seriesRows as $series): ?>
                                                <option value="<?= (int)$series['id'] ?>" <?= (int)($artwork['series_id'] ?? 0) === (int)$series['id'] ? 'selected' : '' ?>><?= series_h($series['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$selectedSeries): ?>
                        <div class="empty-state series-dependent-empty series-filter-empty" data-series-filter-empty hidden>
                            <strong>No artworks match this series.</strong>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="series_artwork_order.js?v=20260720-4"></script>
</body>
</html>
