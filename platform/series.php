<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$user = Auth::requireUser();
$pdo = Database::connection();
Auth::start();
$userId = (int)$user['id'];
$bilingualEditorialService = new BilingualEditorialService($pdo);
$seriesPreviewActive = UiPreview::isActive($user, 'series-catalog');
$seriesBilingualExperiment = $bilingualEditorialService->isEnabled($userId)
    || (Auth::isAdmin($user) && (string)($_GET['bilingual_experiment'] ?? '') === '1');

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

function series_year_select(string $name, mixed $selected, bool $submitOnChange = false, string $ariaLabel = ''): string
{
    $selected = $selected !== null && $selected !== '' ? (int)$selected : null;
    $currentYear = (int)date('Y');
    $html = '<select name="' . series_h($name) . '"'
        . ($ariaLabel !== '' ? ' aria-label="' . series_h($ariaLabel) . '"' : '')
        . ($submitOnChange ? ' onchange="this.form.requestSubmit()"' : '')
        . '><option value="">—</option>';
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

function series_editorial_has_text(mixed $value): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (series_editorial_has_text($item)) return true;
        }
        return false;
    }
    return trim((string)$value) !== '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['series_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException(t('Invalid request.', 'Solicitud inválida.'));
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create_series') {
            $title = ArtworkSeries::normalizeTitle((string)($_POST['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException(t('Series title is required.', 'El título de la serie es obligatorio.'));
            }
            ArtworkSeries::getOrCreate($pdo, $userId, $title, trim((string)($_POST['description'] ?? '')));
            $notice = t('Series created.', 'Serie creada.');
        } elseif ($action === 'update_series') {
            ArtworkSeries::updateContent($pdo, $userId, (int)($_POST['series_id'] ?? 0), [
                'title' => (string)($_POST['title'] ?? ''),
                'subtitle' => (string)($_POST['subtitle'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'long_description' => (string)($_POST['long_description'] ?? ''),
                'tags' => (string)($_POST['tags'] ?? ''),
                'keywords' => (string)($_POST['keywords'] ?? ''),
                'seo_description' => (string)($_POST['seo_description'] ?? ''),
                'conceptual_core' => (string)($_POST['conceptual_core'] ?? ''),
                'interpretive_limits' => (string)($_POST['interpretive_limits'] ?? ''),
                'year_start' => (string)($_POST['year_start'] ?? ''),
                'year_end' => (string)($_POST['year_end'] ?? ''),
            ]);
            $notice = t('Series updated.', 'Serie actualizada.');
        } elseif ($action === 'update_series_direction') {
            ArtworkSeries::updateDirection(
                $pdo,
                $userId,
                (int)($_POST['series_id'] ?? 0),
                (string)($_POST['conceptual_core'] ?? ''),
                (string)($_POST['interpretive_limits'] ?? '')
            );
            $notice = t('Series conceptual direction updated.', 'Dirección conceptual de la serie actualizada.');
        } elseif ($action === 'update_series_period') {
            ArtworkSeries::updatePeriod(
                $pdo,
                $userId,
                (int)($_POST['series_id'] ?? 0),
                (string)($_POST['year_start'] ?? ''),
                (string)($_POST['year_end'] ?? '')
            );
            $notice = t('Series period updated.', 'Período de la serie actualizado.');
        } elseif ($action === 'set_series_header') {
            ArtworkSeries::setHeader($pdo, $userId, (int)($_POST['series_id'] ?? 0), (string)($_POST['file'] ?? ''));
            $notice = t('Series header image updated.', 'Imagen de portada de la serie actualizada.');
        } elseif ($action === 'upload_series_header') {
            ArtworkSeries::uploadHeader($pdo, $userId, (int)($_POST['series_id'] ?? 0), $_FILES['header_upload'] ?? []);
            $notice = t('Series header image uploaded.', 'Imagen de portada de la serie subida.');
        } elseif ($action === 'set_series_header_framing') {
            ArtworkSeries::setHeaderFraming(
                $pdo, $userId, (int)($_POST['series_id'] ?? 0),
                (float)($_POST['focal_x'] ?? 50), (float)($_POST['focal_y'] ?? 50), (float)($_POST['zoom'] ?? 115)
            );
            $notice = t('Header framing updated.', 'Encuadre del encabezado actualizado.');
        } elseif ($action === 'publish_series') {
            $publishSeriesId = (int)($_POST['series_id'] ?? 0);
            ArtworkSeries::setPublished($pdo, $userId, $publishSeriesId, true);
            $notice = t('Series published on the artist website.', 'Serie publicada en el sitio del artista.');
            if ($seriesBilingualExperiment) {
                // El español maestro se aprueba con la misma decisión: la mesa bilingüe
                // no expone una publicación de idioma separada.
                $publishSpanish = $bilingualEditorialService->get($userId, 'series', $publishSeriesId, 'es');
                if (series_editorial_has_text((array)($publishSpanish['content'] ?? []))) {
                    $bilingualEditorialService->setSpanishPublished($userId, 'series', $publishSeriesId, true);
                    $notice = t('Series published with the approved Spanish text.', 'Serie publicada con el español aprobado.');
                }
            }
        } elseif ($action === 'unpublish_series') {
            ArtworkSeries::setPublished($pdo, $userId, (int)($_POST['series_id'] ?? 0), false);
            $notice = t('Series removed from the website.', 'Serie eliminada del sitio web.');
        } elseif ($action === 'delete_series') {
            ArtworkSeries::deleteSeries($pdo, $userId, (int)($_POST['series_id'] ?? 0));
            $notice = t('Series removed. Artworks moved to NO SERIE.', 'Serie eliminada. Las obras pasaron a SIN SERIE.');
        } elseif ($action === 'assign_artwork') {
            $rawSeriesId = trim((string)($_POST['series_id'] ?? ''));
            ArtworkSeries::assignArtwork($pdo, $userId, (int)($_POST['artwork_id'] ?? 0), $rawSeriesId === '' ? null : (int)$rawSeriesId);
            $notice = t('Artwork series updated.', 'Serie de la obra actualizada.');
        } elseif ($action === 'set_creation_number') {
            ArtworkSeries::setCreationNumber(
                $pdo,
                $userId,
                (int)($_POST['artwork_id'] ?? 0),
                (int)($_POST['creation_number'] ?? 0)
            );
            $notice = t('Artwork Creation ID updated.', 'ID de creación de la obra actualizado.');
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
    return trim((string)($artwork['sheet_title'] ?: '')) ?: (trim((string)($artwork['final_title'] ?: '')) ?: t('Untitled', 'Sin título'));
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
$seriesSpanishEditorial = $selectedSeries && $seriesBilingualExperiment
    ? $bilingualEditorialService->get($userId, 'series', (int)$selectedSeries['id'], 'es')
    : ['content' => [], 'private_memo' => '', 'status' => 'unprepared'];
$seriesEnglishEditorial = $selectedSeries && $seriesBilingualExperiment
    ? $bilingualEditorialService->get($userId, 'series', (int)$selectedSeries['id'], 'en')
    : ['content' => [], 'private_memo' => '', 'status' => 'unprepared'];
$seriesHasSpanishContent = series_editorial_has_text((array)($seriesSpanishEditorial['content'] ?? []));
$seriesMissing = $selectedSeries ? ArtworkSeries::missingForPublish($selectedSeries) : [];
$seriesMissingLabelsEs = ['header image' => 'imagen de portada', 'description' => 'texto de la serie'];
$seriesMissingEs = array_map(
    static fn(string $item): string => $seriesMissingLabelsEs[$item] ?? $item,
    $seriesMissing
);
$seriesIsPublished = $selectedSeries && !empty($selectedSeries['published']);
$seriesSpanishPending = $seriesIsPublished && !empty($seriesSpanishEditorial['has_unpublished_changes']);
$seriesPublicationAnchor = $selectedSeries ? 'series.php?series=' . (int)$selectedSeries['id'] : 'series.php';
$seriesEditorialStateLabel = ($seriesEnglishEditorial['status'] ?? '') === 'stale'
    ? 'English · actualizar'
    : (($seriesEnglishEditorial['status'] ?? '') === 'unprepared' ? 'English · pendiente' : 'ES + EN');
$seriesEditorialFields = $selectedSeries ? [
    ['key' => 'subtitle', 'es' => 'Subtítulo', 'en' => 'Subtitle', 'large' => false, 'es_placeholder' => 'Subtítulo editorial de la serie…', 'en_placeholder' => 'International English subtitle…'],
    ['key' => 'short_description', 'es' => 'Descripción breve', 'en' => 'Short description', 'large' => false, 'es_placeholder' => 'Una o dos frases para presentar la serie…', 'en_placeholder' => 'Short international presentation…'],
    ['key' => 'description', 'es' => 'Texto curatorial', 'en' => 'Curatorial text', 'large' => true, 'es_placeholder' => 'Escribí el texto curatorial completo de la serie…', 'en_placeholder' => 'International English curatorial text…'],
] : [];
$seriesSearchFields = $selectedSeries ? [
    ['key' => 'tags', 'es' => 'Tags de catálogo', 'en' => 'Catalogue tags', 'large' => false, 'es_placeholder' => 'Tipo, estilos, técnicas, materiales, soporte, color, superficie y formato…', 'en_placeholder' => 'Type, styles, techniques, materials, support, color, surface and format…'],
    ['key' => 'search_terms', 'es' => 'Búsquedas y long tails', 'en' => 'Searches and long tails', 'large' => false, 'es_placeholder' => 'Búsquedas amplias y específicas que usaría un comprador…', 'en_placeholder' => 'Broad and specific searches an international buyer would use…'],
    ['key' => 'seo_title', 'es' => 'Título SEO', 'en' => 'SEO title', 'large' => false, 'es_placeholder' => 'Nombre de serie + descripción clara + artista…', 'en_placeholder' => 'Series name + clear descriptor + artist…'],
    ['key' => 'seo_description', 'es' => 'Descripción SEO', 'en' => 'SEO description', 'large' => false, 'es_placeholder' => 'Descripción breve para buscadores…', 'en_placeholder' => 'International English SEO description…'],
] : [];
?>
<!doctype html>
<html lang="<?= series_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= series_h(t('Series - Artwork Mockups', 'Series - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css?v=19">
    <?php if ($selectedSeries && $seriesBilingualExperiment): ?><link rel="stylesheet" href="bilingual-editorial.css?v=20260723-8"><?php endif; ?>
    <?php if ($seriesPreviewActive): ?>
        <link rel="stylesheet" href="visual-consistency-preview.css?v=2">
    <?php endif; ?>
    <style>
        .series-bilingual-title {
            display:grid;
            grid-template-columns:160px minmax(0,1fr) auto;
            gap:22px clamp(22px,3vw,44px);
            align-items:stretch;
            width:100%;
            box-sizing:border-box;
            padding:18px 20px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .series-bilingual-cover-upload {
            min-width:0;
            margin:0;
        }

        .series-bilingual-cover {
            display:block;
            position:relative;
            min-height:160px;
            overflow:hidden;
            border:1px solid var(--line);
            background:var(--surface-soft);
            cursor:pointer;
        }

        .series-bilingual-cover img {
            display:block;
            width:100%;
            height:100%;
            object-fit:cover;
            transform-origin:center;
        }

        .series-bilingual-cover--empty {
            display:flex;
            align-items:center;
            justify-content:center;
            border-style:dashed;
        }

        .series-bilingual-cover--empty .series-header-framing__replace {
            position:static;
        }

        .series-bilingual-cover-upload .series-header-upload__status {
            margin:6px 0 0;
        }

        .series-bilingual-title-copy {
            min-width:0;
            align-self:center;
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

        .series-bilingual-heading:focus,
        .series-bilingual-heading [data-universal-title] { color:inherit; font:inherit !important; font-family:inherit !important; font-size:inherit !important; font-weight:inherit !important; letter-spacing:inherit !important; line-height:inherit !important; text-transform:inherit; }
        .series-bilingual-heading [data-universal-title] { cursor:text; }
        .series-bilingual-heading [data-universal-title]:focus { outline:0; }

        .series-bilingual-title-memo {
            margin:15px 0 0;
            color:var(--accent);
            font:italic 500 21px/1.5 var(--font-serif);
        }

        /* El período se lee como una línea editorial más del título, nunca como un formulario. */
        .series-bilingual-period { display:flex; align-items:baseline; flex-wrap:wrap; gap:6px; margin:12px 0 0; }
        .series-bilingual-period select {
            width:auto;
            padding:2px 18px 2px 4px;
            border:0;
            border-bottom:1px solid transparent;
            border-radius:0;
            background:transparent
                url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' fill='none' stroke='%238b8378' stroke-width='1.4'/%3E%3C/svg%3E")
                no-repeat right 4px center/9px 5px;
            box-shadow:none;
            color:var(--muted);
            font-size:13px;
            letter-spacing:.02em;
            -webkit-appearance:none;
            appearance:none;
            cursor:pointer;
        }
        .series-bilingual-period select:hover { border-bottom-color:var(--line-dark,#c8beb4); color:var(--ink); }
        .series-bilingual-period select:focus { border-bottom-color:var(--accent); box-shadow:none; color:var(--ink); outline:0; }
        .series-bilingual-period span { color:var(--muted); font-size:13px; }
        .series-bilingual-period__note { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }

        .series-bilingual-actions { display:grid; grid-template-columns:repeat(2,112px); align-content:center; align-items:start; justify-items:stretch; gap:10px; }
        .series-bilingual-actions form { margin:0; }
        .series-bilingual-actions .status-pill { grid-column:1 / -1; justify-self:center; margin:0; }
        .series-bilingual-actions__missing { grid-column:1 / -1; max-width:234px; margin:0; color:var(--muted); font-size:10px; line-height:1.45; text-align:center; }
        .series-bilingual-actions__missing a { color:var(--accent); text-decoration:underline; }
        .series-bilingual-actions__retire { grid-column:1 / -1; justify-self:center; }
        .series-bilingual-actions__retire button { width:auto; min-height:0; margin:0; padding:8px 12px; box-shadow:none; font-size:9px; }

        .series-bilingual-editorial {
            margin-top:18px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .series-direction-editor {
            margin-top:18px;
            border:1px solid var(--line);
            background:var(--surface);
        }

        .series-direction-editor > summary {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:20px;
            padding:18px 20px;
            cursor:pointer;
            list-style:none;
        }

        .series-direction-editor > summary::-webkit-details-marker { display:none; }
        .series-direction-editor > summary strong { display:block; color:var(--ink); font:500 23px/1.1 var(--font-serif); }
        .series-direction-editor > summary small { display:block; margin-top:5px; color:var(--muted); font-size:12px; }
        .series-direction-editor > summary::after { content:'+'; color:var(--accent); font:500 22px/1 var(--font-serif); }
        .series-direction-editor[open] > summary::after { content:'−'; }
        .series-direction-editor form { border-top:1px solid var(--line); background:var(--surface); }
        .series-direction-editor .series-bilingual-spread { border-top:0; }
        .series-direction-editor footer { display:flex; align-items:center; justify-content:space-between; gap:20px; margin:0 14px 14px; padding:14px 18px; border-top:1px solid var(--line); background:#fbf7e8; }
        .series-direction-editor footer p { max-width:650px; margin:0; color:var(--muted); font-size:11px; line-height:1.5; }
        .series-direction-editor button { width:auto; min-height:40px; margin:0; padding:10px 16px; border:1px solid #d8c17e; border-radius:3px; background:#ead99f; color:#554a30; box-shadow:none; font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; }

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
            column-gap:6px;
            row-gap:0;
            padding:14px;
            border-top:1px solid var(--line);
        }
        .series-bilingual-page {
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
        .series-search-architecture { margin-top:18px; border-top:1px solid var(--line); }
        .series-search-architecture > summary { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:16px 0; cursor:pointer; list-style:none; }
        .series-search-architecture > summary::-webkit-details-marker { display:none; }
        .series-search-architecture > summary span { color:var(--ink); font:500 20px/1.2 var(--font-serif); }
        .series-search-architecture > summary small { color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .series-search-architecture > summary small::after { content:'+'; margin-left:10px; color:var(--accent); font:500 19px/1 var(--font-serif); }
        .series-search-architecture[open] > summary small::after { content:'−'; }
        .series-search-architecture .series-bilingual-field:first-of-type { margin-top:0; }

        @supports (grid-template-rows:subgrid) {
            .series-bilingual-editorial > .series-bilingual-spread {
                grid-template-rows:repeat(9,auto);
            }

            .series-bilingual-editorial > .series-bilingual-spread > .series-bilingual-page {
                display:grid;
                grid-template-rows:subgrid;
                grid-row:1 / span 9;
            }

            .series-bilingual-editorial > .series-bilingual-spread .series-search-architecture {
                display:contents;
            }

            .series-bilingual-editorial > .series-bilingual-spread .series-search-architecture > summary {
                margin-top:18px;
                border-top:1px solid var(--line);
            }
        }
        .series-bilingual-memo { margin:0 14px 14px; padding:14px 6px 2px; border-top:1px solid var(--line); }
        .series-bilingual-memo summary { cursor:pointer; color:var(--muted); font-size:9px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
        .series-bilingual-memo .series-bilingual-copy { min-height:82px; }
        .bilingual-publication-bar { display:flex; align-items:center; justify-content:space-between; gap:18px; margin:0 14px 14px; padding:14px 18px; border-top:1px solid var(--line); background:#fbf7e8; }
        .bilingual-publication-bar span { color:var(--muted); font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; }
        .bilingual-publication-bar button { min-height:38px; margin:0; padding:9px 16px; border:1px solid #d8c17e; border-radius:3px; background:#ead99f; color:#554a30; box-shadow:none; font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }

        .series-website-decision {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            width:112px;
            min-width:112px;
            height:112px;
            min-height:112px;
            margin:0;
            padding:14px;
            border-radius:4px;
            box-shadow:0 8px 20px rgba(58,52,43,.10);
            font-size:11px;
            font-weight:700;
            letter-spacing:.08em;
            line-height:1.35;
            text-align:center;
            text-transform:uppercase;
        }

        .series-website-decision--publish { border:1px solid #d6c27d; background:#ead99f; color:#554a30; }
        .series-website-decision--publish:hover,
        .series-website-decision--publish:focus-visible { border-color:#c7ae5e; background:#e2cd86; }
        .series-website-decision--retire { border:1px solid #c9a0a7; background:#ddbfc3; color:#5d363d; }
        .series-website-decision--retire:hover,
        .series-website-decision--retire:focus-visible { border-color:#b9858e; background:#d3adb3; }
        .series-website-decision--create { border:1px solid #9daf98; background:#b8c7b1; color:#344232; text-decoration:none; }
        .series-website-decision--create:hover,
        .series-website-decision--create:focus-visible { border-color:#879c81; background:#aabca3; color:#293627; }
        .series-website-decision:disabled { box-shadow:none; opacity:.55; }

        .series-order-grid [data-series-order-id] { cursor:grab; }
        .series-order-grid [data-series-order-id].sortable-chosen { cursor:grabbing; }
        .series-order-grid .sortable-ghost { opacity:.35; }
        .series-order-grid.is-saving-order { pointer-events:none; }
        .series-order-status { display:block; min-height:14px; margin:8px 0 0; color:var(--muted); font-size:9px; letter-spacing:.05em; text-align:right; text-transform:uppercase; }
        .series-order-status.is-error { color:#9f2d23; }

        @media (max-width:800px) {
            .series-bilingual-title { grid-template-columns:100px minmax(0,1fr); gap:14px; padding:14px; }
            .series-bilingual-actions { grid-column:1 / -1; grid-template-columns:repeat(2,112px); align-items:start; justify-content:start; justify-items:stretch; gap:10px; padding-top:4px; border-top:1px solid var(--line); }
            .series-bilingual-cover { min-height:112px; }
            .series-bilingual-heading { font-size:36px; }
            .series-bilingual-title-memo { font-size:17px; }
            .series-direction-editor form { grid-template-columns:1fr; }
            .series-bilingual-spread { grid-template-columns:1fr; }
            .series-bilingual-page { grid-column:auto; }

            @supports (grid-template-rows:subgrid) {
                .series-bilingual-editorial > .series-bilingual-spread {
                    grid-template-rows:none;
                }

                .series-bilingual-editorial > .series-bilingual-spread > .series-bilingual-page {
                    display:block;
                    grid-row:auto;
                }

                .series-bilingual-editorial > .series-bilingual-spread .series-search-architecture {
                    display:block;
                }

                .series-bilingual-editorial > .series-bilingual-spread .series-search-architecture > summary {
                    margin-top:0;
                }
            }
        }

        @media (max-width:560px) {
            .series-bilingual-title { grid-template-columns:86px minmax(0,1fr); }
        }
    </style>
</head>
<body class="series-page<?= $seriesPreviewActive ? ' ui-visual-consistency-preview' : '' ?>"<?= $seriesPreviewActive ? ' data-ui-preview="series-catalog"' : '' ?>>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= series_h($user['email']) ?></a></header>
        <div class="series-catalog"<?= $selectedSeries && $seriesBilingualExperiment ? ' data-bilingual-editor data-entity-type="series" data-entity-id="' . (int)$selectedSeries['id'] . '" data-csrf="' . series_h(Auth::csrfToken('bilingual_editorial')) . '" data-endpoint="bilingual_editorial.php"' : '' ?>>
            <?php if ($seriesPreviewActive): ?>
                <aside class="ui-preview-notice" aria-label="<?= series_h(t('Visual consistency preview', 'Vista previa de consistencia visual')) ?>">
                    <span><strong><?= series_h(t('Preview', 'Vista previa')) ?></strong> <?= series_h(t('Series workspace', 'Mesa de trabajo de series')) ?></span>
                    <a href="series.php<?= $selectedSeries ? '?series=' . (int)$selectedSeries['id'] : '' ?>"><?= series_h(t('Exit preview', 'Salir de la vista previa')) ?></a>
                </aside>
            <?php endif; ?>
            <?php if (!$selectedSeries): ?>
            <div class="catalog-heading">
                <div>
                    <h1><?= series_h(t('Series', 'Series')) ?></h1>
                    <p><?= series_h(t('Group artworks and mockups by series. NO SERIE stays silent in public titles.', 'Agrupá obras y mockups por serie. SIN SERIE se mantiene silencioso en los títulos públicos.')) ?></p>
                </div>
            </div>
            <?php elseif ($seriesBilingualExperiment): ?>
            <div class="series-bilingual-title" aria-label="Título universal de la serie">
                <form method="post" enctype="multipart/form-data" class="series-bilingual-cover-upload" data-series-header-upload data-series-bilingual-header-upload>
                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                    <input type="hidden" name="action" value="upload_series_header">
                    <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                    <label
                        class="series-bilingual-cover<?= empty($selectedSeries['header_file']) ? ' series-bilingual-cover--empty' : '' ?>"
                        for="series-bilingual-header-upload-input"
                        data-series-header-dropzone
                        tabindex="0"
                        role="button"
                        aria-label="<?= empty($selectedSeries['header_file']) ? series_h(t('Upload series cover', 'Subir portada de la serie')) : series_h(t('Change series cover', 'Cambiar portada de la serie')) ?>"
                    >
                        <?php if (!empty($selectedSeries['header_file'])): ?>
                        <img
                            src="<?= series_h(series_media_url($selectedSeries['header_file'], 520)) ?>"
                            alt="<?= series_h(t('Cover of', 'Portada de')) ?> <?= series_h($selectedSeries['title']) ?>"
                            style="object-position:<?= (int)($selectedSeries['header_focal_x'] ?? 50) ?>% <?= (int)($selectedSeries['header_focal_y'] ?? 50) ?>%;transform:scale(<?= ((int)($selectedSeries['header_zoom'] ?? 115)) / 100 ?>);"
                        >
                        <?php endif; ?>
                        <span class="series-header-framing__replace" data-series-header-label><?= empty($selectedSeries['header_file']) ? series_h(t('Upload cover', 'Subir portada')) : series_h(t('Change image', 'Cambiar imagen')) ?></span>
                    </label>
                    <input id="series-bilingual-header-upload-input" class="series-header-upload__input" type="file" name="header_upload" accept="image/png,image/jpeg,image/webp" required data-series-header-file>
                    <span class="series-header-upload__status" data-series-header-status aria-live="polite"></span>
                </form>
                <div class="series-bilingual-title-copy">
                    <span class="series-bilingual-label">Título universal</span>
                    <h1 class="series-bilingual-heading" aria-label="Título de la serie"><span contenteditable="true" role="textbox" data-universal-title><?= series_h($selectedSeries['title']) ?></span> <span contenteditable="false">Series</span></h1>
                    <p class="series-bilingual-title-memo">STRATA — LIMEN · SERIES X — NUHRĀ (ܢܘܗܪܐ) · no traducir</p>
                    <form class="series-bilingual-period" method="post" action="<?= series_h($seriesPublicationAnchor) ?>">
                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                        <input type="hidden" name="action" value="update_series_period">
                        <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                        <?= series_year_select('year_start', $selectedSeries['year_start'] ?? null, true, series_h(t('Series start year', 'Año de inicio de la serie'))) ?>
                        <span aria-hidden="true">—</span>
                        <?= series_year_select('year_end', $selectedSeries['year_end'] ?? null, true, series_h(t('Series end year', 'Año de cierre de la serie'))) ?>
                        <span class="series-bilingual-period__note"><?= empty($selectedSeries['year_end']) ? series_h(t('open series', 'serie abierta')) : series_h(t('closed series', 'serie cerrada')) ?></span>
                    </form>
                </div>
                <div class="series-bilingual-actions">
                    <span class="status-pill <?= $seriesIsPublished ? 'status-published' : 'status-pending' ?>"><?= $seriesIsPublished ? series_h(t('Published', 'Publicada')) : series_h(t('Draft', 'Borrador')) ?></span>
                    <a class="series-website-decision series-website-decision--create" href="create_scenes.php?series=<?= (int)$selectedSeries['id'] ?>"><span><?= t('Create<br>artwork', 'Crear<br>obra') ?></span></a>
                    <form method="post" action="<?= series_h($seriesPublicationAnchor) ?>">
                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                        <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                        <?php if (!$seriesIsPublished): ?>
                            <button class="series-website-decision series-website-decision--publish" type="submit" name="action" value="publish_series" <?= $seriesMissing ? 'disabled' : '' ?>><span><?= t('Publish<br>series', 'Publicar<br>serie') ?></span></button>
                        <?php elseif ($seriesSpanishPending): ?>
                            <button class="series-website-decision series-website-decision--publish" type="submit" name="action" value="publish_series"><span><?= t('Publish<br>changes', 'Publicar<br>cambios') ?></span></button>
                        <?php else: ?>
                            <button class="series-website-decision series-website-decision--retire" type="submit" name="action" value="unpublish_series"><span><?= t('Retire<br>series', 'Retirar<br>serie') ?></span></button>
                        <?php endif; ?>
                    </form>
                    <?php if ($seriesMissingEs): ?>
                        <p class="series-bilingual-actions__missing">
                            <?= series_h(t('Missing', 'Falta')) ?> <?= series_h(implode(' · ', $seriesMissingEs)) ?>
                            <?php if (in_array('texto de la serie', $seriesMissingEs, true)): ?><a href="#series-language-editorial"><?= series_h(t('complete', 'completar')) ?></a><?php endif; ?>
                        </p>
                    <?php elseif ($seriesIsPublished && $seriesSpanishPending): ?>
                        <form method="post" action="<?= series_h($seriesPublicationAnchor) ?>" class="series-bilingual-actions__retire">
                            <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                            <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                            <button class="button-link secondary" type="submit" name="action" value="unpublish_series"><?= series_h(t('Remove from site', 'Retirar del sitio')) ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <details class="series-direction-editor"<?= trim((string)($selectedSeries['conceptual_core'] ?? '')) === '' && trim((string)($selectedSeries['interpretive_limits'] ?? '')) === '' ? ' open' : '' ?>>
                <summary>
                    <span>
                        <strong><?= series_h(t('Series direction', 'Dirección de la serie')) ?></strong>
                        <small><?= series_h(t("Artist's explanation to define the series identity and copy.", 'Explicación del artista para definir la identidad y los textos de la serie.')) ?></small>
                    </span>
                </summary>
                <form method="post" data-series-direction-form>
                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                    <input type="hidden" name="action" value="update_series_direction">
                    <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                    <input type="hidden" name="conceptual_core" value="<?= series_h($selectedSeries['conceptual_core'] ?? '') ?>">
                    <input type="hidden" name="interpretive_limits" value="<?= series_h($selectedSeries['interpretive_limits'] ?? '') ?>">
                    <div class="series-bilingual-spread">
                        <article class="series-bilingual-page series-bilingual-page--source">
                            <span class="series-bilingual-language">Fuente del artista</span>
                            <section class="series-bilingual-field series-bilingual-field--large">
                                <label>Núcleo conceptual</label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-series-direction-copy="conceptual_core" data-placeholder="Qué territorio conceptual abre esta serie; qué relaciones, tensiones o imágenes deben permanecer abiertas."><?= series_h($selectedSeries['conceptual_core'] ?? '') ?></div>
                            </section>
                        </article>
                        <article class="series-bilingual-page series-bilingual-page--english">
                            <span class="series-bilingual-language">Límites del texto</span>
                            <section class="series-bilingual-field series-bilingual-field--large">
                                <label>Límites interpretativos</label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-series-direction-copy="interpretive_limits" data-placeholder="Qué no debe reducir, afirmar ni convertir en conclusión el texto."><?= series_h($selectedSeries['interpretive_limits'] ?? '') ?></div>
                            </section>
                        </article>
                    </div>
                    <footer>
                        <p><?= series_h(t('These notes are not published: they are the source of the series Spanish content.', 'Estas notas no se publican: son la fuente del contenido español de la serie.')) ?></p>
                        <button type="submit"><?= series_h(t('Save direction', 'Guardar dirección')) ?></button>
                    </footer>
                </form>
            </details>
            <details class="series-bilingual-editorial" id="series-language-editorial">
                <summary>
                    <span class="series-bilingual-summary">
                        <strong><?= series_h(t('Editorial space', 'Espacio editorial')) ?></strong>
                        <span><?= series_h(t('Master Spanish and international English for publication.', 'Español maestro e inglés internacional para publicación.')) ?></span>
                    </span>
                    <span class="series-bilingual-state" data-bilingual-save-state><?= series_h($seriesEditorialStateLabel) ?></span>
                </summary>
                <div class="series-bilingual-spread">
                    <article class="series-bilingual-page series-bilingual-page--source">
                        <span class="series-bilingual-language">Español · fuente</span>
                        <?php foreach ($seriesEditorialFields as $field): ?>
                            <section class="series-bilingual-field <?= $field['large'] ? 'series-bilingual-field--large' : '' ?>">
                                <label><?= series_h($field['es']) ?></label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="es" data-editorial-field="<?= series_h($field['key']) ?>" data-placeholder="<?= series_h($field['es_placeholder']) ?>"><?= series_h($seriesSpanishEditorial['content'][$field['key']] ?? '') ?></div>
                            </section>
                        <?php endforeach; ?>
                        <details class="series-search-architecture">
                            <summary><span>SEO de catálogo</span><small>Cómo te encuentran</small></summary>
                            <?php foreach ($seriesSearchFields as $field): ?>
                                <section class="series-bilingual-field">
                                    <label><?= series_h($field['es']) ?></label>
                                    <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="es" data-editorial-field="<?= series_h($field['key']) ?>" data-placeholder="<?= series_h($field['es_placeholder']) ?>"><?= series_h($seriesSpanishEditorial['content'][$field['key']] ?? '') ?></div>
                                </section>
                            <?php endforeach; ?>
                        </details>
                    </article>
                    <article class="series-bilingual-page series-bilingual-page--english">
                        <span class="series-bilingual-language">English · publicación internacional</span>
                        <?php foreach ($seriesEditorialFields as $field): ?>
                            <section class="series-bilingual-field <?= $field['large'] ? 'series-bilingual-field--large' : '' ?>">
                                <label><?= series_h($field['en']) ?></label>
                                <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="en" data-editorial-field="<?= series_h($field['key']) ?>" data-placeholder="<?= series_h($field['en_placeholder']) ?>"><?= series_h($seriesEnglishEditorial['content'][$field['key']] ?? '') ?></div>
                            </section>
                        <?php endforeach; ?>
                        <details class="series-search-architecture">
                            <summary><span>Catalogue SEO</span><small>How buyers find it</small></summary>
                            <?php foreach ($seriesSearchFields as $field): ?>
                                <section class="series-bilingual-field">
                                    <label><?= series_h($field['en']) ?></label>
                                    <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-editorial-locale="en" data-editorial-field="<?= series_h($field['key']) ?>" data-placeholder="<?= series_h($field['en_placeholder']) ?>"><?= series_h($seriesEnglishEditorial['content'][$field['key']] ?? '') ?></div>
                                </section>
                            <?php endforeach; ?>
                        </details>
                    </article>
                </div>
                <div class="bilingual-preparation-bar">
                    <div>
                        <strong><?= series_h(t('Generate ES + EN content', 'Generar contenido ES + EN')) ?></strong>
                        <span><?= series_h(t('Creates the descriptions and useful catalogue SEO in both languages.', 'Crea las descripciones y el SEO útil de catálogo en ambos idiomas.')) ?></span>
                    </div>
                    <button type="button" data-editorial-generate><?= $seriesHasSpanishContent ? series_h(t('Update ES + EN content', 'Actualizar contenido ES + EN')) : series_h(t('Generate ES + EN content', 'Generar contenido ES + EN')) ?></button>
                </div>
                <details class="series-bilingual-memo">
                    <summary><?= series_h(t('Private series memo', 'Memo privado de la serie')) ?></summary>
                    <div class="series-bilingual-copy" contenteditable="true" role="textbox" aria-multiline="true" data-private-memo data-editorial-locale="es" data-placeholder="<?= series_h(t('Ideas, decisions and conceptual evolution of the series…', 'Ideas, decisiones y evolución conceptual de la serie…')) ?>"><?= series_h($seriesSpanishEditorial['private_memo'] ?? '') ?></div>
                </details>
            </details>
            <?php else:
                $seriesYearInline = trim(series_year_range_label($selectedSeries['year_start'] ?? null, $selectedSeries['year_end'] ?? null), " \xC2\xB7");
            ?>
            <div class="catalog-heading series-detail-heading">
                <div class="series-detail-heading__copy">
                    <div class="series-detail-title-row">
                        <h1><span class="series-title-label"><?= series_h(t('Series', 'Series')) ?></span><span class="series-title-name"><?= series_h($selectedSeries['title']) ?></span></h1>
                        <span class="status-pill <?= !empty($selectedSeries['published']) ? 'status-published' : 'status-pending' ?>">
                            <?= !empty($selectedSeries['published']) ? series_h(t('Published', 'Publicada')) : series_h(t('Draft', 'Borrador')) ?>
                        </span>
                    </div>
                    <p class="series-detail-summary">
                        <?php if (trim((string)($selectedSeries['subtitle'] ?? '')) !== ''): ?><strong><?= series_h($selectedSeries['subtitle']) ?></strong><span aria-hidden="true">·</span><?php endif; ?>
                        <?php if ($seriesYearInline !== ''): ?><span><?= series_h($seriesYearInline) ?></span><span aria-hidden="true">·</span><?php endif; ?>
                        <span><?= (int)$selectedSeries['artwork_count'] ?> <?= series_h(t('artworks', 'obras')) ?></span><span aria-hidden="true">·</span><span><?= (int)$selectedSeries['mockup_count'] ?> mockups</span>
                    </p>
                </div>
                <div class="catalog-heading__actions series-detail-actions">
                    <a class="series-create-art-decision" href="create_scenes.php?series=<?= (int)$selectedSeries['id'] ?>"><span><?= series_h(t('Create Art', 'Crear Obra')) ?></span></a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($notice !== ''): ?><div class="notice-card notice-ok"><?= series_h($notice) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="notice-card notice-error"><?= series_h($error) ?></div><?php endif; ?>

            <?php if (!$seriesBilingualExperiment || !$selectedSeries): ?>
            <section class="catalog-panel catalog-panel--compact catalog-panel--series-picker">
                <?php if ($selectedSeries && $seriesMissing): ?>
                    <div class="warning-list" style="margin-bottom: 20px;"><?= series_h(t('Complete before publishing:', 'Completá antes de publicar:')) ?> <?= series_h(implode(' · ', $seriesMissing)) ?></div>
                <?php endif; ?>
                <?php if (!$selectedSeries): ?>
                    <div
                        class="social-square-grid series-order-grid"
                        data-series-order-grid
                        data-series-order-endpoint="reorder_series.php"
                        data-series-order-csrf="<?= series_h($_SESSION['series_csrf']) ?>"
                        data-series-order-app-csrf="<?= series_h(Auth::csrfToken('mutation')) ?>"
                    >
                        <?php foreach ($seriesRows as $index => $series): ?>
                            <?php $seriesArtworkCount = (int)($series['artwork_count'] ?? 0); ?>
                            <div class="series-series-option" data-series-order-id="<?= (int)$series['id'] ?>">
                                <a class="social-square-button series-series-tile social-square-button--<?= series_tone($index) ?>" href="series.php?series=<?= (int)$series['id'] ?>" data-series-order-handle data-series-filter-id="<?= (int)$series['id'] ?>"<?= !empty($series['header_file']) ? ' style="--series-tile-image: url(\'' . series_h(series_media_url($series['header_file'], 420)) . '\'); --series-tile-position: ' . (int)($series['header_focal_x'] ?? 50) . '% ' . (int)($series['header_focal_y'] ?? 50) . '%;"' : '' ?> aria-label="<?= series_h(t('Open workspace for', 'Abrir mesa de trabajo de')) ?> <?= series_h($series['title']) ?>, <?= $seriesArtworkCount ?> <?= $seriesArtworkCount === 1 ? series_h(t('artwork', 'obra')) : series_h(t('artworks', 'obras')) ?>, <?= !empty($series['published']) ? series_h(t('published', 'publicada')) : series_h(t('draft', 'borrador')) ?>">
                                    <span class="series-series-tile__title"><?= series_h($series['title']) ?></span>
                                    <small class="series-series-tile__meta">
                                        <?= $seriesArtworkCount > 0 ? $seriesArtworkCount . ' ' . ($seriesArtworkCount === 1 ? series_h(t('artwork', 'obra')) : series_h(t('artworks', 'obras'))) : series_h(t('No artworks', 'Sin obras')) ?>
                                        · <?= !empty($series['published']) ? series_h(t('Published', 'Publicada')) : series_h(t('Draft', 'Borrador')) ?>
                                    </small>
                                </a>
                                <a class="series-series-option__edit" data-no-series-order href="series.php?series=<?= (int)$series['id'] ?>#series-language-editorial" aria-label="<?= series_h(t('Edit text for', 'Editar texto de')) ?> <?= series_h($series['title']) ?>" title="<?= series_h(t('Edit editorial text', 'Editar texto editorial')) ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l11-11-4-4L4 16v4Zm9.7-13.7 4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <details class="series-create-toggle">
                            <summary class="social-square-button social-square-button--new" aria-label="<?= series_h(t('New series', 'Nueva serie')) ?>"><span>+</span></summary>
                            <form class="series-create-form" method="post">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="create_series">
                                <input type="text" name="title" placeholder="<?= series_h(t('Series title', 'Título de la serie')) ?>" required>
                                <input type="text" name="description" placeholder="<?= series_h(t('Short internal description', 'Descripción interna breve')) ?>">
                                <button type="submit"><?= series_h(t('Create', 'Crear')) ?></button>
                            </form>
                        </details>
                    </div>
                    <span class="series-order-status" data-series-order-status aria-live="polite"></span>
                <?php else: $series = $selectedSeries; ?>
                    <div class="series-grid series-grid--detailed">
                        <div class="series-header-picker">
                            <?php if (!empty($series['header_file'])): ?>
                                <div class="series-header-framing">
                                    <label class="series-header-framing__stage" id="series-framing-stage"
                                         for="series-header-upload-input"
                                         data-series-header-dropzone
                                         tabindex="0"
                                         role="button"
                                         aria-label="<?= series_h(t('Change series image', 'Cambiar imagen de la serie')) ?>"
                                         data-focal-x="<?= (int)($series['header_focal_x'] ?? 50) ?>"
                                         data-focal-y="<?= (int)($series['header_focal_y'] ?? 50) ?>"
                                         data-zoom="<?= (int)($series['header_zoom'] ?? 115) ?>">
                                        <img src="<?= series_h(series_media_url($series['header_file'], 900)) ?>" alt="<?= series_h(t('Header preview', 'Vista previa del encabezado')) ?>" id="series-framing-img"
                                             style="object-position: <?= (int)($series['header_focal_x'] ?? 50) ?>% <?= (int)($series['header_focal_y'] ?? 50) ?>%; transform: scale(<?= ((int)($series['header_zoom'] ?? 115)) / 100 ?>);">
                                        <span class="series-header-framing__replace" data-series-header-label><?= series_h(t('Change image', 'Cambiar imagen')) ?></span>
                                    </label>
                                    <p class="series-header-framing__hint"><?= series_h(t('Click to change the image. Drag to reframe and use the control to zoom.', 'Haz clic para cambiar la imagen. Arrastra para reencuadrarla y usa el control para ampliar.')) ?> <span><?= series_h(t('The crop only affects the website.', 'El recorte solo afecta al website.')) ?></span></p>
                                    <label class="series-header-framing__zoom">Zoom<input type="range" id="series-framing-zoom" min="115" max="400" value="<?= (int)($series['header_zoom'] ?? 115) ?>"></label>
                                    <form method="post" id="series-framing-form">
                                        <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                        <input type="hidden" name="action" value="set_series_header_framing">
                                        <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                        <input type="hidden" name="focal_x" id="series-framing-focal-x" value="<?= (int)($series['header_focal_x'] ?? 50) ?>">
                                        <input type="hidden" name="focal_y" id="series-framing-focal-y" value="<?= (int)($series['header_focal_y'] ?? 50) ?>">
                                        <input type="hidden" name="zoom" id="series-framing-zoom-value" value="<?= (int)($series['header_zoom'] ?? 115) ?>">
                                        <button class="button-link secondary" type="submit"><?= series_h(t('Save framing', 'Guardar encuadre')) ?></button>
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
                                    var dragMoved = false;
                                    var dragStartX = 0;
                                    var dragStartY = 0;
                                    stage.addEventListener('mousedown', function (event) {
                                        if (event.button !== 0) return;
                                        dragging = true;
                                        dragMoved = false;
                                        dragStartX = event.clientX;
                                        dragStartY = event.clientY;
                                    });
                                    window.addEventListener('mouseup', function () { dragging = false; });
                                    stage.addEventListener('mousemove', function (event) {
                                        if (!dragging) return;
                                        if (Math.abs(event.clientX - dragStartX) > 4 || Math.abs(event.clientY - dragStartY) > 4) {
                                            dragMoved = true;
                                        }
                                        var rect = stage.getBoundingClientRect();
                                        focalX = Math.max(0, Math.min(100, Math.round(((event.clientX - rect.left) / rect.width) * 100)));
                                        focalY = Math.max(0, Math.min(100, Math.round(((event.clientY - rect.top) / rect.height) * 100)));
                                        apply();
                                    });
                                    stage.addEventListener('click', function (event) {
                                        if (!dragMoved) return;
                                        event.preventDefault();
                                        dragMoved = false;
                                    });
                                })();
                                </script>
                            <?php endif; ?>

                            <form method="post" enctype="multipart/form-data" class="series-header-upload__form <?= empty($series['header_file']) ? 'series-header-upload__form--empty' : 'series-header-upload__form--replace' ?>" data-series-header-upload>
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="upload_series_header">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                <?php if (empty($series['header_file'])): ?>
                                <label class="series-header-empty" for="series-header-upload-input" data-series-header-dropzone tabindex="0" role="button">
                                    <span data-series-header-label><?= empty($series['header_file']) ? series_h(t('Upload header image', 'Subir imagen de encabezado')) : series_h(t('Replace header image', 'Reemplazar imagen de encabezado')) ?></span>
                                    <small><?= series_h(t('Click or drop a JPG, PNG or WebP · 15 MB maximum', 'Hacé clic o soltá un JPG, PNG o WebP · máximo 15 MB')) ?></small>
                                </label>
                                <?php endif; ?>
                                <input id="series-header-upload-input" class="series-header-upload__input" type="file" name="header_upload" accept="image/png,image/jpeg,image/webp" required data-series-header-file>
                                <span class="series-header-upload__status" data-series-header-status aria-live="polite"></span>
                            </form>

                            <details class="series-header-mockups">
                                <summary><?= series_h(t('Browse generated mockups', 'Explorar mockups generados')) ?></summary>
                                <?php if (!$seriesMockupCandidates): ?>
                                    <p class="favorite-empty"><?= series_h(t('No mockups have been generated yet.', 'Todavía no se generaron mockups.')) ?></p>
                                <?php else: ?>
                                    <div class="series-header-grid">
                                        <?php foreach ($seriesMockupCandidates as $mockupCandidate): ?>
                                            <div class="pin-image">
                                                <img src="<?= series_h(series_media_url($mockupCandidate['file'], 420)) ?>" alt="<?= series_h($mockupCandidate['title'] ?: t('Mockup', 'Mockup')) ?>">
                                                <form class="header-pin-form" method="post">
                                                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                                    <input type="hidden" name="action" value="set_series_header">
                                                    <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                                                    <input type="hidden" name="file" value="<?= series_h($mockupCandidate['file']) ?>">
                                                    <button class="header-pin <?= ($series['header_file'] ?? '') === $mockupCandidate['file'] ? 'is-active' : '' ?>" name="submit_header" title="<?= series_h(t('Set as series header', 'Usar como encabezado de la serie')) ?>" aria-label="<?= series_h(t('Set as series header', 'Usar como encabezado de la serie')) ?>">
                                                        <svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>

                        </div>
                        <article class="series-card series-card--detailed">
                            <form class="series-delete-form" method="post" id="delete-series-form" onsubmit="return confirm(<?= json_encode(t('Remove this series? Artworks will move to NO SERIE.', '¿Eliminar esta serie? Las obras pasarán a SIN SERIE.')) ?>);" style="display:none;">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="delete_series">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">
                            </form>

                            <form method="post" class="catalog-edit-form">
                                <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                                <input type="hidden" name="action" value="update_series">
                                <input type="hidden" name="series_id" value="<?= (int)$series['id'] ?>">

                                <div class="catalog-edit-form__row">
                                    <label><?= series_h(t('Title', 'Título')) ?><input type="text" name="title" value="<?= series_h($series['title']) ?>" required></label>
                                    <label><?= series_h(t('Subtitle', 'Subtítulo')) ?><input type="text" name="subtitle" value="<?= series_h($series['subtitle'] ?? '') ?>" placeholder="<?= series_h(t('Short tagline shown with the title', 'Frase breve mostrada junto al título')) ?>"></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label><?= series_h(t('Years from', 'Años desde')) ?><?= series_year_select('year_start', $series['year_start'] ?? null) ?></label>
                                    <label><?= series_h(t('Years to', 'Años hasta')) ?><?= series_year_select('year_end', $series['year_end'] ?? null) ?></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label><?= series_h(t('Universal slug', 'Slug universal')) ?><input type="text" value="<?= series_h($series['slug']) ?>" readonly aria-describedby="series-slug-help"><small id="series-slug-help"><?= series_h(t('Generated automatically from the universal title.', 'Generado automáticamente a partir del título universal.')) ?></small></label>
                                    <label><?= series_h(t('Tags', 'Etiquetas')) ?><input type="text" name="tags" value="<?= series_h($series['tags'] ?? '') ?>" placeholder="<?= series_h(t('Comma separated, e.g. abstract, painting', 'Separadas por comas, ej. abstracto, pintura')) ?>"></label>
                                </div>

                                <div class="catalog-edit-form__row">
                                    <label><?= series_h(t('Short Description', 'Descripción breve')) ?><textarea name="description" rows="3" placeholder="<?= series_h(t('One or two sentences used in previews and cards', 'Una o dos frases usadas en vistas previas y tarjetas')) ?>"><?= series_h($series['description'] ?? '') ?></textarea></label>
                                    <label><?= series_h(t('SEO Meta Description', 'Meta descripción SEO')) ?><textarea name="seo_description" rows="3" placeholder="<?= series_h(t('Meta description shown in search results', 'Meta descripción mostrada en resultados de búsqueda')) ?>"><?= series_h($series['seo_description'] ?? '') ?></textarea></label>
                                </div>

                                <label><?= series_h(t('Long Description', 'Descripción extensa')) ?><textarea name="long_description" rows="8" placeholder="<?= series_h(t('Full curatorial text for the series page', 'Texto curatorial completo para la página de la serie')) ?>"><?= series_h($series['long_description'] ?? '') ?></textarea></label>
                                <label><?= series_h(t('Long-Tail Keywords', 'Palabras clave long-tail')) ?><textarea name="keywords" rows="2" placeholder="<?= series_h(t('Comma separated, e.g. structural abstract painting large scale', 'Separadas por comas, ej. pintura abstracta estructural gran escala')) ?>"><?= series_h($series['keywords'] ?? '') ?></textarea></label>
                                <label><?= series_h(t('Conceptual Core', 'Núcleo conceptual')) ?><textarea name="conceptual_core" rows="6" placeholder="<?= series_h(t('Artist-authored conceptual direction for this series', 'Dirección conceptual escrita por el artista para esta serie')) ?>"><?= series_h($series['conceptual_core'] ?? '') ?></textarea></label>
                                <label><?= series_h(t('Interpretive Limits', 'Límites interpretativos')) ?><textarea name="interpretive_limits" rows="5" placeholder="<?= series_h(t('What analysis and editorial text must not reduce, claim or infer', 'Qué no debe reducir, afirmar ni inferir el análisis y el texto editorial')) ?>"><?= series_h($series['interpretive_limits'] ?? '') ?></textarea></label>

                                <p><?= series_h(series_year_range_label($series['year_start'] ?? null, $series['year_end'] ?? null)) ?><?= (int)$series['artwork_count'] ?> <?= series_h(t('artworks', 'obras')) ?> · <?= (int)$series['mockup_count'] ?> mockups</p>

                                <div class="catalog-edit-form__actions">
                                    <button class="button-link" type="submit"><?= series_h(t('Save changes', 'Guardar cambios')) ?></button>
                                    <button class="button-link secondary danger" type="submit" form="delete-series-form"><?= series_h(t('Delete series', 'Eliminar serie')) ?></button>
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
                        <h2><?= $selectedSeries ? series_h(t('Works in this series', 'Obras en esta serie')) : series_h(t('Artwork assignment', 'Asignación de obras')) ?></h2>
                        <?php if ($selectedSeries): ?>
                            <p><?= count($displayedArtworks) ?> <?= count($displayedArtworks) === 1 ? series_h(t('artwork belongs', 'obra pertenece')) : series_h(t('artworks belong', 'obras pertenecen')) ?> <?= series_h(t('to', 'a')) ?> <?= series_h($selectedSeries['title']) ?>. <?= series_h(t('Drag the images to change their order; changing a series saves immediately.', 'Arrastrá las imágenes para cambiar su orden; cambiar de serie guarda de inmediato.')) ?></p>
                        <?php else: ?>
                            <p><?= series_h(t('Assign each canonical artwork to its series. All root views and mockups inherit the same relationship.', 'Asigná cada obra canónica a su serie. Todas las vistas raíz y mockups heredan la misma relación.')) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="series-artwork-heading-tools">
                        <?php if (!$selectedSeries && $displayedArtworks): ?>
                            <label class="series-artwork-filter">
                                <span><?= series_h(t('View by series', 'Ver por serie')) ?></span>
                                <select data-series-artwork-filter aria-label="<?= series_h(t('Filter artwork assignment by series', 'Filtrar asignación de obras por serie')) ?>">
                                    <option value="all"><?= series_h(t('All series', 'Todas las series')) ?></option>
                                    <?php foreach ($seriesRows as $series): ?>
                                        <option value="<?= (int)$series['id'] ?>"><?= series_h($series['title']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="none"><?= series_h(t('No series', 'Sin serie')) ?></option>
                                </select>
                            </label>
                        <?php endif; ?>
                        <span class="series-dependent-count" data-series-visible-count><?= count($displayedArtworks) ?> <?= count($displayedArtworks) === 1 ? series_h(t('artwork', 'obra')) : series_h(t('artworks', 'obras')) ?></span>
                    </div>
                </div>
                <p class="series-mobile-order-hint" data-series-order-hint<?= $selectedSeries ? '' : ' hidden' ?>><?= series_h(t('Hold an artwork image to change its order.', 'Mantené presionada una imagen de obra para cambiar su orden.')) ?></p>
                <?php if (!$displayedArtworks): ?>
                    <div class="empty-state series-dependent-empty">
                        <strong><?= series_h(t('No artworks are associated with', 'No hay obras asociadas a')) ?> <?= $selectedSeries ? series_h($selectedSeries['title']) : series_h(t('a series', 'una serie')) ?> <?= series_h(t('yet.', 'todavía.')) ?></strong>
                        <?php if ($selectedSeries): ?><a href="series.php"><?= series_h(t('Assign artworks from the Series overview', 'Asigná obras desde el resumen de Series')) ?></a><?php endif; ?>
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
                                        <select name="series_id" aria-label="<?= series_h(t('Artwork series', 'Serie de la obra')) ?>" onchange="this.form.requestSubmit()">
                                            <option value=""><?= series_h(t('NO SERIES', 'SIN SERIE')) ?></option>
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
                            <strong><?= series_h(t('No artworks match this series.', 'Ninguna obra coincide con esta serie.')) ?></strong>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php if ($selectedSeries && $seriesBilingualExperiment): ?>
                <form
                    class="series-delete-action"
                    method="post"
                    data-current-series-delete
                    onsubmit="return confirm(<?= json_encode(t('Delete this series? Its artworks and mockups will move to NO SERIES.', '¿Eliminar esta serie? Sus obras y mockups pasarán a SIN SERIE.')) ?>);"
                >
                    <input type="hidden" name="csrf" value="<?= series_h($_SESSION['series_csrf']) ?>">
                    <input type="hidden" name="action" value="delete_series">
                    <input type="hidden" name="series_id" value="<?= (int)$selectedSeries['id'] ?>">
                    <button class="button-link secondary danger" type="submit"><?= series_h(t('Delete series', 'Eliminar serie')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<?php if (!$selectedSeries): ?><script src="series_order.js?v=20260724-1"></script><?php endif; ?>
<script src="series_artwork_order.js?v=20260720-4"></script>
<?php if ($selectedSeries): ?><script src="series_header_upload.js?v=20260724-1"></script><?php endif; ?>
<?php if ($selectedSeries && $seriesBilingualExperiment): ?><script src="bilingual-editorial.js?v=20260724-1"></script><?php endif; ?>
</body>
</html>
