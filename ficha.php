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

        if ($action === 'set_canonical') {
            $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
            $decoded = json_decode((string)$sheet['related_artwork_ids'], true);
            $memberIds = is_array($decoded) ? array_map('intval', $decoded) : [];
            if (!in_array($artworkId, $memberIds, true)) {
                throw new RuntimeException('Esa obra no pertenece a esta ficha.');
            }
            $stmt = $pdo->prepare('SELECT root_file, main_file FROM artworks WHERE id = ? AND user_id = ?');
            $stmt->execute([$artworkId, $userId]);
            $artwork = $stmt->fetch();
            $sourceFile = $artwork ? basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: '')) : '';
            $pdo->prepare('UPDATE artwork_sheets SET canonical_artwork_id = ?, source_image_file = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([$artworkId, $sourceFile, date('c'), $sheetId, $userId]);
            $_SESSION['ficha_notice'] = 'Obra #' . $artworkId . ' es ahora la portada.';
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'detach_artwork' || $action === 'move_artwork') {
            $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
            $targetSheetId = max(0, (int)($_POST['target_sheet_id'] ?? 0));
            $decoded = json_decode((string)$sheet['related_artwork_ids'], true);
            $memberIds = is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
            if (!in_array($artworkId, $memberIds, true)) {
                throw new RuntimeException('Esa obra no pertenece a esta ficha.');
            }
            if (count($memberIds) <= 1) {
                throw new RuntimeException('Es la única obra de la ficha: eliminá la ficha completa en su lugar.');
            }

            $remaining = array_values(array_diff($memberIds, [$artworkId]));
            $pdo->beginTransaction();
            try {
                $newCanonical = (int)$sheet['canonical_artwork_id'];
                $sourceFile = (string)$sheet['source_image_file'];
                if ($newCanonical === $artworkId) {
                    $newCanonical = $remaining[0];
                    $stmt = $pdo->prepare('SELECT root_file, main_file FROM artworks WHERE id = ? AND user_id = ?');
                    $stmt->execute([$newCanonical, $userId]);
                    $artwork = $stmt->fetch();
                    $sourceFile = $artwork ? basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: '')) : '';
                }
                $pdo->prepare('UPDATE artwork_sheets SET canonical_artwork_id = ?, source_image_file = ?, related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([$newCanonical, $sourceFile, json_encode($remaining, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);

                if ($action === 'move_artwork' && $targetSheetId > 0) {
                    $target = $service->sheet($targetSheetId, $userId);
                    $targetDecoded = json_decode((string)$target['related_artwork_ids'], true);
                    $targetIds = is_array($targetDecoded) ? array_values(array_map('intval', $targetDecoded)) : [];
                    if (!in_array($artworkId, $targetIds, true)) {
                        $targetIds[] = $artworkId;
                    }
                    $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                        ->execute([json_encode($targetIds, JSON_UNESCAPED_SLASHES), date('c'), $targetSheetId, $userId]);
                    $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ? AND artwork_id = ?')
                        ->execute([$targetSheetId, date('c'), $userId, $sheetId, $artworkId]);
                    $noticeText = 'Obra #' . $artworkId . ' movida a la Ficha #' . $targetSheetId . ' junto con sus mockups.';
                } else {
                    $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ? AND artwork_id = ?')
                        ->execute([date('c'), $userId, $sheetId, $artworkId]);
                    $noticeText = 'Obra #' . $artworkId . ' desacoplada: quedó sin ficha (podés reagruparla desde el asistente).';
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $_SESSION['ficha_notice'] = $noticeText;
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'delete_artwork_full') {
            $artworkId = max(0, (int)($_POST['artwork_id'] ?? 0));
            $decoded = json_decode((string)$sheet['related_artwork_ids'], true);
            $memberIds = is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
            if (!in_array($artworkId, $memberIds, true)) {
                throw new RuntimeException('Esa obra no pertenece a esta ficha.');
            }
            if (count($memberIds) <= 1) {
                throw new RuntimeException('Es la única obra de la ficha: eliminá la ficha completa en su lugar.');
            }

            $stmt = $pdo->prepare('SELECT id, root_file, main_file FROM artworks WHERE id = ? AND user_id = ?');
            $stmt->execute([$artworkId, $userId]);
            $doomed = $stmt->fetch();
            if (!$doomed) {
                throw new RuntimeException('Obra inexistente.');
            }
            $doomedFile = basename((string)($doomed['root_file'] ?: $doomed['main_file'] ?: ''));

            $remaining = array_values(array_diff($memberIds, [$artworkId]));
            $pdo->beginTransaction();
            try {
                // 1) Si era portada, reasignar ANTES de borrar (el FK en cascada borraría la ficha entera).
                $newCanonical = (int)$sheet['canonical_artwork_id'];
                $sourceFile = (string)$sheet['source_image_file'];
                if ($newCanonical === $artworkId) {
                    $newCanonical = $remaining[0];
                    $stmt = $pdo->prepare('SELECT root_file, main_file FROM artworks WHERE id = ? AND user_id = ?');
                    $stmt->execute([$newCanonical, $userId]);
                    $artwork = $stmt->fetch();
                    $sourceFile = $artwork ? basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: '')) : '';
                }
                $pdo->prepare('UPDATE artwork_sheets SET canonical_artwork_id = ?, source_image_file = ?, related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([$newCanonical, $sourceFile, json_encode($remaining, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);

                // 2) Los mockups generados desde esta vista pasan a la portada (el FK en cascada
                //    borraría sus mockup_sheets si siguieran apuntando a la obra eliminada).
                $pdo->prepare('UPDATE mockup_sheets SET artwork_id = ?, updated_at = ? WHERE user_id = ? AND artwork_id = ?')
                    ->execute([$newCanonical, date('c'), $userId, $artworkId]);

                // 3) Preservar linaje: los mockups que nacieron de este archivo pasan a apuntar
                //    al archivo de la portada, para que futuras reagrupaciones no los pierdan.
                if ($doomedFile !== '' && $sourceFile !== '') {
                    $pdo->prepare('UPDATE mockups SET artwork_file = ? WHERE user_id = ? AND artwork_file = ?')
                        ->execute([$sourceFile, $userId, $doomedFile]);
                }

                // 4) Borrar la obra (cascada: embeddings, análisis, jobs).
                $pdo->prepare('DELETE FROM artworks WHERE id = ? AND user_id = ?')->execute([$artworkId, $userId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // 5) Borrar el archivo del disco solo si ninguna otra obra lo usa.
            $fileDeleted = false;
            if ($doomedFile !== '') {
                $stmt = $pdo->prepare('SELECT COUNT(*) FROM artworks WHERE (root_file = ? OR main_file = ?)');
                $stmt->execute([$doomedFile, $doomedFile]);
                if ((int)$stmt->fetchColumn() === 0) {
                    foreach ([RESULTS_DIR, __DIR__ . '/uploads'] as $dir) {
                        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $doomedFile;
                        if (is_file($path)) {
                            $fileDeleted = @unlink($path) || $fileDeleted;
                        }
                    }
                    $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo($doomedFile, PATHINFO_FILENAME) . '.meta.json';
                    if (is_file($metaPath)) {
                        @unlink($metaPath);
                    }
                }
            }

            $_SESSION['ficha_notice'] = 'Obra #' . $artworkId . ' eliminada' . ($fileDeleted ? ' junto con su archivo.' : ' (archivo no encontrado o compartido: no se borró).') . ' Sus mockups quedaron en esta ficha.';
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'delete_mockup_full') {
            $mockupSheetId = max(0, (int)($_POST['mockup_sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT id, mockup_file FROM mockup_sheets WHERE id = ? AND user_id = ? AND artwork_sheet_id = ?');
            $stmt->execute([$mockupSheetId, $userId, $sheetId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Ese mockup no pertenece a esta ficha.');
            }
            $file = basename((string)$row['mockup_file']);

            $pdo->beginTransaction();
            try {
                $promptFiles = [];
                if ($file !== '') {
                    $stmt = $pdo->prepare('SELECT id, prompt_file FROM mockups WHERE user_id = ? AND mockup_file = ?');
                    $stmt->execute([$userId, $file]);
                    $mockupIds = [];
                    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mockupRow) {
                        $mockupIds[] = (int)$mockupRow['id'];
                        if (!empty($mockupRow['prompt_file'])) {
                            $promptFiles[] = basename((string)$mockupRow['prompt_file']);
                        }
                    }
                    if ($mockupIds) {
                        $placeholders = implode(',', array_fill(0, count($mockupIds), '?'));
                        $pdo->prepare("DELETE FROM mockup_generation_jobs WHERE user_id = ? AND mockup_id IN ({$placeholders})")
                            ->execute(array_merge([$userId], $mockupIds));
                        $pdo->prepare("DELETE FROM mockups WHERE user_id = ? AND id IN ({$placeholders})")
                            ->execute(array_merge([$userId], $mockupIds));
                    }
                    $pdo->prepare('DELETE FROM mockup_generation_jobs WHERE user_id = ? AND mockup_file = ?')->execute([$userId, $file]);
                }
                $pdo->prepare('DELETE FROM mockup_sheets WHERE user_id = ? AND mockup_file = ?')->execute([$userId, $file]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if ($file !== '') {
                $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
                if (is_file($path)) {
                    @unlink($path);
                }
                $metaPath = RESULTS_DIR . DIRECTORY_SEPARATOR . pathinfo($file, PATHINFO_FILENAME) . '.meta.json';
                if (is_file($metaPath)) {
                    @unlink($metaPath);
                }
                foreach (array_unique($promptFiles) as $promptFile) {
                    $promptPath = RESULTS_DIR . DIRECTORY_SEPARATOR . $promptFile;
                    if (is_file($promptPath)) {
                        @unlink($promptPath);
                    }
                }
            }

            $_SESSION['ficha_notice'] = 'Mockup ' . $file . ' eliminado del todo (registro y archivo).';
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'detach_mockup' || $action === 'move_mockup') {
            $mockupSheetId = max(0, (int)($_POST['mockup_sheet_id'] ?? 0));
            $targetSheetId = max(0, (int)($_POST['target_sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT id FROM mockup_sheets WHERE id = ? AND user_id = ? AND artwork_sheet_id = ?');
            $stmt->execute([$mockupSheetId, $userId, $sheetId]);
            if (!$stmt->fetch()) {
                throw new RuntimeException('Ese mockup no pertenece a esta ficha.');
            }
            if ($action === 'move_mockup' && $targetSheetId > 0) {
                $target = $service->sheet($targetSheetId, $userId);
                $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, artwork_id = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([$targetSheetId, (int)$target['canonical_artwork_id'], date('c'), $mockupSheetId, $userId]);
                $_SESSION['ficha_notice'] = 'Mockup movido a la Ficha #' . $targetSheetId . '.';
            } else {
                $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([date('c'), $mockupSheetId, $userId]);
                $_SESSION['ficha_notice'] = 'Mockup desacoplado: quedó en la bandeja de huérfanos.';
            }
            header('Location: ficha.php?id=' . $sheetId);
            exit;
        }

        if ($action === 'delete_sheet') {
            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ?')
                    ->execute([date('c'), $userId, $sheetId]);
                $pdo->prepare('DELETE FROM artwork_sheets WHERE id = ? AND user_id = ?')->execute([$sheetId, $userId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            $_SESSION['fichas_notice'] = 'Ficha #' . $sheetId . ' eliminada. Sus obras y mockups NO se borraron: quedaron sin agrupar.';
            header('Location: fichas.php');
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

// Otras fichas del usuario, para los selectores de "mover a"
$stmt = $pdo->prepare('SELECT id, title, canonical_artwork_id FROM artwork_sheets WHERE user_id = ? AND id <> ? ORDER BY id');
$stmt->execute([$userId, $sheetId]);
$otherSheets = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        .asset-actions { display:flex; gap:3px; padding:4px 6px 6px; align-items:center; }
        .asset-actions select { flex:1; min-width:0; font-size:10px; padding:2px; border:1px solid var(--line); border-radius:4px; background:var(--surface-soft); }
        .asset-actions button { font-size:10px; padding:2px 6px; cursor:pointer; }
        .danger-zone { margin-top:16px; border-top:1px solid var(--line); padding-top:12px; }
        .danger-zone button { color:var(--danger, #b42318); border-color:var(--danger, #b42318); }
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
                                <div class="asset-actions">
                                    <select onchange="artworkAction(<?= $memberId ?>, this.value); this.selectedIndex = 0;">
                                        <option value="">Acciones…</option>
                                        <?php if ($memberId !== $canonicalId): ?>
                                            <option value="canonical">★ Usar como portada</option>
                                        <?php endif; ?>
                                        <option value="detach">Desacoplar (dejar sin ficha)</option>
                                        <option value="delete_full">🗑 Eliminar del todo (con archivo)</option>
                                        <?php foreach ($otherSheets as $other): ?>
                                            <option value="move:<?= (int)$other['id'] ?>">Mover a Ficha #<?= (int)$other['id'] ?><?= trim((string)$other['title']) !== '' ? ' · ' . h(mb_substr((string)$other['title'], 0, 24)) : '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
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
                                    <div class="asset-actions">
                                        <select onchange="mockupAction(<?= (int)$mockup['id'] ?>, this.value); this.selectedIndex = 0;">
                                            <option value="">Acciones…</option>
                                            <option value="detach">Desacoplar (a huérfanos)</option>
                                            <option value="delete_full">🗑 Eliminar del todo (con archivo)</option>
                                            <?php foreach ($otherSheets as $other): ?>
                                                <option value="move:<?= (int)$other['id'] ?>">Mover a Ficha #<?= (int)$other['id'] ?><?= trim((string)$other['title']) !== '' ? ' · ' . h(mb_substr((string)$other['title'], 0, 24)) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
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
                    <div class="danger-zone">
                        <form method="post" onsubmit="return confirm('¿Eliminar la Ficha #<?= $sheetId ?>? Las obras y mockups NO se borran: quedan sin agrupar y podés rearmarlos desde el asistente.');">
                            <input type="hidden" name="id" value="<?= $sheetId ?>">
                            <input type="hidden" name="action" value="delete_sheet">
                            <button type="submit" class="button-link secondary">🗑 Eliminar ficha (conserva obras y mockups)</button>
                        </form>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>
<script>
function postFichaAction(fields) {
    var form = document.createElement('form');
    form.method = 'post';
    fields.id = <?= $sheetId ?>;
    Object.keys(fields).forEach(function (name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fields[name];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}
function artworkAction(artworkId, value) {
    if (!value) { return; }
    if (value === 'canonical') {
        postFichaAction({ action: 'set_canonical', artwork_id: artworkId });
    } else if (value === 'detach') {
        if (confirm('¿Desacoplar la obra #' + artworkId + ' de esta ficha? Sus mockups quedan como huérfanos.')) {
            postFichaAction({ action: 'detach_artwork', artwork_id: artworkId });
        }
    } else if (value === 'delete_full') {
        if (confirm('¿ELIMINAR la obra #' + artworkId + ' definitivamente?\n\n- Se borra el registro Y el archivo de imagen del disco (irreversible).\n- Sus mockups NO se borran: pasan a la portada de esta ficha.')) {
            postFichaAction({ action: 'delete_artwork_full', artwork_id: artworkId });
        }
    } else if (value.indexOf('move:') === 0) {
        postFichaAction({ action: 'move_artwork', artwork_id: artworkId, target_sheet_id: value.slice(5) });
    }
}
function mockupAction(mockupSheetId, value) {
    if (!value) { return; }
    if (value === 'detach') {
        postFichaAction({ action: 'detach_mockup', mockup_sheet_id: mockupSheetId });
    } else if (value === 'delete_full') {
        if (confirm('¿ELIMINAR este mockup definitivamente?\n\nSe borra el registro Y el archivo de imagen del disco (irreversible).')) {
            postFichaAction({ action: 'delete_mockup_full', mockup_sheet_id: mockupSheetId });
        }
    } else if (value.indexOf('move:') === 0) {
        postFichaAction({ action: 'move_mockup', mockup_sheet_id: mockupSheetId, target_sheet_id: value.slice(5) });
    }
}
</script>
</body>
</html>
