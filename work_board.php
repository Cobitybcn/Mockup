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

function board_image_url(string $file): string
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

function board_related_ids(array $sheet): array
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

// AJAX Group Contents Endpoint
if (isset($_GET['action']) && $_GET['action'] === 'get_group_contents') {
    header('Content-Type: application/json');
    try {
        $sheetId = max(0, (int)($_GET['sheet_id'] ?? 0));
        $artworkId = max(0, (int)($_GET['artwork_id'] ?? 0));
        
        if ($sheetId > 0) {
            $sheet = $service->sheet($sheetId, $userId);
        } elseif ($artworkId > 0) {
            $sheet = $service->sheetForArtwork($artworkId, $userId);
        } else {
            throw new RuntimeException('ID de ficha u obra inválido.');
        }
        
        $canonicalId = (int)$sheet['canonical_artwork_id'];
        $actualSheetId = (int)$sheet['id'];
        
        $childArtworks = [];
        $relatedIds = board_related_ids($sheet);
        
        if (count($relatedIds) > 1) {
            $placeholders = implode(',', array_fill(0, count($relatedIds), '?'));
            $stmt = $pdo->prepare("SELECT id, final_title, root_file, main_file FROM artworks WHERE user_id = ? AND id IN ($placeholders)");
            $stmt->execute(array_merge([$userId], $relatedIds));
            foreach ($stmt->fetchAll() as $art) {
                $artId = (int)$art['id'];
                if ($artId !== $canonicalId) {
                    $childArtworks[] = [
                        'id' => $artId,
                        'title' => trim((string)($art['final_title'] ?: 'Obra #' . $artId)),
                        'file' => basename((string)($art['root_file'] ?: $art['main_file'] ?: '')),
                        'image' => board_image_url(basename((string)($art['root_file'] ?: $art['main_file'] ?: ''))),
                    ];
                }
            }
        }
        
        $mockups = $service->associatedMockups($sheet, $userId);
        $childMockups = [];
        foreach ($mockups as $mock) {
            $childMockups[] = [
                'file' => basename($mock['mockup_file']),
                'image' => board_image_url(basename($mock['mockup_file'])),
            ];
        }
        
        echo json_encode([
            'success' => true,
            'sheet_id' => $actualSheetId,
            'child_artworks' => $childArtworks,
            'child_mockups' => $childMockups,
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    }
    exit;
}

$notice = (string)($_SESSION['work_board_notice'] ?? '');
$error = (string)($_SESSION['work_board_error'] ?? '');
unset($_SESSION['work_board_notice'], $_SESSION['work_board_error']);
$query = trim((string)($_GET['q'] ?? ''));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'group_assets') {
        $targetArtworkId = max(0, (int)($_POST['target_artwork_id'] ?? 0));
        $assets = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['assets'] ?? [])))));
        if ($targetArtworkId <= 0) {
            throw new RuntimeException('Selecciona una obra o ficha destino.');
        }
        if (!$assets) {
            throw new RuntimeException('No hay elementos seleccionados para agrupar.');
        }

        $targetSheet = $service->sheetForArtwork($targetArtworkId, $userId);
        $targetSheetId = (int)$targetSheet['id'];
        $relatedIds = board_related_ids($targetSheet);
        $movedArtworks = 0;
        $movedMockups = 0;

        $pdo->beginTransaction();
        try {
            foreach ($assets as $asset) {
                [$type, $value] = array_pad(explode(':', $asset, 2), 2, '');
                if ($type === 'artwork') {
                    $artworkId = max(0, (int)$value);
                    if ($artworkId <= 0 || $artworkId === $targetArtworkId) {
                        continue;
                    }
                    $service->artwork($artworkId, $userId);

                    // Check if source artwork already has its own sheet
                    $stmtSource = $pdo->prepare('SELECT id, related_artwork_ids FROM artwork_sheets WHERE user_id = ? AND canonical_artwork_id = ?');
                    $stmtSource->execute([$userId, $artworkId]);
                    $sourceSheet = $stmtSource->fetch();

                    if ($sourceSheet) {
                        $sourceSheetId = (int)$sourceSheet['id'];
                        // Gather source related IDs
                        $srcDecoded = json_decode((string)($sourceSheet['related_artwork_ids'] ?? ''), true);
                        $srcIds = is_array($srcDecoded) ? $srcDecoded : preg_split('/[,\s]+/', (string)($sourceSheet['related_artwork_ids'] ?? ''));
                        $srcIds = array_values(array_unique(array_filter(array_map('intval', (array)$srcIds))));

                        $relatedIds = array_merge($relatedIds, $srcIds);
                        $relatedIds[] = $artworkId;

                        // Update mockup_sheets that point to source sheet
                        $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ?')
                            ->execute([$targetSheetId, date('c'), $userId, $sourceSheetId]);

                        // Delete source sheet
                        $pdo->prepare('DELETE FROM artwork_sheets WHERE id = ? AND user_id = ?')
                            ->execute([$sourceSheetId, $userId]);
                    } else {
                        $relatedIds[] = $artworkId;
                    }

                    // Update mockup_sheets for this artwork ID to point to target sheet
                    $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, updated_at = ? WHERE user_id = ? AND artwork_id = ? AND (artwork_sheet_id IS NULL OR artwork_sheet_id = 0)')
                        ->execute([$targetSheetId, date('c'), $userId, $artworkId]);

                    $movedArtworks++;
                    continue;
                }

                if ($type === 'mockup') {
                    $file = basename(str_replace('\\', '/', $value));
                    if ($file === '') {
                        continue;
                    }

                    $stmt = $pdo->prepare('SELECT id FROM mockup_sheets WHERE user_id = ? AND mockup_file = ? ORDER BY id DESC');
                    $stmt->execute([$userId, $file]);
                    $ids = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                    if ($ids) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $pdo->prepare("UPDATE mockup_sheets SET artwork_sheet_id = ?, artwork_id = ?, updated_at = ? WHERE user_id = ? AND id IN ({$placeholders})")
                            ->execute(array_merge([$targetSheetId, $targetArtworkId, date('c'), $userId], $ids));
                    } else {
                        $service->attachMockupFile($targetSheetId, $userId, $file, 'Agrupado.');
                    }
                    $movedMockups++;
                }
            }

            $relatedIds = array_values(array_unique(array_filter(array_map('intval', $relatedIds))));
            if (!in_array($targetArtworkId, $relatedIds, true)) {
                array_unshift($relatedIds, $targetArtworkId);
            }
            $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $targetSheetId, $userId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $_SESSION['work_board_notice'] = 'Fusión completada: ' . $movedArtworks . ' vistas raíz y ' . $movedMockups . ' mockups agrupados en Ficha #' . $targetSheetId . '.';
        header('Location: work_board.php?q=' . urlencode($query));
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm_auto_group') {
        $matches = $_POST['matches'] ?? [];
        if (!is_array($matches)) {
            throw new RuntimeException('Datos inválidos.');
        }

        $movedMockups = 0;
        $pdo->beginTransaction();
        try {
            foreach ($matches as $targetArtworkId => $files) {
                $targetArtworkId = max(0, (int)$targetArtworkId);
                if ($targetArtworkId <= 0 || !is_array($files)) {
                    continue;
                }

                $targetSheet = $service->sheetForArtwork($targetArtworkId, $userId);
                $targetSheetId = (int)$targetSheet['id'];

                foreach ($files as $file) {
                    $file = basename(str_replace('\\', '/', (string)$file));
                    if ($file === '') {
                        continue;
                    }

                    $stmt = $pdo->prepare('SELECT id FROM mockup_sheets WHERE user_id = ? AND mockup_file = ? ORDER BY id DESC');
                    $stmt->execute([$userId, $file]);
                    $ids = array_values(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
                    if ($ids) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $pdo->prepare("UPDATE mockup_sheets SET artwork_sheet_id = ?, artwork_id = ?, updated_at = ? WHERE user_id = ? AND id IN ({$placeholders})")
                            ->execute(array_merge([$targetSheetId, $targetArtworkId, date('c'), $userId], $ids));
                    } else {
                        $service->attachMockupFile($targetSheetId, $userId, $file, 'Auto-agrupado.');
                    }
                    $movedMockups++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $_SESSION['work_board_notice'] = 'Agrupamiento automático completado: ' . $movedMockups . ' mockups asociados con éxito.';
        header('Location: work_board.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'ungroup_asset') {
        $asset = trim((string)($_POST['asset'] ?? ''));
        $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
        if ($sheetId <= 0) {
            throw new RuntimeException('ID de ficha inválido.');
        }
        if ($asset === '') {
            throw new RuntimeException('Elemento no especificado.');
        }

        // Verify sheet ownership
        $sheet = $service->sheet($sheetId, $userId);
        $canonicalId = (int)$sheet['canonical_artwork_id'];

        [$type, $value] = array_pad(explode(':', $asset, 2), 2, '');

        $pdo->beginTransaction();
        try {
            if ($type === 'artwork') {
                $artworkId = max(0, (int)$value);
                if ($artworkId === $canonicalId) {
                    throw new RuntimeException('La obra principal no se puede desagrupar. Desagrupá las otras obras o eliminá la ficha.');
                }
                
                $decoded = json_decode((string)($sheet['related_artwork_ids'] ?? ''), true);
                $relatedIds = is_array($decoded) ? $decoded : preg_split('/[,\s]+/', (string)($sheet['related_artwork_ids'] ?? ''));
                $relatedIds = array_values(array_unique(array_filter(array_map('intval', (array)$relatedIds))));
                
                $relatedIds = array_diff($relatedIds, [$artworkId]);
                $relatedIds = array_values(array_unique(array_filter(array_map('intval', $relatedIds))));
                if (!in_array($canonicalId, $relatedIds, true)) {
                    array_unshift($relatedIds, $canonicalId);
                }

                $pdo->prepare('UPDATE artwork_sheets SET related_artwork_ids = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                    ->execute([json_encode($relatedIds, JSON_UNESCAPED_SLASHES), date('c'), $sheetId, $userId]);

                $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, artwork_id = 0, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ? AND artwork_id = ?')
                    ->execute([date('c'), $userId, $sheetId, $artworkId]);

                $_SESSION['work_board_notice'] = 'Obra #' . $artworkId . ' desagrupada.';
            }

            if ($type === 'mockup') {
                $file = basename(str_replace('\\', '/', $value));
                if ($file !== '') {
                    $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, artwork_id = 0, updated_at = ? WHERE user_id = ? AND artwork_sheet_id = ? AND mockup_file = ?')
                        ->execute([date('c'), $userId, $sheetId, $file]);
                }
                $_SESSION['work_board_notice'] = 'Mockup ' . $file . ' desagrupado.';
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        header('Location: work_board.php?q=' . urlencode($query));
        exit;
    }
} catch (Throwable $e) {
    $_SESSION['work_board_error'] = $e->getMessage();
    header('Location: work_board.php?q=' . urlencode($query));
    exit;
}

$artworkIdToCanonical = []; 
$canonicalToSheet = [];     
$artworkToSheetId = [];     
$sheetCounts = [];          

$stmt = $pdo->prepare('SELECT * FROM artwork_sheets WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
foreach ($stmt->fetchAll() as $sheet) {
    $canonicalId = (int)$sheet['canonical_artwork_id'];
    $canonicalToSheet[$canonicalId] = $sheet;
    $relatedIds = board_related_ids($sheet);
    $sheetCounts[$canonicalId] = count($relatedIds);
    foreach ($relatedIds as $rId) {
        $artworkIdToCanonical[$rId] = $canonicalId;
        $artworkToSheetId[$rId] = (int)$sheet['id'];
    }
}

$stmt = $pdo->prepare('SELECT * FROM artworks WHERE user_id = :user_id ORDER BY updated_at DESC, created_at DESC');
$stmt->execute(['user_id' => $userId]);
$allArtworks = $stmt->fetchAll();

$allArtworksById = [];
foreach ($allArtworks as $art) {
    $allArtworksById[(int)$art['id']] = $art;
}

$rootFiles = [];
foreach ($allArtworks as $artwork) {
    $file = basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
    if ($file !== '') {
        $rootFiles[$file] = true;
    }
}

$mockupFiles = [];
$mockupSheetByFile = [];
$stmt = $pdo->prepare('SELECT * FROM mockup_sheets WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
foreach ($stmt->fetchAll() as $row) {
    $file = basename((string)$row['mockup_file']);
    if ($file !== '') {
        $mockupFiles[$file] = true;
        $mockupSheetByFile[$file] = $row;
    }
}

foreach (['mockup_generation_jobs', 'mockups'] as $table) {
    $stmt = $pdo->prepare("SELECT mockup_file FROM {$table} WHERE user_id = :user_id AND mockup_file IS NOT NULL AND mockup_file <> ''");
    $stmt->execute(['user_id' => $userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $file = basename((string)$file);
        if ($file !== '') {
            $mockupFiles[$file] = true;
        }
    }
}

foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
    foreach (glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*.' . $ext) ?: [] as $path) {
        $file = basename($path);
        if (!isset($rootFiles[$file])) {
            $mockupFiles[$file] = true;
        }
    }
}

// ----------------------------------------------------
// SMART AUTO-GROUPING LOGIC
// ----------------------------------------------------
$autoGroups = [];
$ungroupedMockupFiles = [];
foreach (array_keys($mockupFiles) as $file) {
    $row = $mockupSheetByFile[$file] ?? [];
    $linkedArtworkId = (int)($row['artwork_id'] ?? 0);
    $linkedSheetId = (int)($row['artwork_sheet_id'] ?? 0);

    if ($linkedSheetId <= 0 && $linkedArtworkId <= 0) {
        $ungroupedMockupFiles[] = $file;
    }
}

foreach ($ungroupedMockupFiles as $file) {
    $bestMatchArtId = 0;
    $bestMatchScore = 0;
    $mName = strtolower(pathinfo($file, PATHINFO_FILENAME));
    
    foreach ($allArtworks as $art) {
        $artId = (int)$art['id'];
        $rootFile = basename((string)($art['root_file'] ?: $art['main_file'] ?: ''));
        if ($rootFile === '') {
            continue;
        }
        $rName = strtolower(pathinfo($rootFile, PATHINFO_FILENAME));
        
        if ($mName === $rName) {
            $bestMatchArtId = $artId;
            $bestMatchScore = 100;
            break;
        }
        
        if (str_contains($mName, $rName)) {
            $score = strlen($rName);
            if ($score > $bestMatchScore) {
                $bestMatchScore = $score;
                $bestMatchArtId = $artId;
            }
        }
    }
    
    if ($bestMatchArtId > 0) {
        $autoGroups[$bestMatchArtId][] = $file;
    }
}

// Check Wizard action
$action = (string)($_GET['action'] ?? '');
if ($action === 'auto_group_wizard') {
    ?>
    <!doctype html>
    <html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Asistente Auto-Agrupamiento - Mockup Lab</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="style.css">
        <style>
            .proposal-card { border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); padding: 14px; margin-bottom: 12px; display: flex; gap: 15px; }
            .proposal-target { width: 240px; display: flex; gap: 12px; align-items: center; border-right: 1px solid var(--line); padding-right: 15px; }
            .proposal-img { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; border: 1px solid var(--line); }
            .proposal-files { flex: 1; }
            .proposal-files ul { margin: 6px 0 0 16px; padding: 0; font-size: 12px; color: var(--muted); }
            .empty-proposal { padding: 40px; text-align: center; color: var(--muted); background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); }
        </style>
    </head>
    <body>
    <div class="app-shell">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="main-area">
            <header class="app-header">
                <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
            </header>
            <div class="alert-strip">Asistente inteligente: asocia mockups basándose en el nombre de la obra original.</div>
            <div class="workspace">
                <div class="workspace-header">
                    <div>
                        <h1>Auto-Agrupamiento Inteligente</h1>
                        <p>Hemos analizado el nombre de tus mockups sin agrupar y encontramos las siguientes sugerencias:</p>
                    </div>
                </div>

                <?php if (count($autoGroups) > 0): ?>
                    <form method="post" action="work_board.php">
                        <input type="hidden" name="action" value="confirm_auto_group">
                        
                        <div class="auto-group-proposals">
                            <?php foreach ($autoGroups as $artId => $files): ?>
                                <?php 
                                $art = $allArtworksById[$artId];
                                $artFile = basename((string)($art['root_file'] ?: $art['main_file'] ?: ''));
                                ?>
                                <div class="proposal-card">
                                    <div class="proposal-target">
                                        <?php if ($artFile !== ''): ?>
                                            <img src="<?= board_image_url($artFile) ?>" class="proposal-img">
                                        <?php else: ?>
                                            <div class="proposal-img" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--muted);">N/A</div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= h($art['final_title'] ?: 'Obra #' . $artId) ?></strong>
                                            <span class="meta" style="font-size:10px;display:block;">Ficha #<?= (int)($artworkToSheetId[$artId] ?? 0) ?> · Obra #<?= $artId ?></span>
                                        </div>
                                    </div>
                                    <div class="proposal-files">
                                        <strong><?= count($files) ?> mockups a asociar:</strong>
                                        <ul>
                                            <?php foreach ($files as $file): ?>
                                                <li>
                                                    <input type="hidden" name="matches[<?= $artId ?>][]" value="<?= h($file) ?>">
                                                    <?= h($file) ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 20px; display: flex; gap: 10px;">
                            <button type="submit" class="button-link primary">Confirmar y Agrupar <?= count($autoGroups) ?> Grupos</button>
                            <a href="work_board.php" class="button-link secondary">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="empty-proposal">
                        <strong>No se encontraron coincidencias automáticas</strong>
                        <p class="meta" style="margin-top: 4px;">Todos los nombres de archivo de mockup actuales parecen ser diferentes de las obras cargadas o ya están correctamente agrupados.</p>
                        <a href="work_board.php" class="button-link secondary" style="margin-top: 15px; display: inline-block;">Volver al Tablero</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ----------------------------------------------------
// MAIN VIEW LOGIC
// ----------------------------------------------------
$groupedCards = [];
$ungroupedCards = [];

foreach ($allArtworks as $artwork) {
    $artId = (int)$artwork['id'];

    $isChild = (isset($artworkIdToCanonical[$artId]) && $artworkIdToCanonical[$artId] !== $artId);
    if ($isChild) {
        continue;
    }

    $file = basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
    $title = trim((string)($artwork['final_title'] ?: 'Obra #' . $artId));

    $sheet = $canonicalToSheet[$artId] ?? null;
    $sheetId = $sheet ? (int)$sheet['id'] : 0;
    $count = $sheet ? (int)($sheetCounts[$artId] ?? 1) : 1;

    $match = false;
    $haystack = strtolower($title . ' ' . $artId . ' ' . $file);
    if ($query === '' || str_contains($haystack, strtolower($query))) {
        $match = true;
    } else {
        if ($sheet) {
            $relatedIds = board_related_ids($sheet);
            foreach ($relatedIds as $rId) {
                if ($rId !== $artId) {
                    foreach ($allArtworks as $art) {
                        if ((int)$art['id'] === $rId) {
                            $cTitle = trim((string)($art['final_title'] ?: 'Obra #' . $rId));
                            $cFile = basename((string)($art['root_file'] ?: $art['main_file'] ?: ''));
                            $cHaystack = strtolower($cTitle . ' ' . $rId . ' ' . $cFile);
                            if (str_contains($cHaystack, strtolower($query))) {
                                $match = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }

    if (!$match) {
        continue;
    }

    $card = [
        'type' => 'artwork',
        'asset' => 'artwork:' . $artId,
        'id' => $artId,
        'file' => $file,
        'image' => board_image_url($file),
        'title' => $title,
        'subtitle' => $sheetId > 0 ? 'Ficha #' . $sheetId : 'Obra raíz #' . $artId,
        'target' => true,
        'target_artwork_id' => $artId,
        'count' => $count,
        'sheet_id' => $sheetId,
        'is_canonical' => true,
        'is_child' => false,
    ];

    if ($sheetId > 0) {
        $groupedCards[] = $card;
    } else {
        $ungroupedCards[] = $card;
    }
}

foreach (array_keys($mockupFiles) as $file) {
    $row = $mockupSheetByFile[$file] ?? [];
    $linkedArtworkId = (int)($row['artwork_id'] ?? 0);
    $linkedSheetId = (int)($row['artwork_sheet_id'] ?? 0);

    if ($linkedSheetId > 0 || $linkedArtworkId > 0) {
        continue;
    }

    $haystack = strtolower($file);
    if ($query !== '' && !str_contains($haystack, strtolower($query))) {
        continue;
    }

    $ungroupedCards[] = [
        'type' => 'mockup',
        'asset' => 'mockup:' . $file,
        'id' => 0,
        'file' => $file,
        'image' => board_image_url($file),
        'title' => $file,
        'subtitle' => 'Mockup sin agrupar',
        'target' => false,
        'target_artwork_id' => '',
        'count' => 0,
        'sheet_id' => 0,
        'is_canonical' => false,
        'is_child' => false,
    ];
}

// Pagination logic for Inbox (ungroupedCards)
$itemsPerPage = 30;
$totalInboxItems = count($ungroupedCards);
$totalPages = max(1, (int)ceil($totalInboxItems / $itemsPerPage));
$currentPage = min($totalPages, max(1, (int)($_GET['page'] ?? 1)));
$inboxPageItems = array_slice($ungroupedCards, ($currentPage - 1) * $itemsPerPage, $itemsPerPage);

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Organizador de Mockups - Mockup Lab</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .board-head { display:flex; flex-wrap:wrap; gap:10px; align-items:end; justify-content:space-between; margin-bottom:14px; }
        .board-search { display:flex; gap:8px; align-items:center; }
        .board-search input { min-width:260px; }
        .board-status { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .status-pill { border:1px solid var(--line); border-radius:999px; padding:6px 10px; color:var(--muted); background:var(--surface); font-size:12px; }

        /* Split Columns Workspace Dashboard */
        .board-layout { display: flex; gap: 20px; margin-top: 15px; align-items: stretch; height: calc(100vh - 180px); min-height: 500px; }
        .board-column { display: flex; flex-direction: column; overflow: hidden; }
        .main-column { flex: 1; }
        .sidebar-column { width: 440px; border-left: 1px solid var(--line); padding-left: 20px; display: flex; flex-direction: column; }
        
        .column-header { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: baseline; }
        .column-header h2 { font-size: 15px; font-weight: 700; margin: 0; }
        
        .image-board { display: grid; gap: 12px; overflow-y: auto; flex: 1; padding: 4px; }
        .is-grouped-tab { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
        
        .image-card { position:relative; border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); box-shadow:var(--shadow); overflow:hidden; display:flex; flex-direction:column; justify-content:space-between; }
        .image-card:hover { border-color:var(--accent); box-shadow:0 12px 26px rgba(0,0,0,.12); }
        .image-card img, .image-card .empty-img { width:100%; aspect-ratio:1; object-fit:cover; display:block; background:var(--surface-soft); border-bottom:1px solid var(--line); user-select:none; }
        .empty-img { display:grid; place-items:center; color:var(--muted); font-size:12px; }
        .card-body { padding:9px; display:grid; gap:4px; flex-grow:1; }
        .card-title { font-size:12px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .meta { color:var(--muted); font-size:11px; line-height:1.35; }
        
        .type-badge { font-size:10px; font-weight:800; border-radius:999px; padding:2px 6px; }
        .type-badge.artwork { background: #e2f0d9; color: #385723; }
        .type-badge.mockup { background: #fff2cc; color: #7f6000; }
        
        /* Inbox list styling */
        .bulk-actions-bar { background: var(--surface-soft); border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; margin-bottom: 12px; display: flex; flex-direction: column; gap: 8px; }
        .bulk-form-inputs { display: flex; gap: 8px; width: 100%; }
        .bulk-form-inputs select { flex: 1; min-width: 0; padding: 6px; font-size: 12px; border-radius: 4px; border: 1px solid var(--line); background: var(--surface); }
        .bulk-form-inputs button { font-size: 12px; padding: 6px 12px; cursor: pointer; white-space: nowrap; }
        
        .inbox-list { display: flex; flex-direction: column; gap: 8px; overflow-y: auto; flex: 1; padding: 2px; }
        .inbox-item { display: flex; align-items: center; gap: 10px; padding: 8px; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface); transition: border-color 0.15s; cursor: pointer; }
        .inbox-item:hover { border-color: var(--accent); }
        .inbox-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid var(--line); }
        .inbox-details { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .inbox-title { font-size: 11px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .pagination { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-top: 1px solid var(--line); margin-top: 8px; }
        .pagination span { font-size: 11px; }
        .pagination .button-link { font-size: 11px; padding: 4px 8px; }

        .card-footer { padding:8px; border-top:1px solid var(--line); display:flex; justify-content:center; background:var(--surface-soft); }
        .card-footer button { width:100%; text-align:center; font-size:11px; padding:4px 8px; cursor:pointer; }
        
        /* Inline Accordion Drawer */
        .group-drawer { padding:12px; background:var(--bg); border-top:1px solid var(--line); }
        .drawer-section-title { font-size:11px; font-weight:700; text-transform:uppercase; color:var(--muted); margin:12px 0 6px; letter-spacing:0.05em; display:flex; justify-content:space-between; }
        .drawer-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(64px, 1fr)); gap:8px; margin-bottom:8px; }
        .drawer-item { position:relative; border:1px solid var(--line); background:var(--surface); border-radius:4px; overflow:hidden; aspect-ratio:1; box-shadow:0 2px 6px rgba(0,0,0,0.02); }
        .drawer-item img { width:100%; height:100%; object-fit:cover; display:block; border-bottom:none !important; }
        .drawer-item.is-master { border:2px solid var(--accent); }
        .drawer-item.is-master::after { content:"★"; position:absolute; top:2px; left:3px; color:var(--accent); font-size:10px; text-shadow:0 0 2px #fff; }
        
        .ungroup-form { display:contents; }
        .ungroup-action-btn { position:absolute; top:2px; right:2px; width:16px; height:16px; border-radius:50%; background:rgba(20,20,18,0.7); color:#fff; border:none; font-size:11px; line-height:1; cursor:pointer; display:none; align-items:center; justify-content:center; transition:background .15s; }
        .drawer-item:hover .ungroup-action-btn { display:flex; }
        .ungroup-action-btn:hover { background:var(--danger); }
        
        @media (max-width: 1024px) {
            .board-layout { flex-direction: column; height: auto; }
            .sidebar-column { width: 100%; border-left: none; border-top: 1px solid var(--line); padding-left: 0; padding-top: 20px; height: 500px; }
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
        <div class="alert-strip">Organizador por Lotes: selecciona mockups en la bandeja derecha para agruparlos masivamente.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Organizador de Mockups</h1>
                    <p>Agrupa tus mockups y obras en lote de manera rápida y sin arrastres pesados.</p>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="board-head">
                <form class="board-search" method="get">
                    <input type="text" name="q" value="<?= h($query) ?>" placeholder="Buscar obra o archivo...">
                    <button class="button-link secondary" type="submit">Buscar</button>
                    <?php if ($query !== ''): ?><a class="button-link secondary" href="work_board.php">Limpiar</a><?php endif; ?>
                </form>
                <div class="board-status">
                    <span class="status-pill"><?= h($totalInboxItems) ?> elementos sin agrupar</span>
                </div>
            </div>

            <div class="board-layout">
                <!-- Groups Column (Left) -->
                <div class="board-column main-column">
                    <div class="column-header">
                        <h2>Fichas y Grupos Existentes (<?= count($groupedCards) ?>)</h2>
                    </div>
                    
                    <div class="image-board is-grouped-tab">
                        <?php foreach ($groupedCards as $card): ?>
                            <article
                                class="image-card"
                                data-card
                                data-sheet-id="<?= $card['sheet_id'] ?>"
                                data-artwork-id="<?= $card['id'] ?>"
                            >
                                <div>
                                    <span class="type-badge artwork" style="position:absolute;top:7px;right:7px;">GRUPO</span>
                                    <?php if ($card['image'] !== ''): ?>
                                        <img src="<?= h($card['image']) ?>" alt="<?= h($card['title']) ?>" loading="lazy" decoding="async">
                                    <?php else: ?>
                                        <div class="empty-img">Sin imagen</div>
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <span class="card-title" title="<?= h($card['title']) ?>"><?= h($card['title']) ?></span>
                                        <span class="meta"><?= h($card['subtitle']) ?></span>
                                    </div>
                                </div>

                                <div class="card-footer">
                                    <button class="button-link secondary expand-btn" type="button" data-summary="<?= h($card['count']) ?> obras" onclick="toggleGroupDrawer(this, event)">
                                        Ver contenido (<?= h($card['count']) ?> <?= $card['count'] === 1 ? 'obra' : 'obras' ?>)
                                    </button>
                                </div>
                                <div class="group-drawer" style="display:none;" onclick="event.stopPropagation();"></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Inbox Column (Right) -->
                <div class="board-column sidebar-column">
                    <div class="column-header">
                        <h2>Bandeja de Entrada</h2>
                        <a href="work_board.php?action=auto_group_wizard" class="button-link secondary accent-btn" style="border-color: var(--accent); color: var(--accent); font-size: 11px; padding: 4px 8px; font-weight:700;">⚡ Auto-Agrupar Inteligente</a>
                    </div>
                    
                    <form id="bulk-group-form" method="post" action="work_board.php">
                        <input type="hidden" name="action" value="group_assets">
                        
                        <div class="bulk-actions-bar">
                            <div style="display:flex; align-items:center; gap:6px;">
                                <input type="checkbox" id="select-all-inbox" onclick="toggleSelectAllInbox(this)" style="cursor:pointer;">
                                <label for="select-all-inbox" class="meta" style="cursor:pointer; user-select:none; font-weight:700;">Marcar todos en esta página</label>
                            </div>
                            
                            <div class="bulk-form-inputs">
                                <select name="target_artwork_id" required>
                                    <option value="">-- Asociar seleccionados a --</option>
                                    <?php foreach ($groupedCards as $gCard): ?>
                                        <option value="<?= $gCard['id'] ?>">Ficha #<?= $gCard['sheet_id'] ?>: <?= h($gCard['title']) ?></option>
                                    <?php endforeach; ?>
                                    <optgroup label="Iniciar nuevo grupo con:">
                                        <?php foreach ($ungroupedCards as $uCard): ?>
                                            <?php if ($uCard['type'] === 'artwork'): ?>
                                                <option value="<?= $uCard['id'] ?>">Obra #<?= $uCard['id'] ?>: <?= h($uCard['title']) ?></option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <button type="submit" class="button-link primary">Asociar</button>
                            </div>
                        </div>
                        
                        <div class="inbox-list">
                            <?php foreach ($inboxPageItems as $card): ?>
                                <div class="inbox-item" onclick="toggleCheckboxFromItem(this, event)">
                                    <input type="checkbox" name="assets[]" value="<?= h($card['asset']) ?>" class="inbox-checkbox" onclick="event.stopPropagation();">
                                    <?php if ($card['image'] !== ''): ?>
                                        <img src="<?= h($card['image']) ?>" class="inbox-thumb" draggable="false">
                                    <?php else: ?>
                                        <div class="inbox-thumb" style="display:grid;place-items:center;font-size:8px;color:var(--muted);">Sin img</div>
                                    <?php endif; ?>
                                    <div class="inbox-details">
                                        <span class="inbox-title" title="<?= h($card['title']) ?>"><?= h($card['title']) ?></span>
                                        <span class="meta"><?= h($card['subtitle']) ?></span>
                                    </div>
                                    <span class="type-badge <?= $card['type'] ?>"><?= h($card['type'] === 'artwork' ? 'OBRA' : 'MOCKUP') ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="pagination">
                            <?php if ($currentPage > 1): ?>
                                <a href="work_board.php?page=<?= $currentPage - 1 ?>&q=<?= h($query) ?>" class="button-link secondary">&laquo; Anterior</a>
                            <?php else: ?>
                                <span class="button-link secondary disabled" style="opacity:0.5;pointer-events:none;">&laquo; Anterior</span>
                            <?php endif; ?>
                            <span class="meta">Pág. <?= $currentPage ?> de <?= $totalPages ?></span>
                            <?php if ($currentPage < $totalPages): ?>
                                <a href="work_board.php?page=<?= $currentPage + 1 ?>&q=<?= h($query) ?>" class="button-link secondary">Siguiente &raquo;</a>
                            <?php else: ?>
                                <span class="button-link secondary disabled" style="opacity:0.5;pointer-events:none;">Siguiente &raquo;</span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </main>
</div>
<script>
function toggleSelectAllInbox(master) {
    var checkboxes = document.querySelectorAll('.inbox-checkbox');
    checkboxes.forEach(function (cb) {
        cb.checked = master.checked;
    });
}

function toggleCheckboxFromItem(itemDiv, event) {
    // If user clicked directly on checkbox, do nothing (checkbox's own handler runs)
    if (event.target.classList.contains('inbox-checkbox')) {
        return;
    }
    var cb = itemDiv.querySelector('.inbox-checkbox');
    if (cb) {
        cb.checked = !cb.checked;
    }
}

function toggleGroupDrawer(btn, event) {
    if (event) {
        event.stopPropagation();
    }
    var card = btn.closest('[data-card]');
    var drawer = card.querySelector('.group-drawer');
    var sheetId = card.getAttribute('data-sheet-id') || '0';
    var artworkId = card.getAttribute('data-artwork-id') || '0';
    var isVisible = drawer.style.display !== 'none';
    
    if (isVisible) {
        drawer.style.display = 'none';
        btn.textContent = 'Ver contenido (' + btn.getAttribute('data-summary') + ')';
    } else {
        drawer.style.display = 'block';
        btn.textContent = 'Ocultar contenido';
        
        if (!drawer.getAttribute('data-loaded')) {
            drawer.innerHTML = '<div style="padding: 10px; text-align: center; color: var(--muted); font-size:12px;">Cargando...</div>';
            
            fetch('work_board.php?action=get_group_contents&sheet_id=' + sheetId + '&artwork_id=' + artworkId)
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data.success) {
                        drawer.innerHTML = '<div style="padding: 10px; color: var(--danger); font-size:12px;">' + (data.error || 'Error al cargar') + '</div>';
                        return;
                    }
                    
                    var actualSheetId = data.sheet_id;
                    card.setAttribute('data-sheet-id', actualSheetId);
                    
                    var html = '';
                    
                    // Render Artworks
                    html += '<h4 class="drawer-section-title">Obras Agrupadas</h4>';
                    html += '<div class="drawer-grid">';
                    
                    // Master artwork
                    var cardImg = card.querySelector('img');
                    var masterSrc = cardImg ? cardImg.src : '';
                    html += '<div class="drawer-item is-master" title="Obra principal">';
                    if (masterSrc) {
                        html += '<img src="' + masterSrc + '">';
                    } else {
                        html += '<div class="empty-img">Sin imagen</div>';
                    }
                    html += '</div>';
                    
                    // Child artworks
                    data.child_artworks.forEach(function(art) {
                        html += '<div class="drawer-item" title="Obra asociada #' + art.id + ': ' + art.title + '">';
                        html += '<img src="' + art.image + '">';
                        html += '<form action="work_board.php" method="post" class="ungroup-form">';
                        html += '<input type="hidden" name="action" value="ungroup_asset">';
                        html += '<input type="hidden" name="sheet_id" value="' + actualSheetId + '">';
                        html += '<input type="hidden" name="asset" value="artwork:' + art.id + '">';
                        html += '<button type="submit" class="ungroup-action-btn" title="Desagrupar de esta ficha">&times;</button>';
                        html += '</form>';
                        html += '</div>';
                    });
                    html += '</div>';
                    
                    // Render Mockups
                    if (data.child_mockups.length > 0) {
                        html += '<h4 class="drawer-section-title">Mockups Asociados</h4>';
                        html += '<div class="drawer-grid">';
                        data.child_mockups.forEach(function(mock) {
                            html += '<div class="drawer-item" title="Mockup: ' + mock.file + '">';
                            html += '<img src="' + mock.image + '">';
                            html += '<form action="work_board.php" method="post" class="ungroup-form">';
                            html += '<input type="hidden" name="action" value="ungroup_asset">';
                            html += '<input type="hidden" name="sheet_id" value="' + actualSheetId + '">';
                            html += '<input type="hidden" name="asset" value="mockup:' + mock.file + '">';
                            html += '<button type="submit" class="ungroup-action-btn" title="Desagrupar de esta ficha">&times;</button>';
                            html += '</form>';
                            html += '</div>';
                        });
                        html += '</div>';
                    } else {
                        html += '<div style="padding: 4px 0; color: var(--muted); font-size:11px;">No hay mockups asociados</div>';
                    }
                    
                    drawer.innerHTML = html;
                    drawer.setAttribute('data-loaded', 'true');
                })
                .catch(function(err) {
                    drawer.innerHTML = '<div style="padding: 10px; color: var(--danger); font-size:12px;">Error de conexión</div>';
                });
        }
    }
}
</script>
</body>
</html>
