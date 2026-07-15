<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$pdo = Database::connection();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function fichas_image_url(string $file): string
{
    $base = basename(str_replace('\\', '/', $file));
    if ($base === '') {
        return '';
    }
    if (is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $base)) {
        return 'media.php?file=' . urlencode($base);
    }
    if (is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $base)) {
        return 'uploads/' . rawurlencode($base);
    }
    return '';
}

$notice = (string)($_SESSION['fichas_notice'] ?? '');
$error = (string)($_SESSION['fichas_error'] ?? '');
unset($_SESSION['fichas_notice'], $_SESSION['fichas_error']);
$query = trim((string)($_GET['q'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'sync_new') {
        $embeddingService = new ArtworkEmbeddingService($pdo);
        $sheetService = new ArtworkSheetService($pdo);
        $embedded = $embeddingService->embedMissing($userId, 30);

        // Obras sin ficha: asignar a la más parecida si supera el umbral.
        $stmtSheets = $pdo->prepare('SELECT id, canonical_artwork_id, related_artwork_ids FROM artwork_sheets WHERE user_id = ?');
        $stmtSheets->execute([$userId]);
        $inSheet = [];
        $canonicalBySheet = [];
        foreach ($stmtSheets->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $canonicalBySheet[(int)$row['id']] = (int)$row['canonical_artwork_id'];
            $inSheet[(int)$row['canonical_artwork_id']] = true;
            foreach ((array)json_decode((string)$row['related_artwork_ids'], true) as $id) {
                $inSheet[(int)$id] = true;
            }
        }

        $stmtArts = $pdo->prepare('SELECT id FROM artworks WHERE user_id = ? ORDER BY id');
        $stmtArts->execute([$userId]);
        $assigned = 0;
        $pendingReview = 0;
        foreach ($stmtArts->fetchAll(PDO::FETCH_COLUMN) as $artworkId) {
            $artworkId = (int)$artworkId;
            if (isset($inSheet[$artworkId])) {
                continue;
            }
            $best = $embeddingService->bestSheetFor($artworkId, $userId);
            if ($best !== null && $best['similarity'] >= ArtworkEmbeddingService::AUTO_ASSIGN_THRESHOLD && isset($canonicalBySheet[$best['sheet_id']])) {
                $sheetService->mergeArtworkIds($canonicalBySheet[$best['sheet_id']], $userId, [$artworkId]);
                $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, updated_at = ? WHERE user_id = ? AND artwork_id = ? AND (artwork_sheet_id IS NULL OR artwork_sheet_id = 0)')
                    ->execute([$best['sheet_id'], date('c'), $userId, $artworkId]);
                $assigned++;
            } else {
                $pendingReview++;
            }
        }

        (new ArtworkGroupService($pdo))->syncUser($userId);
        $_SESSION['fichas_notice'] = "Sincronización: {$embedded} obras embebidas, {$assigned} asignadas a fichas automáticamente" . ($pendingReview > 0 ? ", {$pendingReview} necesitan revisión en el asistente." : '.');
        header('Location: fichas.php');
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['fichas_error'] = $e->getMessage();
    header('Location: fichas.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM artwork_sheets WHERE user_id = ? ORDER BY id');
$stmt->execute([$userId]);
$sheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare('SELECT id, root_file, main_file, final_title FROM artworks WHERE user_id = ?');
$stmt->execute([$userId]);
$artworksById = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $artworksById[(int)$row['id']] = $row;
}

$stmt = $pdo->prepare('SELECT artwork_sheet_id, COUNT(*) AS total FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id IS NOT NULL GROUP BY artwork_sheet_id');
$stmt->execute([$userId]);
$mockupCountBySheet = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $mockupCountBySheet[(int)$row['artwork_sheet_id']] = (int)$row['total'];
}

$orphanCount = 0;
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id IS NULL');
$stmt->execute([$userId]);
$orphanCount = (int)$stmt->fetchColumn();

// Obras sin ficha (nuevas o nunca agrupadas)
$sheetedArtworkIds = [];
foreach ($sheets as $sheet) {
    $decoded = json_decode((string)$sheet['related_artwork_ids'], true);
    foreach ((array)$decoded as $id) {
        $sheetedArtworkIds[(int)$id] = true;
    }
    $sheetedArtworkIds[(int)$sheet['canonical_artwork_id']] = true;
}
$unsheeted = array_diff_key($artworksById, $sheetedArtworkIds);

$cards = [];
foreach ($sheets as $sheet) {
    $sheetId = (int)$sheet['id'];
    $canonicalId = (int)$sheet['canonical_artwork_id'];
    $canonical = $artworksById[$canonicalId] ?? null;
    $decoded = json_decode((string)$sheet['related_artwork_ids'], true);
    $memberCount = is_array($decoded) ? count($decoded) : 1;
    $file = $canonical ? basename((string)($canonical['root_file'] ?: $canonical['main_file'] ?: '')) : (string)$sheet['source_image_file'];
    $title = trim((string)$sheet['title']);
    if ($title === '') {
        $title = trim((string)($canonical['final_title'] ?? '')) ?: 'Ficha #' . $sheetId;
    }

    if ($query !== '') {
        $haystack = strtolower($title . ' ' . $sheetId . ' ' . $file . ' ' . $sheet['tags'] . ' ' . $sheet['keywords'] . ' ' . $sheet['description']);
        if (!str_contains($haystack, strtolower($query))) {
            continue;
        }
    }

    $metaFields = ['title', 'description', 'tags', 'alt_text', 'caption'];
    $metaDone = count(array_filter($metaFields, fn($f) => trim((string)$sheet[$f]) !== ''));

    $cards[] = [
        'sheet_id' => $sheetId,
        'title' => $title,
        'image' => fichas_image_url($file),
        'members' => $memberCount,
        'mockups' => (int)($mockupCountBySheet[$sheetId] ?? 0),
        'meta_done' => $metaDone,
        'meta_total' => count($metaFields),
        'status' => (string)$sheet['status'],
    ];
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Fichas - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .fichas-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .fichas-search { display:flex; gap:8px; }
        .fichas-search input { min-width:240px; }
        .fichas-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(230px, 1fr)); gap:14px; }
        .ficha-card { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); box-shadow:var(--shadow); overflow:hidden; display:flex; flex-direction:column; text-decoration:none; color:inherit; transition:border-color .15s, box-shadow .15s; }
        .ficha-card:hover { border-color:var(--accent); box-shadow:0 12px 26px rgba(0,0,0,.12); }
        .ficha-card img, .ficha-card .empty-img { width:100%; aspect-ratio:1; object-fit:cover; display:block; background:var(--surface-soft); border-bottom:1px solid var(--line); }
        .empty-img { display:grid; place-items:center; color:var(--muted); font-size:12px; }
        .ficha-body { padding:10px 12px; display:grid; gap:5px; }
        .ficha-title { font-size:13px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ficha-stats { display:flex; gap:8px; flex-wrap:wrap; }
        .stat-pill { border:1px solid var(--line); border-radius:999px; padding:2px 8px; font-size:10px; color:var(--muted); background:var(--surface-soft); }
        .stat-pill.meta-full { border-color:#385723; color:#385723; background:#e2f0d9; }
        .stat-pill.meta-empty { border-color:#7f6000; color:#7f6000; background:#fff2cc; }
        .orphan-strip { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface-soft); padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Una ficha por obra de arte real: sus vistas raíz, sus mockups y sus metadatos en un solo lugar.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Fichas de Obras</h1>
                    <p><?= count($cards) ?> fichas · <?= $orphanCount ?> mockups sin ficha · <?= count($unsheeted) ?> obras raíz sin agrupar</p>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="fichas-toolbar">
                <form class="fichas-search" method="get">
                    <input type="text" name="q" value="<?= h($query) ?>" placeholder="Buscar por título, tag, archivo...">
                    <button class="button-link secondary" type="submit">Buscar</button>
                    <?php if ($query !== ''): ?><a class="button-link secondary" href="fichas.php">Limpiar</a><?php endif; ?>
                </form>
                <div style="display:flex; gap:8px;">
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="sync_new">
                        <button type="submit" class="button-link secondary" title="Embebe obras nuevas y las asigna a su ficha por similitud visual" onclick="this.textContent='Sincronizando...';">⟳ Sincronizar obras nuevas</button>
                    </form>
                    <a class="button-link secondary" href="fichas_reconcile.php">Reagrupar obras (asistente IA)</a>
                </div>
            </div>

            <?php if (count($unsheeted) > 0 || $orphanCount > 0): ?>
                <div class="orphan-strip">
                    <span class="meta">
                        <?php if (count($unsheeted) > 0): ?><strong><?= count($unsheeted) ?> obras raíz</strong> todavía no pertenecen a ninguna ficha. <?php endif; ?>
                        <?php if ($orphanCount > 0): ?><strong><?= $orphanCount ?> mockups</strong> sin ficha (sin linaje conocido).<?php endif; ?>
                    </span>
                    <a class="button-link secondary" href="fichas_reconcile.php">Resolver en el asistente</a>
                </div>
            <?php endif; ?>

            <?php if (!$cards): ?>
                <div class="notice">Aún no hay fichas. Usa el <a href="fichas_reconcile.php">asistente de agrupación</a> para crearlas a partir de tus obras.</div>
            <?php else: ?>
                <div class="fichas-grid">
                    <?php foreach ($cards as $card): ?>
                        <a class="ficha-card" href="artwork.php?id=<?= (int)$card['canonical_artwork_id'] ?>">
                            <?php if ($card['image'] !== ''): ?>
                                <img src="<?= h($card['image']) ?>" alt="<?= h($card['title']) ?>" loading="lazy" decoding="async">
                            <?php else: ?>
                                <div class="empty-img">Sin imagen</div>
                            <?php endif; ?>
                            <div class="ficha-body">
                                <span class="ficha-title" title="<?= h($card['title']) ?>"><?= h($card['title']) ?></span>
                                <div class="ficha-stats">
                                    <span class="stat-pill"><?= $card['members'] ?> raíces</span>
                                    <span class="stat-pill"><?= $card['mockups'] ?> mockups</span>
                                    <span class="stat-pill <?= $card['meta_done'] === $card['meta_total'] ? 'meta-full' : 'meta-empty' ?>">
                                        Metadatos <?= $card['meta_done'] ?>/<?= $card['meta_total'] ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
