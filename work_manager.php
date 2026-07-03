<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$pdo = Database::connection();
$service = new ArtworkSheetService($pdo);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function wm_image_url(?string $file): string
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

function wm_related_ids(array $sheet): array
{
    $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
    $ids = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    $canonicalId = (int)($sheet['canonical_artwork_id'] ?? 0);
    if ($canonicalId > 0 && !in_array($canonicalId, $ids, true)) {
        array_unshift($ids, $canonicalId);
    }
    return $ids;
}

function wm_redirect(int $sheetId): void
{
    header('Location: work_manager.php?sheet_id=' . urlencode((string)$sheetId));
    exit;
}

function wm_delete_artworks(PDO $pdo, int $userId, array $artworkIds): void
{
    $artworkIds = array_values(array_unique(array_filter(array_map('intval', $artworkIds))));
    if (!$artworkIds) {
        throw new RuntimeException('Seleccioná al menos una obra para eliminar.');
    }

    $placeholders = implode(',', array_fill(0, count($artworkIds), '?'));
    $stmt = $pdo->prepare("SELECT id FROM artworks WHERE user_id = ? AND id IN ({$placeholders})");
    $stmt->execute(array_merge([$userId], $artworkIds));
    $ownedIds = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    if (!$ownedIds) {
        throw new RuntimeException('No se encontraron obras propias para eliminar.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = ?');
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll() as $sheetRow) {
            $sheetId = (int)$sheetRow['id'];
            $canonicalId = (int)$sheetRow['canonical_artwork_id'];
            $decoded = json_decode((string)($sheetRow['related_artwork_ids'] ?? ''), true);
            $relatedIds = is_array($decoded) ? $decoded : [];
            $relatedIds = array_values(array_diff(array_unique(array_filter(array_map('intval', $relatedIds))), $ownedIds));
            if (in_array($canonicalId, $ownedIds, true)) {
                $pdo->prepare('DELETE FROM artwork_sheets WHERE id = ? AND user_id = ?')->execute([$sheetId, $userId]);
            } else {
                $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);
            }
        }

        $ownedPlaceholders = implode(',', array_fill(0, count($ownedIds), '?'));
        $pdo->prepare("DELETE FROM mockup_sheets WHERE user_id = ? AND artwork_id IN ({$ownedPlaceholders})")
            ->execute(array_merge([$userId], $ownedIds));
        $pdo->prepare("DELETE FROM artworks WHERE user_id = ? AND id IN ({$ownedPlaceholders})")
            ->execute(array_merge([$userId], $ownedIds));
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$notice = (string)($_SESSION['work_manager_notice'] ?? '');
$error = (string)($_SESSION['work_manager_error'] ?? '');
unset($_SESSION['work_manager_notice'], $_SESSION['work_manager_error']);

