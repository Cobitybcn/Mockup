<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$pdo = Database::connection();
$sheetService = new ArtworkSheetService($pdo);

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

const FICHA_PROPOSAL_PATH = __DIR__ . '/storage/ficha_proposal.json';
const FICHA_HINTS_PATH = __DIR__ . '/storage/artwork_sheets_hints_20260703.json';
const FICHA_CONFIRMED_PATH = __DIR__ . '/storage/ficha_confirmed_groups.json';
const FICHA_COSINE_THRESHOLD = 0.86;

function reconcile_image_url(string $file): string
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

/** @return array<int, array> artworks del usuario indexadas por id */
function reconcile_artworks(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, root_file, main_file, final_title, created_at FROM artworks WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int)$row['id']] = $row;
    }
    return $byId;
}

/** @return array<string, int> mockup_file => artwork_id (linaje de generación) */
function reconcile_mockup_lineage(PDO $pdo, int $userId, array $artworksById): array
{
    $fileToArtwork = [];
    foreach ($artworksById as $id => $artwork) {
        foreach (['root_file', 'main_file'] as $key) {
            $file = basename(str_replace('\\', '/', (string)($artwork[$key] ?? '')));
            if ($file !== '' && !isset($fileToArtwork[$file])) {
                $fileToArtwork[$file] = (int)$id;
            }
        }
    }

    $lineage = [];
    $stmt = $pdo->prepare("SELECT mockup_file, artwork_file FROM mockups WHERE user_id = ? AND mockup_file <> ''");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mockupFile = basename(str_replace('\\', '/', (string)$row['mockup_file']));
        $artworkFile = basename(str_replace('\\', '/', (string)$row['artwork_file']));
        if ($mockupFile !== '' && isset($fileToArtwork[$artworkFile])) {
            $lineage[$mockupFile] = $fileToArtwork[$artworkFile];
        }
    }

    $stmt = $pdo->prepare("SELECT mockup_file, artwork_id FROM mockup_generation_jobs WHERE user_id = ? AND mockup_file IS NOT NULL AND mockup_file <> '' AND artwork_id > 0");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mockupFile = basename(str_replace('\\', '/', (string)$row['mockup_file']));
        $artworkId = (int)$row['artwork_id'];
        if ($mockupFile !== '' && !isset($lineage[$mockupFile]) && isset($artworksById[$artworkId])) {
            $lineage[$mockupFile] = $artworkId;
        }
    }
    return $lineage;
}

