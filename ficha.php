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

function ficha_image_url(string $file): string
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

$sheetId = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($sheetId <= 0) {
    header('Location: fichas.php');
    exit;
}

$notice = (string)($_SESSION['ficha_notice'] ?? '');
$error = (string)($_SESSION['ficha_error'] ?? '');
unset($_SESSION['ficha_notice'], $_SESSION['ficha_error']);

try {
    $sheet = $service->sheet($sheetId, $userId);
} catch (Throwable $e) {
    $_SESSION['fichas_error'] = $e->getMessage();
    header('Location: fichas.php');
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'save_meta') {
            $service->saveArtworkSheet($sheetId, $userId, [
                // Preservar agrupación y portada: saveArtworkSheet pisa estos campos si no se pasan.
                'related_artwork_ids' => (string)$sheet['related_artwork_ids'],
                'source_image_file' => (string)$sheet['source_image_file'],
                'title' => (string)($_POST['title'] ?? ''),
                'subtitle' => (string)($_POST['subtitle'] ?? ''),
                'description' => (string)($_POST['description'] ?? ''),
                'short_description' => (string)($_POST['short_description'] ?? ''),
                'keywords' => (string)($_POST['keywords'] ?? ''),
                'tags' => (string)($_POST['tags'] ?? ''),
                'alt_text' => (string)($_POST['alt_text'] ?? ''),
                'caption' => (string)($_POST['caption'] ?? ''),
                'user_notes' => (string)($_POST['user_notes'] ?? ''),
                'status' => (string)($_POST['status'] ?? 'draft'),
            ]);
            $_SESSION['ficha_notice'] = 'Metadatos guardados.';
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'generate_meta') {
            $service->generateArtworkSheet($sheetId, $userId);
            $_SESSION['ficha_notice'] = 'Metadatos propuestos por IA. Revisá y guardá.';
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        throw new RuntimeException('Acción inválida.');
    }
} catch (Throwable $e) {
    $_SESSION['ficha_error'] = $e->getMessage();
    header('Location: ficha.php?id=' . $sheetId);
    exit;
}

$canonicalId = (int)$sheet['canonical_artwork_id'];
$decoded = json_decode((string)$sheet['related_artwork_ids'], true);
$memberIds = is_array($decoded) ? array_values(array_unique(array_map('intval', $decoded))) : [$canonicalId];
if (!in_array($canonicalId, $memberIds, true)) {
    array_unshift($memberIds, $canonicalId);
}

$members = [];
if ($memberIds) {
    $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
    $stmt = $pdo->prepare("SELECT id, root_file, main_file, final_title FROM artworks WHERE user_id = ? AND id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $memberIds));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $members[(int)$row['id']] = $row;
    }
}