$sheetId = max(0, (int)($_GET['sheet_id'] ?? $_POST['sheet_id'] ?? 0));
$query = trim((string)($_GET['q'] ?? ''));
$candidateQuery = trim((string)($_GET['candidate_q'] ?? ''));
$mockupQuery = trim((string)($_GET['mockup_q'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['create_artwork_id']) ? 'create_sheet_from_artwork' : (string)($_POST['action'] ?? '');
        $sheet = $sheetId > 0 ? $service->sheet($sheetId, $userId) : [];
        $relatedIds = $sheet ? wm_related_ids($sheet) : [];

        if ($action === 'save_sheet') {
            $service->saveArtworkSheet($sheetId, $userId, $_POST);
            $_SESSION['work_manager_notice'] = 'Ficha guardada.';
            wm_redirect($sheetId);
        }

        if ($action === 'create_sheet_from_artwork') {
            $artworkId = max(0, (int)($_POST['create_artwork_id'] ?? 0));
            $created = $service->sheetForArtwork($artworkId, $userId);
            $_SESSION['work_manager_notice'] = 'Ficha creada para obra #' . $artworkId . '.';
            wm_redirect((int)$created['id']);
        }

        if ($action === 'set_primary_artwork') {
            $primaryId = max(0, (int)($_POST['primary_artwork_id'] ?? 0));
            $artwork = $service->artwork($primaryId, $userId);
            if (!in_array($primaryId, $relatedIds, true)) {
                $relatedIds[] = $primaryId;
            }
            $source = (string)($artwork['root_file'] ?? $artwork['main_file'] ?? '');
            $pdo->prepare('
                UPDATE artwork_sheets
                SET canonical_artwork_id = ?, related_artwork_ids = ?, source_image_file = ?, updated_at = ?
                WHERE id = ? AND user_id = ?
            ')->execute([$primaryId, json_encode(array_values(array_unique($relatedIds)), JSON_UNESCAPED_SLASHES), $source, date('c'), $sheetId, $userId]);
            $_SESSION['work_manager_notice'] = 'Obra madre cambiada a #' . $primaryId . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'attach_artworks') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['artwork_ids'] ?? [])))));
            foreach ($ids as $id) {
                $service->artwork($id, $userId);
                $relatedIds[] = $id;
            }
            $relatedIds = array_values(array_unique(array_filter($relatedIds)));
            $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);
            $_SESSION['work_manager_notice'] = 'Vistas/obras asociadas: ' . count($ids) . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'detach_artworks') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['artwork_ids'] ?? [])))));
            $canonicalId = (int)($sheet['canonical_artwork_id'] ?? 0);
            $ids = array_values(array_diff($ids, [$canonicalId]));
            foreach ($ids as $id) {
                $service->sheetForArtwork($id, $userId);
            }
            $relatedIds = array_values(array_diff($relatedIds, $ids));
            if ($canonicalId > 0 && !in_array($canonicalId, $relatedIds, true)) {
                array_unshift($relatedIds, $canonicalId);
            }
            $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);
            $_SESSION['work_manager_notice'] = 'Obras desacopladas: ' . count($ids) . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'delete_artworks') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['artwork_ids'] ?? [])))));
            $activeCanonical = (int)($sheet['canonical_artwork_id'] ?? 0);
            wm_delete_artworks($pdo, $userId, $ids);
            $_SESSION['work_manager_notice'] = 'Obras eliminadas de la base: ' . implode(', ', $ids) . '. Los archivos no se borraron del disco.';
            if (in_array($activeCanonical, $ids, true)) {
                header('Location: work_manager.php');
                exit;
            }
            wm_redirect($sheetId);
        }

        if ($action === 'attach_mockups') {
            $files = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['mockup_files'] ?? [])))));
            foreach ($files as $file) {
                $service->attachMockupFile($sheetId, $userId, $file, 'Anexado desde administrador de trabajos.');
            }
            $_SESSION['work_manager_notice'] = 'Mockups anexados: ' . count($files) . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'detach_mockups') {
            $files = array_values(array_unique(array_filter(array_map(static fn($file): string => basename((string)$file), (array)($_POST['mockup_files'] ?? [])))));
            foreach ($files as $file) {
                $pdo->prepare('DELETE FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id = ? AND mockup_file = ?')
                    ->execute([$userId, $sheetId, $file]);
            }
            $_SESSION['work_manager_notice'] = 'Mockups quitados de esta ficha: ' . count($files) . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'move_mockups') {
            $targetSheetId = max(0, (int)($_POST['target_sheet_id'] ?? 0));
            $files = array_values(array_unique(array_filter(array_map(static fn($file): string => basename((string)$file), (array)($_POST['mockup_files'] ?? [])))));
            if ($targetSheetId <= 0 || $targetSheetId === $sheetId) {
                throw new RuntimeException('Elegí una ficha destino distinta.');
            }
            if (!$files) {
                throw new RuntimeException('Seleccioná al menos un mockup para mover.');
            }

            $targetSheet = $service->sheet($targetSheetId, $userId);
            $targetArtworkId = (int)$targetSheet['canonical_artwork_id'];
            foreach ($files as $file) {
                $stmt = $pdo->prepare('SELECT id FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id = ? AND mockup_file = ? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$userId, $sheetId, $file]);
                $mockupSheetId = (int)$stmt->fetchColumn();
                if ($mockupSheetId > 0) {
                    $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, artwork_id = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                        ->execute([$targetSheetId, $targetArtworkId, date('c'), $mockupSheetId, $userId]);
                } else {
                    $service->attachMockupFile($targetSheetId, $userId, $file, 'Movido desde administrador de trabajos.');
                }
            }
            $_SESSION['work_manager_notice'] = 'Mockups movidos a la ficha #' . $targetArtworkId . ': ' . count($files) . '.';
            wm_redirect($sheetId);
        }

        if ($action === 'delete_mockup_metadata') {
            $files = array_values(array_unique(array_filter(array_map(static fn($file): string => basename((string)$file), (array)($_POST['mockup_files'] ?? [])))));
            foreach ($files as $file) {
                $pdo->prepare('DELETE FROM mockup_sheets WHERE user_id = ? AND mockup_file = ?')->execute([$userId, $file]);
            }
            $_SESSION['work_manager_notice'] = 'Metadata de mockups eliminada: ' . count($files) . '.';
            wm_redirect($sheetId);
        }
    }
} catch (Throwable $e) {
    $_SESSION['work_manager_error'] = $e->getMessage();
    if ($sheetId > 0) {
        wm_redirect($sheetId);
    }
    header('Location: work_manager.php');
    exit;
}

