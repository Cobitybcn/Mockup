<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
Auth::start();
$userId = (int)$user['id'];

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
        a.series_creation_number ASC,
        g.created_at ASC,
        a.id ASC
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

$selectedSeriesId = max(0, (int)($_GET['series'] ?? 0));
$selectedSeries = null;
foreach ($seriesRows as $series) {
    if ((int)$series['id'] === $selectedSeriesId) { $selectedSeries = $series; break; }
}
$seriesMockupCandidates = $selectedSeries ? ArtworkSeries::searchMockups($pdo, $userId, '') : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Series - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= series_h($user['email']) ?></a></header>
        <div class="series-catalog">
            <?php if (!$selectedSeries): ?>
            <div class="catalog-heading">
                <div>
                    <h1>Series</h1>
                    <p>Group artworks and mockups by series. NO SERIE stays silent in public titles.</p>
                </div>
            </div>
            <?php else:
                $seriesMissing = ArtworkSeries::missingForPublish($selectedSeries);
                $seriesYearInline = trim(series_year_range_label($selectedSeries['year_start'] ?? null, $selectedSeries['year_end'] ?? null), " \xC2\xB7");
            ?>
            <div class="catalog-heading">
                <div>
                    <h1>
                        <span class="series-kicker">Serie:</span>
                        <?= series_h($selectedSeries['title']) ?>
                        <span class="status-pill <?= !empty($selectedSeries['published']) ? 'status-published' : 'status-pending' ?>">
                            <?= !empty($selectedSeries['published']) ? 'Published' : 'Draft' ?>
                        </span>
                    </h1>
                    <p>
                        <?php if (trim((string)($selectedSeries['subtitle'] ?? '')) !== ''): ?>
                            <span style="font-weight: 500; color: var(--ink);"><?= series_h($selectedSeries['subtitle']) ?></span>
                            <?php if ($seriesYearInline !== '' || (int)$selectedSeries['artwork_count'] > 0 || (int)$selectedSeries['mockup_count'] > 0): ?> · <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($seriesYearInline !== ''): ?>
                            <?= series_h($seriesYearInline) ?> · 
                        <?php endif; ?>
                        <?= (int)$selectedSeries['artwork_count'] ?> artworks · <?= (int)$selectedSeries['mockup_count'] ?> mockups
                    </p>
                </div>
                <div class="catalog-heading__actions">
                    <?php if (!empty($selectedSeries['published'])): ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                            <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                            <button class="button-link secondary" name="action" value="unpublish_series">Unpublish</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="margin:0;">
                            <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                            <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                            <button class="button-link" name="action" value="publish_series" <?= $seriesMissing ? 'disabled' : '' ?>>Publish</button>
                        </form>
                    <?php endif; ?>
                    <a class="button-link secondary" href="series.php">Back to series</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?><div class="notice-card notice-ok"><?= series_h($notice) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice-card notice-error"><?= series_h($error) ?></div><?php endif; ?>

            <section class="catalog-panel catalog-panel--compact">
                <?php if (!$selectedSeries): ?>
                <div class="detail-heading">
                    <div>
                        <h2>Series</h2>
                        <p>Open a series to edit it, or add a new one.</p>
                    </div>
                </div>
                <?php else: ?>
                    <?php if ($seriesMissing): ?>
                        <div class="warning-list" style="margin-bottom: 20px;">Complete before publishing: <?= series_h(implode(' · ', $seriesMissing)) ?></div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!$selectedSeries): ?>
                    <div class="social-square-grid">
                        <?php foreach ($seriesRows as $index => $series): ?>
                            <a class="social-square-button social-square-button--<?= series_tone($index) ?>" href="series.php?series=<?= (int)$series['id'] ?>">
                                <span><?= series_h($series['title']) ?></span>
                            </a>
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
                                    function apply() {
                                        img.style.objectPosition = focalX + '% ' + focalY + '%';
                                        img.style.transform = 'scale(' + (zoom / 100) + ')';
                                        focalXField.value = focalX;
                                        focalYField.value = focalY;
                                        zoomField.value = zoom;
                                    }
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
                            <?php else: ?>
                                <button type="button" class="series-header-empty" onclick="var d=document.getElementById('series-upload-details'); d.open=true; d.scrollIntoView({behavior:'smooth',block:'center'});">
                                    Upload header image
                                </button>
                            <?php endif; ?>

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

                            <details class="series-header-upload" id="series-upload-details">
                                <summary>Upload your own image</summary>
                                <form method="post" enctype="multipart/form-data" class="series-header-upload__form">
                                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                    <input type="hidden" name="action" value="upload_series_header">
                                    <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                    <input type="file" name="header_upload" accept="image/png,image/jpeg,image/webp" required>
                                    <button class="button-link secondary" type="submit">Upload</button>
                                </form>
                            </details>
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

            <section class="catalog-panel catalog-panel--compact">
                <div class="detail-heading">
                    <div>
                        <h2>Artwork Assignment</h2>
                        <p>Each canonical artwork and all its root views and mockups inherit this series identifier. Changing the series saves right away.</p>
                    </div>
                </div>
                <?php if (!$artworks): ?>
                    <div class="empty-state">No finished artworks yet.</div>
                <?php else: ?>
                    <div class="series-artwork-list">
                        <?php foreach ($artworks as $artwork): ?>
                            <?php
                            $title = series_artwork_title($artwork);
                            $seriesTitle = ArtworkSeries::display((string)($artwork['series_title'] ?: $artwork['series']));
                            $creationIdentifier = ArtworkSeries::creationIdentifier($seriesTitle, $artwork['series_creation_number'] ?? null);
                            $file = (string)($artwork['root_file'] ?: $artwork['main_file']);
                            $size = trim((string)($artwork['width'] ?? '')) !== '' && trim((string)($artwork['height'] ?? '')) !== ''
                                ? trim((string)$artwork['width']) . ' x ' . trim((string)$artwork['height']) . ' ' . (trim((string)($artwork['unit'] ?? 'cm')) ?: 'cm')
                                : '';
                            ?>
                            <article class="series-artwork-row">
                                <a class="series-artwork-thumb" href="artwork_details.php?id=<?= (int)$artwork['id'] ?>">
                                    <img src="<?= series_h(series_media_url($file, 420)) ?>" alt="<?= series_h($title) ?>" loading="lazy">
                                </a>
                                <div class="series-artwork-main">
                                    <h3><?= series_h($title) ?><?php if ($seriesTitle !== ''): ?> <span class="title-series-soft">(<?= series_h($seriesTitle) ?>)</span><?php endif; ?></h3>
                                    <p><?php if ($creationIdentifier !== ''): ?><strong class="creation-identifier"><?= series_h($creationIdentifier) ?></strong> · <?php endif; ?><?= $size !== '' ? series_h($size) . ' · ' : '' ?><?= (int)$artwork['mockup_count'] ?> mockups</p>
                                </div>
                                <div class="series-artwork-controls">
                                    <form class="series-assign-form" method="post">
                                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                        <input type="hidden" name="action" value="assign_artwork">
                                        <input type="hidden" name="artwork_id" value="<?= (int)$artwork['id'] ?>">
                                        <select name="series_id" aria-label="Artwork series" onchange="this.form.submit()">
                                            <option value="">NO SERIE</option>
                                            <?php foreach ($seriesRows as $series): ?>
                                                <option value="<?= (int)$series['id'] ?>" <?= (int)($artwork['series_id'] ?? 0) === (int)$series['id'] ? 'selected' : '' ?>><?= series_h($series['title']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                    <?php if ($seriesTitle !== ''): ?>
                                        <form class="creation-number-form" method="post">
                                            <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                            <input type="hidden" name="action" value="set_creation_number">
                                            <input type="hidden" name="artwork_id" value="<?= (int)$artwork['id'] ?>">
                                            <label for="creation-number-<?= (int)$artwork['id'] ?>">Creation ID</label>
                                            <div class="creation-number-control">
                                                <span><?= series_h(ArtworkSeries::creationPrefix($seriesTitle)) ?></span>
                                                <input id="creation-number-<?= (int)$artwork['id'] ?>" type="number" name="creation_number" min="1" step="1" value="<?= (int)($artwork['series_creation_number'] ?? 0) ?>" required>
                                                <button type="submit">Save</button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
