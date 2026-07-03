<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function artwork_sheet_image_url(?string $file): string
{
    $file = trim((string)$file);
    if ($file === '') {
        return '';
    }
    $base = basename(str_replace('\\', '/', $file));
    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $base)) {
        return 'media.php?file=' . urlencode($base);
    }
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $base)) {
        return 'uploads/' . rawurlencode($base);
    }
    return '';
}

function artwork_sheet_image_path(?string $file): string
{
    $file = trim((string)$file);
    if ($file === '') {
        return '';
    }
    $base = basename(str_replace('\\', '/', $file));
    $candidates = [
        RESULTS_DIR . DIRECTORY_SEPARATOR . $base,
        __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $base,
    ];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return '';
}

function artwork_sheet_load_image(string $path): mixed
{
    $mime = @mime_content_type($path) ?: '';
    return match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
}

function artwork_sheet_visual_key(string $path): string
{
    if ($path === '' || !is_file($path) || !extension_loaded('gd')) {
        return '';
    }
    $image = artwork_sheet_load_image($path);
    if (!$image) {
        return '';
    }
    $w = imagesx($image);
    $h = imagesy($image);
    if ($w < 8 || $h < 8) {
        imagedestroy($image);
        return '';
    }
    $size = 24;
    $thumb = imagecreatetruecolor($size, $size);
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $size, $size, $w, $h);
    imagedestroy($image);

    $hist = array_fill(0, 64, 0);
    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $rgb = imagecolorat($thumb, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $bin = intdiv($r, 64) * 16 + intdiv($g, 64) * 4 + intdiv($b, 64);
            $hist[$bin]++;
        }
    }
    imagedestroy($thumb);
    arsort($hist);
    $top = array_slice(array_keys($hist), 0, 5);
    $ratioBucket = $w > 0 && $h > 0 ? (string)round(($w / $h) * 10) : '0';
    return $ratioBucket . ':' . implode('-', $top);
}

function artwork_sheet_text_key(array $artwork): string
{
    $text = trim((string)($artwork['sheet_title'] ?: $artwork['final_title'] ?: ''));
    if ($text === '') {
        return '';
    }
    $text = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $text));
    $text = trim($text, '-');
    return strlen($text) >= 6 && !str_starts_with($text, 'obra-sin-titulo') ? $text : '';
}

$pdo = Database::connection();
$service = new ArtworkSheetService($pdo);
$notice = (string)($_SESSION['artwork_sheets_notice'] ?? '');
$error = (string)($_SESSION['artwork_sheets_error'] ?? '');
unset($_SESSION['artwork_sheets_notice'], $_SESSION['artwork_sheets_error']);