$sheetRows = [];
$stmt = $pdo->prepare('
    SELECT s.*, a.final_title, a.root_file, a.main_file,
        (SELECT COUNT(*) FROM mockup_sheets ms WHERE ms.user_id = s.user_id AND ms.artwork_sheet_id = s.id) AS manual_mockups
    FROM artwork_sheets s
    INNER JOIN artworks a ON a.id = s.canonical_artwork_id
    WHERE s.user_id = :user_id
    ORDER BY s.updated_at DESC, s.created_at DESC
');
$stmt->execute(['user_id' => $userId]);
foreach ($stmt->fetchAll() as $row) {
    $title = trim((string)($row['title'] ?: $row['final_title'] ?: 'Obra sin título'));
    if ($query !== '' && stripos($title . ' #' . (string)$row['canonical_artwork_id'], $query) === false) {
        continue;
    }
    $sheetRows[] = $row;
}

if (!$sheetRows && $query === '') {
    $stmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = :user_id ORDER BY updated_at DESC, created_at DESC LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $firstArtworkId = (int)$stmt->fetchColumn();
    if ($firstArtworkId > 0) {
        $created = $service->sheetForArtwork($firstArtworkId, $userId);
        header('Location: work_manager.php?sheet_id=' . urlencode((string)$created['id']));
        exit;
    }
}

if ($sheetId <= 0 && $sheetRows) {
    $sheetId = (int)$sheetRows[0]['id'];
}

$activeSheet = [];
foreach ($sheetRows as $row) {
    if ((int)$row['id'] === $sheetId) {
        $activeSheet = $row;
        break;
    }
}
if (!$activeSheet && $sheetId > 0) {
    try {
        $activeSheet = $service->sheet($sheetId, $userId);
    } catch (Throwable $e) {
        $activeSheet = [];
    }
}

$activeArtwork = [];
$relatedIds = [];
$rootViews = [];
$mockups = [];
$candidateArtworks = [];
$mockupCandidates = [];

if ($activeSheet) {
    $activeArtwork = $service->artwork((int)$activeSheet['canonical_artwork_id'], $userId);
    $relatedIds = wm_related_ids($activeSheet);

    if ($relatedIds) {
        $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM artworks WHERE user_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$userId], $relatedIds));
        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int)$row['id']] = $row;
        }
        foreach ($relatedIds as $id) {
            if (isset($byId[$id])) {
                $rootViews[] = $byId[$id];
            }
        }
    }

    $mockups = $service->associatedMockups($activeSheet, $userId);

    $stmt = $pdo->prepare('
        SELECT id, final_title, root_file, main_file, created_at, updated_at
        FROM artworks
        WHERE user_id = :user_id
        ORDER BY updated_at DESC, created_at DESC
        LIMIT 240
    ');
    $stmt->execute(['user_id' => $userId]);
    foreach ($stmt->fetchAll() as $row) {
        if (in_array((int)$row['id'], $relatedIds, true)) {
            continue;
        }
        $haystack = strtolower((string)$row['id'] . ' ' . (string)$row['final_title'] . ' ' . (string)$row['root_file'] . ' ' . (string)$row['main_file']);
        if ($candidateQuery !== '' && !str_contains($haystack, strtolower($candidateQuery))) {
            continue;
        }
        $candidateArtworks[] = $row;
        if (count($candidateArtworks) >= 80) {
            break;
        }
    }

    $attachedFiles = [];
    foreach ($mockups as $mockup) {
        $attachedFiles[basename((string)($mockup['mockup_file'] ?? ''))] = true;
    }
    $registered = [];
    foreach (['mockup_generation_jobs', 'mockups', 'mockup_sheets'] as $table) {
        $stmt = $pdo->prepare("SELECT mockup_file FROM {$table} WHERE user_id = :user_id AND mockup_file IS NOT NULL AND mockup_file <> ''");
        $stmt->execute(['user_id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
            $registered[basename((string)$file)] = true;
        }
    }
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        foreach (glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $path) {
            $file = basename($path);
            if (isset($attachedFiles[$file])) {
                continue;
            }
            if ($mockupQuery !== '' && stripos($file, $mockupQuery) === false) {
                continue;
            }
            $mockupCandidates[] = [
                'file' => $file,
                'mtime' => filemtime($path) ?: 0,
                'registered' => isset($registered[$file]),
            ];
        }
    }
    usort($mockupCandidates, static fn(array $a, array $b): int => (int)$b['mtime'] <=> (int)$a['mtime']);
    $mockupCandidates = array_slice($mockupCandidates, 0, 160);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Administrador de trabajos - Mockup Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .manager-layout { display:grid; grid-template-columns:280px minmax(0, 1fr) 360px; gap:14px; align-items:start; }
        .manager-panel { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow); }
        .panel-pad { padding:14px; }
        .manager-list { max-height:calc(100vh - 210px); overflow:auto; }
        .sheet-row { display:grid; grid-template-columns:58px minmax(0, 1fr); gap:10px; padding:10px; border-bottom:1px solid var(--line); color:inherit; text-decoration:none; }
        .sheet-row.active { background:var(--surface-soft); box-shadow:inset 3px 0 0 var(--accent); }
        .thumb { width:100%; aspect-ratio:1; object-fit:cover; background:var(--surface-soft); border:1px solid var(--line); border-radius:var(--radius); display:block; }
        .thumb-empty { display:grid; place-items:center; color:var(--muted); font-size:11px; }
        .manager-title { font-size:14px; margin:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .meta { color:var(--muted); font-size:12px; line-height:1.4; overflow-wrap:anywhere; }
        .manager-toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
        .manager-toolbar input { max-width:220px; }
        .hero-work { display:grid; grid-template-columns:260px minmax(0,1fr); gap:16px; align-items:start; }
        .hero-work img, .hero-work .thumb-empty { width:100%; aspect-ratio:4 / 3; object-fit:cover; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); }
        .field-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .field { display:grid; gap:5px; margin-bottom:10px; }
        .field.wide { grid-column:1 / -1; }
        .field label { font-size:10px; text-transform:uppercase; color:var(--muted); font-weight:700; letter-spacing:.05em; }
        .field small { color:var(--muted); font-size:11px; }
        input[type="text"], select, textarea { width:100%; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); color:var(--ink); padding:9px; }
        textarea { resize:vertical; }
        .section-title { display:flex; justify-content:space-between; gap:10px; align-items:center; margin:18px 0 10px; }
        .section-title h2 { margin:0; font-size:18px; }
        .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; }
        .asset-card { position:relative; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); overflow:hidden; cursor:pointer; }
        .asset-card.is-locked { cursor:default; }
        .asset-card.is-selected { box-shadow:0 0 0 2px var(--accent); border-color:var(--accent); }
        .asset-card img, .asset-card .thumb-empty { width:100%; aspect-ratio:4 / 3; object-fit:cover; border-bottom:1px solid var(--line); background:var(--surface-soft); }
        .asset-body { padding:8px; display:grid; gap:4px; }
        .asset-check { position:absolute; top:7px; left:7px; width:22px; height:22px; display:grid; place-items:center; border:1px solid rgba(255,255,255,.8); border-radius:50%; background:rgba(255,255,255,.65); }
        .asset-check input { position:absolute; opacity:0; pointer-events:none; }
        .asset-check::after { content:"+"; font-size:16px; line-height:1; transform:rotate(45deg); color:rgba(30,30,30,.7); }
        .asset-card.is-selected .asset-check { background:rgba(163,126,85,.9); }
        .asset-card.is-selected .asset-check::after { content:"✓"; transform:none; color:#fff; font-size:14px; }
        .primary-badge { position:absolute; top:7px; left:7px; border:1px solid rgba(255,255,255,.8); border-radius:999px; background:rgba(255,255,255,.82); color:#334a7d; font-size:10px; font-weight:800; padding:4px 7px; }
        .badge { display:inline-flex; width:max-content; border:1px solid var(--line); border-radius:999px; padding:2px 7px; font-size:11px; color:var(--muted); background:var(--surface-soft); }
        .floating-actions { position:fixed; right:20px; bottom:16px; z-index:50; display:flex; flex-wrap:wrap; gap:6px; align-items:center; justify-content:flex-end; max-width:860px; padding:8px 10px; background:rgba(255,255,255,.94); border:1px solid var(--line); border-radius:999px; box-shadow:0 12px 34px rgba(0,0,0,.14); backdrop-filter:blur(8px); }
        .floating-actions .button-link { width:auto; padding:6px 9px; font-size:9px; line-height:1; min-height:0; box-shadow:none; }
        .floating-actions select { width:auto; max-width:260px; padding:6px 8px; font-size:11px; min-height:0; }
        .danger-action { background:transparent !important; color:#8f2f2f !important; border:1px solid rgba(143,47,47,.35) !important; }
        .workspace { padding-bottom:88px; }
        @media (max-width:1300px) { .manager-layout { grid-template-columns:240px minmax(0,1fr); } .right-panel { grid-column:1 / -1; } }
        @media (max-width:900px) { .manager-layout, .hero-work, .field-grid { grid-template-columns:1fr; } .manager-list { max-height:none; } .floating-actions { left:10px; right:10px; bottom:10px; border-radius:var(--radius); justify-content:center; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Administrador unificado: obra raíz, vistas, mockups y candidatos en una sola pantalla.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Administrador de trabajos</h1>
                    <p>Gestioná una ficha completa: madre, vistas raíz, mockups, candidatos y metadata.</p>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="manager-layout">
                <aside class="manager-panel">
                    <div class="panel-pad">
                        <form class="manager-toolbar" method="get">
                            <input type="text" name="q" value="<?= h($query) ?>" placeholder="Buscar ficha...">
                            <button class="button-link secondary" type="submit">Buscar</button>
                        </form>
                        <div class="meta"><?= h(count($sheetRows)) ?> fichas cargadas</div>
                    </div>
                    <div class="manager-list">
                        <?php foreach ($sheetRows as $row): ?>
                            <?php
                            $rowTitle = trim((string)($row['title'] ?: $row['final_title'] ?: 'Obra sin título'));
                            $rowImage = wm_image_url((string)($row['source_image_file'] ?: $row['root_file'] ?: $row['main_file'] ?: ''));
                            ?>
                            <a class="sheet-row <?= (int)$row['id'] === $sheetId ? 'active' : '' ?>" href="work_manager.php?sheet_id=<?= h($row['id']) ?>">
                                <?php if ($rowImage !== ''): ?><img class="thumb" src="<?= h($rowImage) ?>" alt="<?= h($rowTitle) ?>" loading="lazy"><?php else: ?><span class="thumb thumb-empty">Sin imagen</span><?php endif; ?>
                                <span>
                                    <strong class="manager-title"><?= h($rowTitle) ?></strong>
                                    <span class="meta">#<?= h($row['canonical_artwork_id']) ?> · <?= h(count(wm_related_ids($row))) ?> vistas · <?= h($row['manual_mockups']) ?> manuales</span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>

                <main>
                    <?php if ($activeSheet): ?>
                        <?php
                        $sourceFile = (string)($activeSheet['source_image_file'] ?: ($activeArtwork['root_file'] ?? $activeArtwork['main_file'] ?? ''));
                        $sourceImage = wm_image_url($sourceFile);
                        $activeTitle = trim((string)($activeSheet['title'] ?: $activeArtwork['final_title'] ?: 'Obra sin título'));
                        ?>
                        <section class="manager-panel panel-pad">
                            <div class="hero-work">
                                <div>
                                    <?php if ($sourceImage !== ''): ?><img src="<?= h($sourceImage) ?>" alt="<?= h($activeTitle) ?>"><?php else: ?><div class="thumb-empty">Sin imagen fuente</div><?php endif; ?>
                                    <p class="meta">Madre: obra #<?= h($activeSheet['canonical_artwork_id']) ?><br>Fuente: <code><?= h($activeSheet['source_image_file']) ?></code></p>
                                </div>
                                <form method="post">
                                    <input type="hidden" name="sheet_id" value="<?= h($sheetId) ?>">
                                    <div class="field-grid">
                                        <div class="field">
                                            <label>Título</label>
                                            <input type="text" name="title" value="<?= h($activeSheet['title']) ?>">
                                            <small>Metadata pública en inglés.</small>
                                        </div>
                                        <div class="field">
                                            <label>Subtítulo</label>
                                            <input type="text" name="subtitle" value="<?= h($activeSheet['subtitle']) ?>">
                                            <small>Subtítulo curatorial o SEO.</small>
                                        </div>
                                        <div class="field">
                                            <label>Imagen fuente</label>
                                            <input type="text" name="source_image_file" value="<?= h($activeSheet['source_image_file']) ?>">
                                            <small>Archivo que representa la ficha.</small>
                                        </div>
                                        <div class="field">
                                            <label>Estado</label>
                                            <select name="status">
                                                <?php foreach (['draft' => 'Borrador', 'reviewed' => 'Revisada', 'published' => 'Publicable'] as $value => $label): ?>
                                                    <option value="<?= h($value) ?>" <?= (string)$activeSheet['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small>Control interno de producción.</small>
                                        </div>
                                        <div class="field wide">
                                            <label>Notas curatoriales</label>
                                            <textarea name="user_notes" rows="3"><?= h($activeSheet['user_notes']) ?></textarea>
                                            <small>Podés escribir en español; la metadata generada debe salir en inglés.</small>
                                        </div>
                                        <div class="field wide">
                                            <label>Descripción</label>
                                            <textarea name="description" rows="5"><?= h($activeSheet['description']) ?></textarea>
                                            <small>Descripción pública de la obra, no del mockup.</small>
                                        </div>
                                        <div class="field wide">
                                            <label>Descripción breve</label>
                                            <textarea name="short_description" rows="2"><?= h($activeSheet['short_description']) ?></textarea>
                                            <small>Resumen para cards y previews.</small>
                                        </div>
                                        <div class="field">
                                            <label>Keywords</label>
                                            <textarea name="keywords" rows="3"><?= h($activeSheet['keywords']) ?></textarea>
                                            <small>Separadas por coma, en inglés.</small>
                                        </div>
                                        <div class="field">
                                            <label>Tags</label>
                                            <textarea name="tags" rows="3"><?= h($activeSheet['tags']) ?></textarea>
                                            <small>Separados por coma, en inglés.</small>
                                        </div>
                                        <div class="field">
                                            <label>Alt text</label>
                                            <textarea name="alt_text" rows="3"><?= h($activeSheet['alt_text']) ?></textarea>
                                            <small>Texto accesible en inglés.</small>
                                        </div>
                                        <div class="field">
                                            <label>Caption</label>
                                            <textarea name="caption" rows="3"><?= h($activeSheet['caption']) ?></textarea>
                                            <small>Pie de imagen público.</small>
                                        </div>
                                    </div>
                                    <input type="hidden" name="related_artwork_ids" value="<?= h(json_encode($relatedIds, JSON_UNESCAPED_SLASHES)) ?>">
                                    <button class="button-link" type="submit" name="action" value="save_sheet">Guardar ficha</button>
                                </form>
                            </div>
                        </section>

                        <form id="manager-actions-form" method="post">
                            <input type="hidden" name="sheet_id" value="<?= h($sheetId) ?>">
                            <section class="manager-panel panel-pad" style="margin-top:14px;">
                                <div class="section-title">
                                    <div>
                                        <h2>Vistas raíz / obras asociadas</h2>
                                        <p class="meta">Elegí la madre, desacoplá vistas incorrectas o eliminá registros técnicos.</p>
                                    </div>
                                    <span class="badge"><?= h(count($rootViews)) ?> vistas</span>
                                </div>
                                <?php if (count($rootViews) <= 1): ?>
                                    <p class="meta">Esta ficha solo tiene la obra madre. No hay vistas extra para desacoplar; anexá otra vista raíz o creá otra ficha desde candidatos.</p>
                                <?php endif; ?>
                                <div class="asset-grid">
                                    <?php foreach ($rootViews as $view): ?>
                                        <?php
                                        $viewImage = wm_image_url((string)($view['root_file'] ?: $view['main_file'] ?: ''));
                                        $isPrimary = (int)$view['id'] === (int)$activeSheet['canonical_artwork_id'];
                                        ?>
                                        <article class="asset-card <?= $isPrimary ? 'is-locked' : '' ?>" <?= $isPrimary ? '' : 'data-select-card' ?>>
                                            <?php if ($isPrimary): ?>
                                                <span class="primary-badge">MADRE</span>
                                            <?php else: ?>
                                                <label class="asset-check"><input type="checkbox" name="artwork_ids[]" value="<?= h($view['id']) ?>"></label>
                                            <?php endif; ?>
                                            <?php if ($viewImage !== ''): ?><img src="<?= h($viewImage) ?>" alt="Obra #<?= h($view['id']) ?>" loading="lazy"><?php else: ?><div class="thumb-empty">Sin imagen</div><?php endif; ?>
                                            <div class="asset-body">
                                                <strong>#<?= h($view['id']) ?> <?= $isPrimary ? '· madre' : '' ?></strong>
                                                <span class="meta"><?= h($view['final_title'] ?: basename((string)($view['root_file'] ?: $view['main_file'] ?: ''))) ?></span>
                                                <?php if (!$isPrimary): ?>
                                                    <button class="button-link secondary" type="submit" name="action" value="set_primary_artwork" onclick="this.form.primary_artwork_id.value='<?= h($view['id']) ?>';">Hacer madre</button>
                                                <?php endif; ?>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="primary_artwork_id" value="">

                                <div class="section-title">
                                    <div>
                                        <h2>Mockups asociados</h2>
                                        <p class="meta">Quitá un mockup de la ficha o borrá solo su metadata.</p>
                                    </div>
                                    <span class="badge"><?= h(count($mockups)) ?> mockups</span>
                                </div>
                                <div class="asset-grid">
                                    <?php foreach ($mockups as $mockup): ?>
                                        <?php
                                        $mockupFile = basename((string)($mockup['mockup_file'] ?? ''));
                                        $mockupImage = wm_image_url($mockupFile);
                                        ?>
                                        <article class="asset-card" data-select-card>
                                            <label class="asset-check"><input type="checkbox" name="mockup_files[]" value="<?= h($mockupFile) ?>"></label>
                                            <?php if ($mockupImage !== ''): ?><img src="<?= h($mockupImage) ?>" alt="<?= h($mockupFile) ?>" loading="lazy"><?php else: ?><div class="thumb-empty">Sin imagen</div><?php endif; ?>
                                            <div class="asset-body">
                                                <strong><?= h($mockupFile) ?></strong>
                                                <span class="meta"><?= h((string)($mockup['source_table'] ?? 'mockup')) ?></span>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </form>
                    <?php else: ?>
                        <section class="manager-panel panel-pad">
                            <h2>No hay fichas todavía</h2>
                            <p class="meta">Subí o seleccioná una obra para crear una ficha de trabajo.</p>
                        </section>
                    <?php endif; ?>
                </main>

                <aside class="manager-panel right-panel">
                    <div class="panel-pad">
                        <h2>Candidatos</h2>
                        <p class="meta">Anexá vistas raíz o mockups sin salir de esta pantalla.</p>
                        <form class="manager-toolbar" method="get">
                            <input type="hidden" name="sheet_id" value="<?= h($sheetId) ?>">
                            <input type="text" name="candidate_q" value="<?= h($candidateQuery) ?>" placeholder="Buscar obras...">
                            <input type="text" name="mockup_q" value="<?= h($mockupQuery) ?>" placeholder="Buscar mockups...">
                            <button class="button-link secondary" type="submit">Filtrar</button>
                        </form>
                    </div>
                    <?php if ($activeSheet): ?>
                        <form id="candidate-form" method="post" class="panel-pad">
                            <input type="hidden" name="sheet_id" value="<?= h($sheetId) ?>">
                            <div class="section-title">
                                <h2>Obras / vistas</h2>
                                <span class="badge"><?= h(count($candidateArtworks)) ?></span>
                            </div>
                            <div class="asset-grid">
                                <?php foreach ($candidateArtworks as $candidate): ?>
                                    <?php $candidateImage = wm_image_url((string)($candidate['root_file'] ?: $candidate['main_file'] ?: '')); ?>
                                    <article class="asset-card" data-select-card>
                                        <label class="asset-check"><input type="checkbox" name="artwork_ids[]" value="<?= h($candidate['id']) ?>"></label>
                                        <?php if ($candidateImage !== ''): ?><img src="<?= h($candidateImage) ?>" alt="Obra #<?= h($candidate['id']) ?>" loading="lazy"><?php else: ?><div class="thumb-empty">Sin imagen</div><?php endif; ?>
                                        <div class="asset-body">
                                            <strong>#<?= h($candidate['id']) ?></strong>
                                            <span class="meta"><?= h($candidate['final_title'] ?: basename((string)($candidate['root_file'] ?: $candidate['main_file'] ?: ''))) ?></span>
                                            <button class="button-link secondary" type="submit" name="create_artwork_id" value="<?= h($candidate['id']) ?>">Crear ficha</button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <button class="button-link secondary" type="submit" name="action" value="attach_artworks" style="margin-top:10px;">Anexar obras seleccionadas</button>

                            <div class="section-title">
                                <h2>Mockups disponibles</h2>
                                <span class="badge"><?= h(count($mockupCandidates)) ?></span>
                            </div>
                            <div class="asset-grid">
                                <?php foreach ($mockupCandidates as $candidate): ?>
                                    <?php $mockupImage = wm_image_url((string)$candidate['file']); ?>
                                    <article class="asset-card" data-select-card>
                                        <label class="asset-check"><input type="checkbox" name="mockup_files[]" value="<?= h($candidate['file']) ?>"></label>
                                        <?php if ($mockupImage !== ''): ?><img src="<?= h($mockupImage) ?>" alt="<?= h($candidate['file']) ?>" loading="lazy"><?php else: ?><div class="thumb-empty">Sin imagen</div><?php endif; ?>
                                        <div class="asset-body">
                                            <strong><?= h($candidate['file']) ?></strong>
                                            <span class="meta"><?= !empty($candidate['registered']) ? 'registrado en otra fuente' : 'no registrado' ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <button class="button-link secondary" type="submit" name="action" value="attach_mockups" style="margin-top:10px;">Anexar mockups seleccionados</button>
                        </form>
                    <?php endif; ?>
                </aside>
            </div>

            <?php if ($activeSheet): ?>
                <div class="floating-actions">
                    <span class="meta" data-selection-count>0 seleccionados</span>
                    <button class="button-link secondary" type="submit" form="manager-actions-form" name="action" value="detach_artworks">Desacoplar vistas</button>
                    <button class="button-link secondary danger-action" type="submit" form="manager-actions-form" name="action" value="delete_artworks" onclick="return confirm('Eliminar las obras seleccionadas de la base? No borra archivos físicos.');">Eliminar obras</button>
                    <select name="target_sheet_id" form="manager-actions-form" aria-label="Ficha destino para mover mockups">
                        <option value="">Mover mockups a...</option>
                        <?php foreach ($sheetRows as $row): ?>
                            <?php if ((int)$row['id'] === $sheetId) { continue; } ?>
                            <?php $rowTitle = trim((string)($row['title'] ?: $row['final_title'] ?: 'Obra sin título')); ?>
                            <option value="<?= h($row['id']) ?>">#<?= h($row['canonical_artwork_id']) ?> · <?= h($rowTitle) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button-link secondary" type="submit" form="manager-actions-form" name="action" value="move_mockups">Mover mockups</button>
                    <button class="button-link secondary" type="submit" form="manager-actions-form" name="action" value="detach_mockups">Quitar mockups</button>
                    <button class="button-link secondary danger-action" type="submit" form="manager-actions-form" name="action" value="delete_mockup_metadata" onclick="return confirm('Eliminar metadata de los mockups seleccionados?');">Borrar metadata mockup</button>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script>
function updateSelectionCount() {
    var count = document.querySelectorAll('#manager-actions-form input[type="checkbox"]:checked').length;
    document.querySelectorAll('[data-selection-count]').forEach(function (node) {
        node.textContent = count + (count === 1 ? ' seleccionado' : ' seleccionados');
    });
}
document.querySelectorAll('[data-select-card]').forEach(function (card) {
    var box = card.querySelector('input[type="checkbox"]');
    if (!box) {
        return;
    }
    card.addEventListener('click', function (event) {
        if (event.target.closest('button')) {
            return;
        }
        box.checked = !box.checked;
        card.classList.toggle('is-selected', box.checked);
        updateSelectionCount();
    });
});
document.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
    box.addEventListener('change', function () {
        var card = box.closest('[data-select-card]');
        if (card) {
            card.classList.toggle('is-selected', box.checked);
        }
        updateSelectionCount();
    });
});
updateSelectionCount();
</script>
</body>
</html>