/** Propuesta inicial: union-find con pistas humanas + duplicados exactos + similitud de embeddings. */
function reconcile_build_proposal(PDO $pdo, int $userId): array
{
    $artworksById = reconcile_artworks($pdo, $userId);
    $ids = array_keys($artworksById);
    $index = array_flip($ids);
    $parent = range(0, count($ids) - 1);
    $find = function (int $i) use (&$parent, &$find): int {
        return $parent[$i] === $i ? $i : $parent[$i] = $find($parent[$i]);
    };
    $union = function (int $a, int $b) use (&$parent, $find): void {
        $parent[$find($a)] = $find($b);
    };

    // 1) Semilla, por prioridad: fichas actuales en la base (reflejan desacoples y
    //    eliminaciones del usuario) → agrupaciones confirmadas → pistas de los gestores de prueba.
    $stmtSeed = $pdo->prepare('SELECT related_artwork_ids, canonical_artwork_id FROM artwork_sheets WHERE user_id = ?');
    $stmtSeed->execute([$userId]);
    $currentSheets = $stmtSeed->fetchAll(PDO::FETCH_ASSOC);
    $confirmed = json_decode((string)@file_get_contents(FICHA_CONFIRMED_PATH), true);
    if ($currentSheets) {
        foreach ($currentSheets as $row) {
            $decoded = json_decode((string)$row['related_artwork_ids'], true);
            $members = is_array($decoded) ? $decoded : [];
            $members[] = (int)$row['canonical_artwork_id'];
            $members = array_values(array_filter(array_unique(array_map('intval', $members)), fn($id) => isset($index[$id])));
            for ($i = 1; $i < count($members); $i++) {
                $union($index[$members[0]], $index[$members[$i]]);
            }
        }
    } elseif (is_array($confirmed) && (int)($confirmed['user_id'] ?? 0) === $userId) {
        foreach ((array)($confirmed['groups'] ?? []) as $group) {
            $members = array_values(array_filter(array_map('intval', (array)($group['artwork_ids'] ?? [])), fn($id) => isset($index[$id])));
            for ($i = 1; $i < count($members); $i++) {
                $union($index[$members[0]], $index[$members[$i]]);
            }
        }
    } else {
        $hints = json_decode((string)@file_get_contents(FICHA_HINTS_PATH), true);
        foreach ((array)($hints['sheets'] ?? []) as $sheet) {
            if ((int)($sheet['user_id'] ?? 0) !== $userId) {
                continue;
            }
            $members = array_values(array_filter(array_map('intval', (array)($sheet['artwork_ids'] ?? [])), fn($id) => isset($index[$id])));
            for ($i = 1; $i < count($members); $i++) {
                $union($index[$members[0]], $index[$members[$i]]);
            }
        }
    }

    // 2) Duplicados exactos por contenido de archivo
    $service = new ArtworkEmbeddingService($pdo);
    $byHash = [];
    foreach ($artworksById as $id => $artwork) {
        $path = $service->resolveArtworkImagePath($artwork);
        if ($path !== null) {
            $byHash[hash_file('sha1', $path)][] = (int)$id;
        }
    }
    foreach ($byHash as $members) {
        for ($i = 1; $i < count($members); $i++) {
            $union($index[$members[0]], $index[$members[$i]]);
        }
    }

    // 3) Similitud semántica de embeddings: aglomerativo por centroides sobre los grupos
    //    base (resiste pares ruidosos mejor que unir par a par).
    $vectors = $service->loadVectors($userId);
    foreach ($vectors as &$vector) {
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $vector)));
        if ($norm > 0) {
            foreach ($vector as $k => $x) {
                $vector[$k] = $x / $norm;
            }
        }
    }
    unset($vector);

    $centroid = function (array $members) use ($vectors): ?array {
        $sum = null;
        foreach ($members as $id) {
            if (!isset($vectors[$id])) {
                continue;
            }
            if ($sum === null) {
                $sum = $vectors[$id];
                continue;
            }
            foreach ($vectors[$id] as $k => $x) {
                $sum[$k] += $x;
            }
        }
        if ($sum === null) {
            return null;
        }
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $sum)));
        if ($norm <= 0) {
            return null;
        }
        foreach ($sum as $k => $x) {
            $sum[$k] = $x / $norm;
        }
        return $sum;
    };

    $clusters = [];
    foreach ($ids as $id) {
        $clusters[$find($index[$id])][] = $id;
    }
    $clusters = array_values($clusters);
    $centroids = array_map($centroid, $clusters);

    while (true) {
        $bestI = -1;
        $bestJ = -1;
        $bestSim = FICHA_COSINE_THRESHOLD;
        $clusterCount = count($clusters);
        for ($i = 0; $i < $clusterCount; $i++) {
            if ($centroids[$i] === null) {
                continue;
            }
            for ($j = $i + 1; $j < $clusterCount; $j++) {
                if ($centroids[$j] === null) {
                    continue;
                }
                $sim = 0.0;
                foreach ($centroids[$i] as $k => $x) {
                    $sim += $x * $centroids[$j][$k];
                }
                if ($sim >= $bestSim) {
                    $bestSim = $sim;
                    $bestI = $i;
                    $bestJ = $j;
                }
            }
        }
        if ($bestI < 0) {
            break;
        }
        $clusters[$bestI] = array_merge($clusters[$bestI], $clusters[$bestJ]);
        $centroids[$bestI] = $centroid($clusters[$bestI]);
        array_splice($clusters, $bestJ, 1);
        array_splice($centroids, $bestJ, 1);
    }

    usort($clusters, fn($a, $b) => count($b) - count($a));

    // Canónica: la obra del grupo con más mockups generados; empate → id más bajo.
    $lineage = reconcile_mockup_lineage($pdo, $userId, $artworksById);
    $mockupCounts = array_count_values(array_values($lineage));

    $groups = [];
    foreach ($clusters as $i => $members) {
        sort($members);
        $canonical = $members[0];
        $bestCount = -1;
        foreach ($members as $memberId) {
            $memberCount = (int)($mockupCounts[$memberId] ?? 0);
            if ($memberCount > $bestCount) {
                $bestCount = $memberCount;
                $canonical = $memberId;
            }
        }
        $groups[] = ['key' => 'g' . ($i + 1), 'canonical' => $canonical, 'artwork_ids' => $members];
    }

    return [
        'generated_at' => date('c'),
        'user_id' => $userId,
        'threshold' => FICHA_COSINE_THRESHOLD,
        'groups' => $groups,
    ];
}