$limit = (string)($_GET['limit'] ?? '1000');
if (!in_array($limit, ['240', '500', '1000', '2000', '5000', 'all'], true)) {
    $limit = '500';
}
$page = max(1, (int)($_GET['page'] ?? 1));
$showGrouped = (string)($_GET['show_grouped'] ?? '') === '1';
$suggestGroups = (string)($_GET['suggest'] ?? '') === '1';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'merge_artworks') {
        $primaryId = max(0, (int)($_POST['primary_artwork_id'] ?? 0));
        $selectedIds = array_map('intval', (array)($_POST['selected_artwork_ids'] ?? []));
        if ($primaryId <= 0) {
            throw new RuntimeException('Elegí una obra madre para unir las fichas.');
        }
        if (!in_array($primaryId, $selectedIds, true)) {
            $selectedIds[] = $primaryId;
        }
        if (count($selectedIds) < 2) {
            throw new RuntimeException('Seleccioná al menos dos obras para unir.');
        }
        $mergedSheet = $service->mergeArtworkIds($primaryId, (int)$user['id'], $selectedIds);
        $_SESSION['artwork_sheets_notice'] = 'Obras unidas en la ficha madre #' . $primaryId . '. IDs relacionados: ' . implode(', ', json_decode((string)$mergedSheet['related_artwork_ids'], true));
        header('Location: artwork_sheets.php?show_grouped=' . ($showGrouped ? '1' : '0') . '&limit=' . urlencode($limit) . '&page=' . urlencode((string)$page));
        exit;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_artworks') {
        $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_artwork_ids'] ?? [])))));
        if (!$selectedIds) {
            throw new RuntimeException('Seleccioná al menos una obra para eliminar.');
        }

        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
        $params = array_merge([(int)$user['id']], $selectedIds);

        $stmt = $pdo->prepare("SELECT id FROM artworks WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute($params);
        $ownedIds = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        if (!$ownedIds) {
            throw new RuntimeException('No se encontraron obras propias para eliminar.');
        }

        $pdo->beginTransaction();
        try {
            $ownedPlaceholders = implode(',', array_fill(0, count($ownedIds), '?'));
            $stmt = $pdo->prepare('SELECT id, canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = ?');
            $stmt->execute([(int)$user['id']]);
            foreach ($stmt->fetchAll() as $sheetRow) {
                $sheetId = (int)$sheetRow['id'];
                $canonicalId = (int)$sheetRow['canonical_artwork_id'];
                $decoded = json_decode((string)($sheetRow['related_artwork_ids'] ?? ''), true);
                $relatedIds = is_array($decoded) ? $decoded : [];
                $relatedIds = array_values(array_diff(array_unique(array_filter(array_map('intval', $relatedIds))), $ownedIds));
                if (in_array($canonicalId, $ownedIds, true)) {
                    $pdo->prepare('DELETE FROM artwork_sheets WHERE id = ? AND user_id = ?')->execute([$sheetId, (int)$user['id']]);
                } else {
                    $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                        ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, (int)$user['id']]);
                }
            }

            $pdo->prepare("DELETE FROM mockup_sheets WHERE user_id = ? AND artwork_id IN ({$ownedPlaceholders})")
                ->execute(array_merge([(int)$user['id']], $ownedIds));
            $pdo->prepare("DELETE FROM artworks WHERE user_id = ? AND id IN ({$ownedPlaceholders})")
                ->execute(array_merge([(int)$user['id']], $ownedIds));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $_SESSION['artwork_sheets_notice'] = 'Ficha(s) y obra(s) eliminadas: ' . implode(', ', $ownedIds) . '. Los archivos de imagen no se borraron del disco.';
        header('Location: artwork_sheets.php?show_grouped=' . ($showGrouped ? '1' : '0') . '&limit=' . urlencode($limit) . '&page=' . urlencode((string)$page));
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['artwork_sheets_error'] = $e->getMessage();
    header('Location: artwork_sheets.php?show_grouped=' . ($showGrouped ? '1' : '0') . '&limit=' . urlencode($limit) . '&page=' . urlencode((string)$page));
    exit;
}