$stmt = $pdo->prepare('SELECT * FROM mockup_sheets WHERE user_id = ? AND artwork_sheet_id = ? ORDER BY id');
$stmt->execute([$userId, $sheetId]);
$mockups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canonicalArtwork = $members[$canonicalId] ?? null;
$canonicalFile = $canonicalArtwork ? basename((string)($canonicalArtwork['root_file'] ?: $canonicalArtwork['main_file'] ?: '')) : '';
$pageTitle = trim((string)$sheet['title']) ?: 'Ficha #' . $sheetId;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= h($pageTitle) ?> - Mockup Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .ficha-layout { display:flex; gap:24px; align-items:flex-start; }
        .ficha-main { flex:1; min-width:0; }
        .ficha-meta { width:400px; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); box-shadow:var(--shadow); padding:16px; position:sticky; top:12px; }
        .ficha-meta h2 { margin:0 0 12px; font-size:15px; }
        .ficha-meta label { display:block; font-size:11px; font-weight:700; color:var(--muted); margin:10px 0 3px; text-transform:uppercase; letter-spacing:.04em; }
        .ficha-meta input, .ficha-meta textarea, .ficha-meta select { width:100%; box-sizing:border-box; padding:7px; border:1px solid var(--line); border-radius:5px; background:var(--surface-soft); font-size:12px; font-family:inherit; }
        .ficha-meta textarea { min-height:64px; resize:vertical; }
        .meta-actions { display:flex; gap:8px; margin-top:14px; }
        .section-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:22px 0 10px; }
        .hero-img { max-width:420px; width:100%; border:1px solid var(--line); border-radius:var(--radius); display:block; }
        .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(120px, 1fr)); gap:10px; }
        .asset-item { border:1px solid var(--line); border-radius:6px; overflow:hidden; background:var(--surface); }
        .asset-item img { width:100%; aspect-ratio:1; object-fit:cover; display:block; }
        .asset-item .meta { display:block; padding:4px 6px; font-size:10px; }
        .asset-item.is-canonical { border:2px solid var(--accent); }
        @media (max-width:1100px) { .ficha-layout { flex-direction:column; } .ficha-meta { width:100%; position:static; } }
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
            <div class="workspace-header">
                <div>
                    <h1><?= h($pageTitle) ?></h1>
                    <p>Ficha #<?= $sheetId ?> · <?= count($members) ?> vistas raíz · <?= count($mockups) ?> mockups · estado: <?= h($sheet['status']) ?></p>
                </div>
                <a class="button-link secondary" href="fichas.php">← Todas las fichas</a>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="ficha-layout">
                <div class="ficha-main">
                    <?php $heroUrl = ficha_image_url($canonicalFile); ?>
                    <?php if ($heroUrl !== ''): ?>
                        <img class="hero-img" src="<?= h($heroUrl) ?>" alt="<?= h($pageTitle) ?>">
                    <?php endif; ?>

                    <div class="section-title">Vistas raíz (<?= count($members) ?>)</div>
                    <div class="asset-grid">
                        <?php foreach ($memberIds as $memberId): ?>
                            <?php
                            $member = $members[$memberId] ?? null;
                            if (!$member) { continue; }
                            $file = basename((string)($member['root_file'] ?: $member['main_file'] ?: ''));
                            $url = ficha_image_url($file);
                            ?>
                            <div class="asset-item <?= $memberId === $canonicalId ? 'is-canonical' : '' ?>">
                                <?php if ($url !== ''): ?><img src="<?= h($url) ?>" loading="lazy" decoding="async"><?php endif; ?>
                                <span class="meta">#<?= $memberId ?><?= $memberId === $canonicalId ? ' ★ portada' : '' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-title">Mockups (<?= count($mockups) ?>)</div>
                    <?php if (!$mockups): ?>
                        <p class="meta">No hay mockups vinculados a esta ficha.</p>
                    <?php else: ?>
                        <div class="asset-grid">
                            <?php foreach ($mockups as $mockup): ?>
                                <?php $url = ficha_image_url((string)$mockup['mockup_file']); ?>
                                <div class="asset-item">
                                    <?php if ($url !== ''): ?>
                                        <a href="<?= h($url) ?>" target="_blank"><img src="<?= h($url) ?>" loading="lazy" decoding="async"></a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="ficha-meta">
                    <h2>Metadatos de la obra</h2>
                    <form method="post">
                        <input type="hidden" name="id" value="<?= $sheetId ?>">
                        <input type="hidden" name="action" value="save_meta">
                        <label>Título</label>
                        <input type="text" name="title" value="<?= h($sheet['title']) ?>">
                        <label>Subtítulo</label>
                        <input type="text" name="subtitle" value="<?= h($sheet['subtitle']) ?>">
                        <label>Descripción</label>
                        <textarea name="description"><?= h($sheet['description']) ?></textarea>
                        <label>Descripción corta</label>
                        <textarea name="short_description"><?= h($sheet['short_description']) ?></textarea>
                        <label>Keywords</label>
                        <input type="text" name="keywords" value="<?= h($sheet['keywords']) ?>">
                        <label>Tags</label>
                        <input type="text" name="tags" value="<?= h($sheet['tags']) ?>">
                        <label>Alt text</label>
                        <input type="text" name="alt_text" value="<?= h($sheet['alt_text']) ?>">
                        <label>Caption</label>
                        <textarea name="caption"><?= h($sheet['caption']) ?></textarea>
                        <label>Notas propias</label>
                        <textarea name="user_notes"><?= h($sheet['user_notes']) ?></textarea>
                        <label>Estado</label>
                        <select name="status">
                            <?php foreach (['draft' => 'Borrador', 'review' => 'En revisión', 'ready' => 'Lista', 'published' => 'Publicada'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $sheet['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="meta-actions">
                            <button type="submit" class="button-link primary">Guardar</button>
                        </div>
                    </form>
                    <form method="post" style="margin-top:8px;">
                        <input type="hidden" name="id" value="<?= $sheetId ?>">
                        <input type="hidden" name="action" value="generate_meta">
                        <button type="submit" class="button-link secondary" onclick="this.textContent='Generando...';">✨ Proponer metadatos con IA</button>
                    </form>
                </aside>
            </div>
        </div>
    </main>
</div>
</body>
</html>