function reconcile_load_proposal(int $userId): ?array
{
    $proposal = json_decode((string)@file_get_contents(FICHA_PROPOSAL_PATH), true);
    return (is_array($proposal) && (int)($proposal['user_id'] ?? 0) === $userId) ? $proposal : null;
}

function reconcile_save_proposal(array $proposal): void
{
    file_put_contents(FICHA_PROPOSAL_PATH, json_encode($proposal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function reconcile_resequence(array $proposal): array
{
    $groups = array_values(array_filter($proposal['groups'], fn($g) => count($g['artwork_ids']) > 0));
    usort($groups, fn($a, $b) => count($b['artwork_ids']) - count($a['artwork_ids']));
    foreach ($groups as $i => &$group) {
        $group['key'] = 'g' . ($i + 1);
        if (!in_array($group['canonical'], $group['artwork_ids'], true)) {
            $group['canonical'] = $group['artwork_ids'][0];
        }
    }
    $proposal['groups'] = $groups;
    return $proposal;
}

$notice = (string)($_SESSION['fichas_reconcile_notice'] ?? '');
$error = (string)($_SESSION['fichas_reconcile_error'] ?? '');
unset($_SESSION['fichas_reconcile_notice'], $_SESSION['fichas_reconcile_error']);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $proposal = reconcile_load_proposal($userId);

        if ($action === 'merge_groups' && $proposal) {
            $keys = array_values(array_filter(array_map('strval', (array)($_POST['group_keys'] ?? []))));
            if (count($keys) < 2) {
                throw new RuntimeException('Select at least two groups to merge.');
            }
            $target = null;
            foreach ($proposal['groups'] as &$group) {
                if (!in_array($group['key'], $keys, true)) {
                    continue;
                }
                if ($target === null) {
                    $target = &$group;
                } else {
                    $target['artwork_ids'] = array_values(array_unique(array_merge($target['artwork_ids'], $group['artwork_ids'])));
                    $group['artwork_ids'] = [];
                }
            }
            unset($group, $target);
            reconcile_save_proposal(reconcile_resequence($proposal));
            $_SESSION['fichas_reconcile_notice'] = count($keys) . ' grupos fusionados.';
            header('Location: fichas_reconcile.php');
            exit;
        }

        if ($action === 'move_artwork' && $proposal) {
            $artworkId = (int)($_POST['artwork_id'] ?? 0);
            $targetKey = (string)($_POST['target_key'] ?? '');
            foreach ($proposal['groups'] as &$group) {
                $group['artwork_ids'] = array_values(array_diff($group['artwork_ids'], [$artworkId]));
            }
            unset($group);
            if ($targetKey === '__new__') {
                $proposal['groups'][] = ['key' => 'gx', 'canonical' => $artworkId, 'artwork_ids' => [$artworkId]];
            } else {
                $moved = false;
                foreach ($proposal['groups'] as &$group) {
                    if ($group['key'] === $targetKey) {
                        $group['artwork_ids'][] = $artworkId;
                        $moved = true;
                        break;
                    }
                }
                unset($group);
                if (!$moved) {
                    throw new RuntimeException('Grupo destino inexistente.');
                }
            }
            reconcile_save_proposal(reconcile_resequence($proposal));
            $_SESSION['fichas_reconcile_notice'] = 'Artwork #' . $artworkId . ' moved.';
            header('Location: fichas_reconcile.php');
            exit;
        }

        if ($action === 'set_canonical' && $proposal) {
            $artworkId = (int)($_POST['artwork_id'] ?? 0);
            $groupKey = (string)($_POST['group_key'] ?? '');
            foreach ($proposal['groups'] as &$group) {
                if ($group['key'] === $groupKey && in_array($artworkId, $group['artwork_ids'], true)) {
                    $group['canonical'] = $artworkId;
                }
            }
            unset($group);
            reconcile_save_proposal($proposal);
            header('Location: fichas_reconcile.php');
            exit;
        }

        if ($action === 'confirm_all' && $proposal) {
            $artworksById = reconcile_artworks($pdo, $userId);
            $lineage = reconcile_mockup_lineage($pdo, $userId, $artworksById);

            // Metadatos previos por obra canónica (para no perder lo ya cargado)
            $stmt = $pdo->prepare('SELECT * FROM artwork_sheets WHERE user_id = ?');
            $stmt->execute([$userId]);
            $oldMetaByArtwork = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $old) {
                if ((string)$old['title'] !== '' || (string)$old['description'] !== '' || (string)$old['tags'] !== '' || (string)$old['user_notes'] !== '') {
                    $oldMetaByArtwork[(int)$old['canonical_artwork_id']] = $old;
                }
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('DELETE FROM artwork_sheets WHERE user_id = ?')->execute([$userId]);

                $now = date('c');
                $insert = $pdo->prepare('
                    INSERT INTO artwork_sheets
                        (user_id, canonical_artwork_id, related_artwork_ids, source_image_file, user_notes,
                         title, subtitle, description, short_description, keywords, tags, alt_text, caption,
                         status, generated_json, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');

                $artworkToSheet = [];
                $sheetCount = 0;
                foreach ($proposal['groups'] as $group) {
                    $members = array_values(array_filter(array_map('intval', $group['artwork_ids']), fn($id) => isset($artworksById[$id])));
                    if (!$members) {
                        continue;
                    }
                    $canonical = in_array((int)$group['canonical'], $members, true) ? (int)$group['canonical'] : $members[0];
                    $sourceFile = basename((string)($artworksById[$canonical]['root_file'] ?: $artworksById[$canonical]['main_file'] ?: ''));

                    $meta = null;
                    foreach ($members as $memberId) {
                        if (isset($oldMetaByArtwork[$memberId])) {
                            $meta = $oldMetaByArtwork[$memberId];
                            if ($memberId === $canonical) {
                                break;
                            }
                        }
                    }

                    $insert->execute([
                        $userId,
                        $canonical,
                        json_encode($members, JSON_UNESCAPED_SLASHES),
                        $sourceFile,
                        (string)($meta['user_notes'] ?? ''),
                        (string)($meta['title'] ?? ''),
                        (string)($meta['subtitle'] ?? ''),
                        (string)($meta['description'] ?? ''),
                        (string)($meta['short_description'] ?? ''),
                        (string)($meta['keywords'] ?? ''),
                        (string)($meta['tags'] ?? ''),
                        (string)($meta['alt_text'] ?? ''),
                        (string)($meta['caption'] ?? ''),
                        'draft',
                        '',
                        $now,
                        $now,
                    ]);
                    $sheetId = (int)$pdo->lastInsertId();
                    $sheetCount++;
                    foreach ($members as $memberId) {
                        $artworkToSheet[$memberId] = $sheetId;
                    }
                }

                // Re-vincular mockup_sheets existentes y crear las que falten, por linaje.
                $stmt = $pdo->prepare('SELECT id, mockup_file FROM mockup_sheets WHERE user_id = ?');
                $stmt->execute([$userId]);
                $existingByFile = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingByFile[basename((string)$row['mockup_file'])][] = (int)$row['id'];
                }

                $updateSheet = $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = ?, artwork_id = ?, updated_at = ? WHERE id = ?');
                $orphanSheet = $pdo->prepare('UPDATE mockup_sheets SET artwork_sheet_id = NULL, updated_at = ? WHERE id = ?');
                $insertMockupSheet = $pdo->prepare('
                    INSERT INTO mockup_sheets
                        (user_id, artwork_sheet_id, artwork_id, mockup_id, mockup_file, user_notes, title, description,
                         keywords, tags, alt_text, caption, status, generated_json, created_at, updated_at)
                    VALUES (?, ?, ?, NULL, ?, ?, \'\', \'\', \'\', \'\', \'\', \'\', \'draft\', \'\', ?, ?)
                ');

                $linked = 0;
                $orphans = 0;
                $handled = [];
                foreach ($lineage as $mockupFile => $artworkId) {
                    $sheetId = $artworkToSheet[$artworkId] ?? null;
                    if ($sheetId === null) {
                        continue;
                    }
                    if (isset($existingByFile[$mockupFile])) {
                        foreach ($existingByFile[$mockupFile] as $rowId) {
                            $updateSheet->execute([$sheetId, $artworkId, $now, $rowId]);
                        }
                    } else {
                        $insertMockupSheet->execute([$userId, $sheetId, $artworkId, $mockupFile, 'Vinculado por linaje.', $now, $now]);
                    }
                    $handled[$mockupFile] = true;
                    $linked++;
                }

                // Mockup sheets sin linaje: quedan como huérfanas visibles.
                foreach ($existingByFile as $mockupFile => $rowIds) {
                    if (isset($handled[$mockupFile])) {
                        continue;
                    }
                    foreach ($rowIds as $rowId) {
                        $orphanSheet->execute([$now, $rowId]);
                    }
                    $orphans++;
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            // La agrupación confirmada queda como semilla definitiva: futuras corridas
            // del asistente parten de aquí y solo proponen para lo nuevo.
            $confirmedGroups = [];
            foreach ($proposal['groups'] as $group) {
                $members = array_values(array_filter(array_map('intval', $group['artwork_ids']), fn($id) => isset($artworksById[$id])));
                if ($members) {
                    $confirmedGroups[] = ['canonical' => (int)$group['canonical'], 'artwork_ids' => $members];
                }
            }
            file_put_contents(FICHA_CONFIRMED_PATH, json_encode([
                'confirmed_at' => date('c'),
                'user_id' => $userId,
                'groups' => $confirmedGroups,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @unlink(FICHA_PROPOSAL_PATH);

            $_SESSION['fichas_notice'] = "Reconciliation applied: {$sheetCount} sheets created, {$linked} mockups linked by lineage, {$orphans} files remain in the orphan tray.";
            header('Location: fichas.php');
            exit;
        }

        throw new RuntimeException('Invalid action or missing proposal.');
    }
} catch (Throwable $e) {
    $_SESSION['fichas_reconcile_error'] = $e->getMessage();
    header('Location: fichas_reconcile.php');
    exit;
}

$rebuild = isset($_GET['rebuild']);
$proposal = $rebuild ? null : reconcile_load_proposal($userId);
if ($proposal === null) {
    $proposal = reconcile_build_proposal($pdo, $userId);
    reconcile_save_proposal($proposal);
}

$artworksById = reconcile_artworks($pdo, $userId);
$lineage = reconcile_mockup_lineage($pdo, $userId, $artworksById);
$mockupCounts = array_count_values(array_values($lineage));
$groups = $proposal['groups'];
$totalArtworks = array_sum(array_map(fn($g) => count($g['artwork_ids']), $groups));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Confirmar Fichas - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .reconcile-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:16px; }
        .group-card { border:1px solid var(--line); border-radius:var(--radius); background:var(--surface); box-shadow:var(--shadow); margin-bottom:14px; }
        .group-head { display:flex; align-items:center; gap:10px; padding:10px 14px; border-bottom:1px solid var(--line); background:var(--surface-soft); }
        .group-head h3 { margin:0; font-size:14px; }
        .group-head .meta { font-size:11px; }
        .group-thumbs { display:flex; flex-wrap:wrap; gap:10px; padding:12px 14px; }
        .thumb-item { width:110px; text-align:center; }
        .thumb-item img, .thumb-item .empty-img { width:110px; height:110px; object-fit:cover; border:1px solid var(--line); border-radius:6px; display:block; background:var(--surface-soft); }
        .thumb-item.is-canonical img { border:2px solid var(--accent); }
        .thumb-item .meta { display:block; margin-top:3px; font-size:10px; }
        .thumb-actions { display:flex; gap:4px; justify-content:center; margin-top:4px; }
        .thumb-actions select { max-width:82px; font-size:10px; padding:2px; }
        .thumb-actions button { font-size:10px; padding:2px 6px; cursor:pointer; }
        .confirm-bar { position:sticky; bottom:0; background:var(--surface); border-top:2px solid var(--accent); padding:12px 16px; display:flex; gap:14px; align-items:center; justify-content:space-between; margin-top:20px; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">One-time review: confirm how your artworks are grouped. Mockups are linked automatically by lineage.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Confirmar Fichas Propuestas</h1>
                    <p><?= count($groups) ?> sheets proposed for <?= $totalArtworks ?> root artworks (AI visual similarity + previous groupings + exact duplicates).</p>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <form method="post" id="merge-form">
                <input type="hidden" name="action" value="merge_groups">
                <div class="reconcile-toolbar">
                    <button type="submit" class="button-link secondary">Merge selected groups</button>
                    <a class="button-link secondary" href="fichas_reconcile.php?rebuild=1" onclick="return confirm('Recalculate with the fast method without pair-by-pair Gemini verification? Manual adjustments on this screen will be lost. For the complete AI proposal: php scripts/build_ficha_proposal.php');">Recalculate (fast)</a>
                    <span class="meta">★ = cover image · to merge: select 2+ groups and choose Merge</span>
                </div>

                <?php foreach ($groups as $group): ?>
                    <?php $mockupTotal = 0; foreach ($group['artwork_ids'] as $memberId) { $mockupTotal += (int)($mockupCounts[$memberId] ?? 0); } ?>
                    <div class="group-card">
                        <div class="group-head">
                            <input type="checkbox" name="group_keys[]" value="<?= h($group['key']) ?>" title="Marcar para fusionar">
                            <h3>Ficha propuesta <?= h(strtoupper($group['key'])) ?></h3>
                            <span class="meta"><?= count($group['artwork_ids']) ?> root artworks · <?= $mockupTotal ?> mockups by lineage</span>
                        </div>
                        <div class="group-thumbs">
                            <?php foreach ($group['artwork_ids'] as $memberId): ?>
                                <?php
                                $artwork = $artworksById[$memberId] ?? null;
                                if (!$artwork) { continue; }
                                $file = basename((string)($artwork['root_file'] ?: $artwork['main_file'] ?: ''));
                                $url = reconcile_image_url($file);
                                $isCanonical = ((int)$group['canonical'] === $memberId);
                                ?>
                                <div class="thumb-item <?= $isCanonical ? 'is-canonical' : '' ?>">
                                    <?php if ($url !== ''): ?>
                                        <img src="<?= h($url) ?>" loading="lazy" decoding="async" title="Artwork #<?= $memberId ?>">
                                    <?php else: ?>
                                        <div class="empty-img">No image</div>
                                    <?php endif; ?>
                                    <span class="meta">#<?= $memberId ?><?= $isCanonical ? ' ★' : '' ?> · <?= (int)($mockupCounts[$memberId] ?? 0) ?> mk</span>
                                    <div class="thumb-actions">
                                        <select onchange="moveArtwork(<?= $memberId ?>, this.value); this.selectedIndex = 0;">
                                            <option value="">Mover…</option>
                                            <?php foreach ($groups as $other): ?>
                                                <?php if ($other['key'] !== $group['key']): ?>
                                                    <option value="<?= h($other['key']) ?>"><?= h(strtoupper($other['key'])) ?></option>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                            <option value="__new__">➜ Nueva ficha</option>
                                        </select>
                                        <?php if (!$isCanonical): ?>
                                            <button type="button" onclick="setCanonical(<?= $memberId ?>, '<?= h($group['key']) ?>')" title="Usar como portada">★</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>

            <form method="post" class="confirm-bar" onsubmit="return confirm('Sheets will be rebuilt with this grouping and all mockups will be linked by lineage. Confirm?');">
                <input type="hidden" name="action" value="confirm_all">
                <span><strong><?= count($groups) ?> sheets</strong> will be created; existing metadata will be preserved.</span>
                <button type="submit" class="button-link primary">Confirm and create sheets</button>
            </form>
        </div>
    </main>
</div>
<script>
function postAction(fields) {
    var form = document.createElement('form');
    form.method = 'post';
    Object.keys(fields).forEach(function (name) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fields[name];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.requestSubmit();
}
function moveArtwork(artworkId, targetKey) {
    if (!targetKey) { return; }
    postAction({ action: 'move_artwork', artwork_id: artworkId, target_key: targetKey });
}
function setCanonical(artworkId, groupKey) {
    postAction({ action: 'set_canonical', artwork_id: artworkId, group_key: groupKey });
}
</script>
</body>
</html>