$stmt = $pdo->prepare('SELECT canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = :user_id');
$stmt->execute(['user_id' => (int)$user['id']]);
$groupedChildren = [];
$groupCounts = [];
foreach ($stmt->fetchAll() as $sheetRow) {
    $canonicalId = (int)($sheetRow['canonical_artwork_id'] ?? 0);
    $decoded = json_decode((string)($sheetRow['related_artwork_ids'] ?? ''), true);
    $ids = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheetRow['related_artwork_ids'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    if ($canonicalId > 0 && count($ids) > 1) {
        $groupCounts[$canonicalId] = count($ids);
    }
    foreach ($ids as $relatedId) {
        if ($canonicalId > 0 && $relatedId > 0 && $relatedId !== $canonicalId) {
            $groupedChildren[$relatedId] = $canonicalId;
        }
    }
}
$hiddenGroupedCount = count($groupedChildren);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE user_id = :user_id');
$stmt->execute(['user_id' => (int)$user['id']]);
$totalPhysicalArtworks = (int)$stmt->fetchColumn();
$visibleTotalArtworks = $showGrouped ? $totalPhysicalArtworks : max(0, $totalPhysicalArtworks - $hiddenGroupedCount);

$sql = "
    SELECT
        a.id,
        a.root_file,
        a.main_file,
        a.final_title,
        a.subtitle,
        a.status,
        a.created_at,
        s.id AS sheet_id,
        s.title AS sheet_title,
        s.status AS sheet_status,
        (
            SELECT COUNT(*)
            FROM mockup_generation_jobs j
            WHERE j.user_id = a.user_id
            AND j.artwork_id = a.id
            AND j.mockup_file IS NOT NULL
            AND j.mockup_file != ''
        ) AS generated_mockups
    FROM artworks a
    LEFT JOIN artwork_sheets s
        ON s.user_id = a.user_id
        AND s.canonical_artwork_id = a.id
    WHERE a.user_id = :user_id
";
$params = ['user_id' => (int)$user['id']];
if (!$showGrouped && $groupedChildren) {
    $excludedIds = array_keys($groupedChildren);
    $placeholders = [];
    foreach ($excludedIds as $index => $id) {
        $key = 'excluded_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = (int)$id;
    }
    $sql .= ' AND a.id NOT IN (' . implode(',', $placeholders) . ')';
}
$sql .= ' ORDER BY a.updated_at DESC, a.created_at DESC';
if ($limit !== 'all') {
    $perPage = max(1, (int)$limit);
    $totalPages = max(1, (int)ceil($visibleTotalArtworks / $perPage));
    $page = min($page, $totalPages);
    $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
} else {
    $totalPages = 1;
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$artworks = $stmt->fetchAll();
$pageStart = $visibleTotalArtworks > 0 ? (($limit === 'all' ? 0 : (($page - 1) * (int)$limit)) + 1) : 0;
$pageEnd = $limit === 'all' ? $visibleTotalArtworks : min($visibleTotalArtworks, ($page - 1) * (int)$limit + count($artworks));

$suggestedGroups = [];
if ($suggestGroups) {
    foreach ($artworks as $artwork) {
        $textKey = artwork_sheet_text_key($artwork);
        $imagePath = artwork_sheet_image_path((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
        $visualKey = artwork_sheet_visual_key($imagePath);
        $key = $textKey !== '' ? 'title:' . $textKey : ($visualKey !== '' ? 'visual:' . $visualKey : '');
        if ($key === '') {
            continue;
        }
        $suggestedGroups[$key][] = $artwork;
    }
    $suggestedGroups = array_values(array_filter($suggestedGroups, static fn(array $group): bool => count($group) >= 2));
    usort($suggestedGroups, static fn(array $a, array $b): int => count($b) <=> count($a));
    $suggestedGroups = array_slice($suggestedGroups, 0, 24);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Fichas de obra - Mockup Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .sheet-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:16px; }
        .sheet-card { position:relative; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); overflow:hidden; display:flex; flex-direction:column; min-height:100%; cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease; }
        .sheet-card:hover { border-color:var(--accent); box-shadow:0 12px 28px rgba(0,0,0,.12); }
        .sheet-card.is-selected { border-color:var(--accent); box-shadow:0 0 0 2px var(--accent), var(--shadow); }
        .sheet-card.is-primary { border-color:#334a7d; box-shadow:0 0 0 2px #334a7d, var(--shadow); }
        .sheet-card.drop-target { border-color:#334a7d; box-shadow:0 0 0 3px #334a7d, 0 18px 36px rgba(51,74,125,.25); }
        .sheet-card img { width:100%; aspect-ratio:4 / 3; object-fit:cover; background:var(--surface-soft); border-bottom:1px solid var(--line); }
        .sheet-card-body { padding:14px; display:grid; gap:8px; }
        .sheet-card h2 { font-size:16px; margin:0; }
        .meta { color:var(--muted); font-size:12px; line-height:1.45; }
        .empty-image { aspect-ratio:4 / 3; display:grid; place-items:center; color:var(--muted); background:var(--surface-soft); border-bottom:1px solid var(--line); }
        .workspace { padding-bottom:86px; }
        .sheet-actions { position:fixed; right:24px; bottom:18px; z-index:40; display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:flex-end; max-width:620px; background:rgba(255,255,255,.94); border:1px solid var(--line); border-radius:999px; box-shadow:0 12px 34px rgba(0,0,0,.14); padding:8px 10px; backdrop-filter:blur(8px); }
        .sheet-actions .button-link { display:inline-flex; width:auto; flex:0 0 auto; padding:6px 9px; font-size:9px; min-height:0; line-height:1; border-radius:6px; box-shadow:none; }
        .sheet-actions .danger-action { background:transparent !important; color:#8f2f2f; border:1px solid rgba(143,47,47,.35); }
        .workflow-status { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:8px; margin:0 0 14px; }
        .status-pill { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); padding:9px 10px; }
        .status-pill strong { display:block; font-size:16px; line-height:1.1; color:var(--ink); }
        .status-pill span { display:block; margin-top:3px; color:var(--muted); font-size:10px; text-transform:uppercase; letter-spacing:.04em; }
        .card-pick { position:absolute; top:8px; left:8px; display:flex; gap:6px; align-items:center; }
        .quick-mark { width:22px; height:22px; display:grid; place-items:center; background:rgba(255,255,255,.42); border:1px solid rgba(255,255,255,.72); border-radius:50%; color:rgba(40,40,40,.7); backdrop-filter:blur(2px); cursor:pointer; }
        .quick-mark::after { content:"+"; font-size:16px; line-height:1; transform:rotate(45deg); }
        .sheet-card.is-selected .quick-mark { background:rgba(163,126,85,.86); color:white; }
        .sheet-card.is-selected .quick-mark::after { content:"✓"; transform:none; font-size:14px; }
        .primary-mark { width:22px; height:22px; display:grid; place-items:center; background:rgba(255,255,255,.72); border:1px solid rgba(255,255,255,.86); border-radius:50%; color:#334a7d; font-size:13px; font-weight:800; opacity:.72; cursor:pointer; }
        .sheet-card.is-primary .primary-mark { background:#334a7d; color:white; opacity:1; }
        .card-pick input { position:absolute; opacity:0; width:1px; height:1px; pointer-events:none; }
        .group-badge { display:inline-flex; width:max-content; border:1px solid var(--line); border-radius:999px; padding:3px 8px; color:var(--muted); font-size:11px; background:var(--surface-soft); }
        .toolbar-form { display:flex; flex-wrap:wrap; gap:10px; align-items:end; margin-bottom:16px; background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:12px; }
        .toolbar-field { display:grid; gap:6px; min-width:180px; }
        .toolbar-field label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        .toolbar-field select { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:10px; }
        .pager { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
        .suggest-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:12px; margin-bottom:16px; }
        .suggest-card { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); padding:12px; display:grid; gap:10px; }
        .suggest-thumbs { display:flex; gap:6px; overflow:hidden; }
        .suggest-thumbs img { width:52px; height:52px; object-fit:cover; border-radius:var(--radius); border:1px solid var(--line); background:var(--surface-soft); }
        @media (max-width:760px) { .sheet-actions { left:10px; right:10px; bottom:10px; border-radius:var(--radius); justify-content:center; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Fichas curatoriales: obra raíz y mockups asociados.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Fichas de obra</h1>
                    <p>Elegí una obra para crear o editar su ficha completa y las fichas de sus mockups.</p>
                </div>
                <div class="sheet-actions" aria-label="Acciones de selección">
                    <span class="meta" data-selection-count>0 seleccionadas</span>
                    <button class="button-link secondary" type="submit" form="artwork-actions-form" name="action" value="merge_artworks">Unir</button>
                    <button class="button-link secondary" type="button" data-select-visible="1">Visibles</button>
                    <button class="button-link secondary" type="button" data-clear-selection="1">Limpiar</button>
                    <a class="button-link secondary" href="artwork_sheets.php?limit=<?= h($limit) ?>&page=<?= h($page) ?>&show_grouped=<?= $showGrouped ? '0' : '1' ?>"><?= $showGrouped ? 'Ocultar unidas' : 'Ver unidas' ?></a>
                    <button class="button-link secondary danger-action" type="submit" form="artwork-actions-form" name="action" value="delete_artworks" onclick="return confirm('Esto eliminará la ficha y el registro de obra seleccionados de la base. No borra archivos de imagen del disco. ¿Continuar?');">Eliminar</button>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="workflow-status">
                <div class="status-pill"><strong><?= h($totalPhysicalArtworks) ?></strong><span>obras físicas</span></div>
                <div class="status-pill"><strong><?= h($hiddenGroupedCount) ?></strong><span>unidas ocultas</span></div>
                <div class="status-pill"><strong><?= h($visibleTotalArtworks) ?></strong><span>en el listado actual</span></div>
                <div class="status-pill"><strong><?= h($pageStart) ?>-<?= h($pageEnd) ?></strong><span>rango de esta página</span></div>
            </div>

            <form class="toolbar-form" method="get">
                <div class="toolbar-field">
                    <label>Mostrar</label>
                    <select name="limit">
                        <option value="500" <?= $limit === '500' ? 'selected' : '' ?>>500 por página</option>
                        <option value="240" <?= $limit === '240' ? 'selected' : '' ?>>240 por página</option>
                        <option value="1000" <?= $limit === '1000' ? 'selected' : '' ?>>1000 por página</option>
                        <option value="2000" <?= $limit === '2000' ? 'selected' : '' ?>>2000 por página</option>
                        <option value="5000" <?= $limit === '5000' ? 'selected' : '' ?>>5000 por página</option>
                        <option value="all" <?= $limit === 'all' ? 'selected' : '' ?>>Todas</option>
                    </select>
                </div>
                <label class="meta" style="display:flex; gap:6px; align-items:center;">
                    <input type="checkbox" name="show_grouped" value="1" <?= $showGrouped ? 'checked' : '' ?>>
                    incluir obras unidas como hijas
                </label>
                <input type="hidden" name="page" value="1">
                <button class="button-link secondary" type="submit">Aplicar</button>
                <a class="button-link secondary" href="artwork_sheets.php?<?= h(http_build_query(['limit' => $limit, 'page' => $page, 'show_grouped' => $showGrouped ? '1' : '0', 'suggest' => $suggestGroups ? '0' : '1'])) ?>"><?= $suggestGroups ? 'Ocultar sugerencias' : 'Sugerir grupos' ?></a>
                <span class="meta">Filtro actual: <?= $showGrouped ? 'incluye hijas unidas' : 'oculta hijas unidas' ?> · tarjetas cargadas: <?= h(count($artworks)) ?></span>
            </form>

            <?php if ($suggestGroups): ?>
                <div class="suggest-grid">
                    <?php foreach ($suggestedGroups as $index => $group): ?>
                        <?php $primary = $group[0]; ?>
                        <article class="suggest-card">
                            <strong>Grupo sugerido <?= h($index + 1) ?> · <?= h(count($group)) ?> obras</strong>
                            <div class="suggest-thumbs">
                                <?php foreach (array_slice($group, 0, 6) as $item): ?>
                                    <?php $thumb = artwork_sheet_image_url((string)($item['root_file'] ?: $item['main_file'] ?: '')); ?>
                                    <?php if ($thumb !== ''): ?><img src="<?= h($thumb) ?>" alt="Obra #<?= h($item['id']) ?>" loading="lazy"><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="meta">Madre propuesta: #<?= h($primary['id']) ?>. Revisá antes de unir.</div>
                            <button class="button-link secondary" type="button" data-suggest-group="<?= h(implode(',', array_map(static fn(array $item): string => (string)$item['id'], $group))) ?>" data-suggest-primary="<?= h($primary['id']) ?>">Seleccionar este grupo</button>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <?php
                $basePager = [
                    'limit' => $limit,
                    'show_grouped' => $showGrouped ? '1' : '0',
                ];
                $prevUrl = 'artwork_sheets.php?' . http_build_query($basePager + ['page' => max(1, $page - 1)]);
                $nextUrl = 'artwork_sheets.php?' . http_build_query($basePager + ['page' => min($totalPages, $page + 1)]);
                ?>
                <div class="pager">
                    <a class="button-link secondary" href="<?= h($prevUrl) ?>">Anterior</a>
                    <span class="meta">Página <?= h($page) ?> de <?= h($totalPages) ?></span>
                    <a class="button-link secondary" href="<?= h($nextUrl) ?>">Siguiente</a>
                </div>
            <?php endif; ?>

            <form id="artwork-actions-form" method="post">
                <div class="sheet-grid">
                <?php foreach ($artworks as $artwork): ?>
                    <?php
                    $imageUrl = artwork_sheet_image_url((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
                    $title = trim((string)($artwork['sheet_title'] ?: $artwork['final_title'] ?: 'Obra sin título'));
                    ?>
                    <article class="sheet-card" draggable="true" data-artwork-id="<?= h($artwork['id']) ?>">
                        <div class="card-pick">
                            <label class="quick-mark" title="Seleccionada"><input type="checkbox" name="selected_artwork_ids[]" value="<?= h($artwork['id']) ?>"></label>
                            <label class="primary-mark" title="Ficha madre"><input type="radio" name="primary_artwork_id" value="<?= h($artwork['id']) ?>">M</label>
                        </div>
                        <?php if ($imageUrl !== ''): ?>
                            <img src="<?= h($imageUrl) ?>" alt="<?= h($title) ?>">
                        <?php else: ?>
                            <div class="empty-image">Sin imagen</div>
                        <?php endif; ?>
                        <div class="sheet-card-body">
                            <h2><?= h($title) ?></h2>
                            <div class="meta">
                                Obra #<?= h($artwork['id']) ?> · <?= h($artwork['sheet_id'] ? 'ficha creada' : 'sin ficha') ?><br>
                                Mockups detectados: <?= h($artwork['generated_mockups']) ?>
                            </div>
                            <?php if (isset($groupCounts[(int)$artwork['id']])): ?>
                                <span class="group-badge"><?= h($groupCounts[(int)$artwork['id']]) ?> IDs unidos</span>
                            <?php elseif (isset($groupedChildren[(int)$artwork['id']])): ?>
                                <span class="group-badge">Unida a #<?= h($groupedChildren[(int)$artwork['id']]) ?></span>
                            <?php endif; ?>
                            <a class="button-link secondary" href="artwork_sheet.php?id=<?= h($artwork['id']) ?>">Abrir ficha</a>
                        </div>
                    </article>
                <?php endforeach; ?>
                </div>
            </form>
        </div>
    </main>
</div>
<script>
function updateArtworkSelectionCount() {
    var count = document.querySelectorAll('.sheet-card input[name="selected_artwork_ids[]"]:checked').length;
    document.querySelectorAll('[data-selection-count]').forEach(function (node) {
        node.textContent = count + (count === 1 ? ' seleccionada' : ' seleccionadas');
    });
}
document.querySelectorAll('input[name="primary_artwork_id"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var card = radio.closest('.sheet-card');
        var checkbox = card ? card.querySelector('input[name="selected_artwork_ids[]"]') : null;
        if (checkbox) {
            checkbox.checked = true;
            card.classList.add('is-selected');
        }
        document.querySelectorAll('.sheet-card').forEach(function (otherCard) {
            otherCard.classList.remove('is-primary');
        });
        if (card) {
            card.classList.add('is-primary');
        }
        updateArtworkSelectionCount();
    });
});
document.querySelectorAll('.quick-mark').forEach(function (mark) {
    mark.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var card = mark.closest('.sheet-card');
        var checkbox = card ? card.querySelector('input[name="selected_artwork_ids[]"]') : null;
        if (!checkbox || !card) {
            return;
        }
        checkbox.checked = !checkbox.checked;
        card.classList.toggle('is-selected', checkbox.checked);
        updateArtworkSelectionCount();
    });
});
document.querySelectorAll('.primary-mark').forEach(function (mark) {
    mark.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var card = mark.closest('.sheet-card');
        var radio = card ? card.querySelector('input[name="primary_artwork_id"]') : null;
        if (!radio) {
            return;
        }
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    });
});
document.querySelectorAll('.sheet-card').forEach(function (card) {
    var checkbox = card.querySelector('input[name="selected_artwork_ids[]"]');
    var radio = card.querySelector('input[name="primary_artwork_id"]');
    var openLink = card.querySelector('a');
    if (!checkbox) {
        return;
    }
    if (checkbox.checked) {
        card.classList.add('is-selected');
    }
    card.addEventListener('click', function (event) {
        if (event.target === openLink || (openLink && openLink.contains(event.target))) {
            return;
        }
        checkbox.checked = !checkbox.checked;
        card.classList.toggle('is-selected', checkbox.checked);
        updateArtworkSelectionCount();
    });
    card.addEventListener('dblclick', function (event) {
        if (event.target === openLink || (openLink && openLink.contains(event.target))) {
            return;
        }
        if (radio) {
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
});
var paintSelecting = false;
var paintValue = true;
document.querySelectorAll('.sheet-card').forEach(function (card) {
    var checkbox = card.querySelector('input[name="selected_artwork_ids[]"]');
    if (!checkbox) {
        return;
    }
    card.addEventListener('mousedown', function (event) {
        if (event.button !== 0 || event.target.closest('a')) {
            return;
        }
        paintSelecting = true;
        paintValue = !checkbox.checked;
        checkbox.checked = paintValue;
        card.classList.toggle('is-selected', paintValue);
        updateArtworkSelectionCount();
        event.preventDefault();
    });
    card.addEventListener('mouseenter', function () {
        if (!paintSelecting) {
            return;
        }
        checkbox.checked = paintValue;
        card.classList.toggle('is-selected', paintValue);
        updateArtworkSelectionCount();
    });
});
document.addEventListener('mouseup', function () {
    paintSelecting = false;
});
document.querySelectorAll('[data-select-visible]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelectorAll('.sheet-card input[name="selected_artwork_ids[]"]').forEach(function (checkbox) {
            checkbox.checked = true;
            var card = checkbox.closest('.sheet-card');
            if (card) {
                card.classList.add('is-selected');
            }
        });
        updateArtworkSelectionCount();
    });
});
document.querySelectorAll('[data-clear-selection]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.querySelectorAll('.sheet-card').forEach(function (card) {
            card.classList.remove('is-selected', 'is-primary');
            card.querySelectorAll('input').forEach(function (input) {
                input.checked = false;
            });
        });
        updateArtworkSelectionCount();
    });
});
document.querySelectorAll('[data-suggest-group]').forEach(function (button) {
    button.addEventListener('click', function () {
        var ids = (button.getAttribute('data-suggest-group') || '').split(',');
        var primaryId = button.getAttribute('data-suggest-primary') || '';
        document.querySelectorAll('.sheet-card').forEach(function (card) {
            var id = card.getAttribute('data-artwork-id') || '';
            var checkbox = card.querySelector('input[name="selected_artwork_ids[]"]');
            var radio = card.querySelector('input[name="primary_artwork_id"]');
            if (!checkbox) {
                return;
            }
            var selected = ids.indexOf(id) !== -1;
            checkbox.checked = selected;
            card.classList.toggle('is-selected', selected);
            card.classList.toggle('is-primary', id === primaryId);
            if (radio) {
                radio.checked = id === primaryId;
            }
        });
        updateArtworkSelectionCount();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
var draggedArtworkId = '';
document.querySelectorAll('.sheet-card').forEach(function (card) {
    card.addEventListener('dragstart', function (event) {
        draggedArtworkId = card.getAttribute('data-artwork-id') || '';
        var checkbox = card.querySelector('input[name="selected_artwork_ids[]"]');
        if (checkbox && !checkbox.checked) {
            checkbox.checked = true;
            card.classList.add('is-selected');
            updateArtworkSelectionCount();
        }
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedArtworkId);
    });
    card.addEventListener('dragover', function (event) {
        event.preventDefault();
        card.classList.add('drop-target');
        event.dataTransfer.dropEffect = 'move';
    });
    card.addEventListener('dragleave', function () {
        card.classList.remove('drop-target');
    });
    card.addEventListener('drop', function (event) {
        event.preventDefault();
        document.querySelectorAll('.sheet-card.drop-target').forEach(function (targetCard) {
            targetCard.classList.remove('drop-target');
        });
        var primaryId = card.getAttribute('data-artwork-id') || '';
        var selectedIds = Array.from(document.querySelectorAll('input[name="selected_artwork_ids[]"]:checked')).map(function (input) {
            return input.value;
        });
        if (draggedArtworkId && selectedIds.indexOf(draggedArtworkId) === -1) {
            selectedIds.push(draggedArtworkId);
        }
        if (primaryId && selectedIds.indexOf(primaryId) === -1) {
            selectedIds.push(primaryId);
        }
        selectedIds = Array.from(new Set(selectedIds));
        if (!primaryId || selectedIds.length < 2) {
            return;
        }
        if (!confirm('Unir ' + selectedIds.length + ' obras usando #' + primaryId + ' como ficha madre?')) {
            return;
        }
        var form = document.createElement('form');
        form.method = 'post';
        form.action = window.location.href;
        var action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'merge_artworks';
        form.appendChild(action);
        var primary = document.createElement('input');
        primary.type = 'hidden';
        primary.name = 'primary_artwork_id';
        primary.value = primaryId;
        form.appendChild(primary);
        selectedIds.forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_artwork_ids[]';
            input.value = id;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    });
});
updateArtworkSelectionCount();
</script>
</body>
</html>
