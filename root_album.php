<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$isAdmin = Auth::isAdmin($user);
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$artwork = null;

if ($id > 0) {
    if ($isAdmin) {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'id' => $id,
            'user_id' => (int)$user['id'],
        ]);
    }
    $artwork = $stmt->fetch();
    if (!is_array($artwork)) {
        http_response_code(404);
        die('Artwork not found.');
    }
}

if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_root_candidate') {
    $candidateId = max(0, (int)($_POST['candidate_id'] ?? 0));
    $candidateFile = basename((string)($_POST['candidate_file'] ?? ''));
    if ($candidateFile !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $candidateFile)) {
        Database::withBusyRetry(function () use ($pdo, $id, $artwork, $candidateId, $candidateFile): void {
            $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 0 WHERE artwork_id = :artwork_id')
                ->execute(['artwork_id' => $id]);
            if ($candidateId > 0) {
                $pdo->prepare('UPDATE root_artwork_candidates SET is_selected = 1 WHERE id = :id AND artwork_id = :artwork_id')
                    ->execute(['id' => $candidateId, 'artwork_id' => $id]);
            }
            $pdo->prepare('UPDATE artworks SET root_file = :root_file, status = :status, updated_at = :updated_at WHERE id = :id AND user_id = :user_id')
                ->execute([
                    'root_file' => $candidateFile,
                    'status' => 'done',
                    'updated_at' => date('c'),
                    'id' => $id,
                    'user_id' => (int)($artwork['user_id'] ?? 0),
                ]);
        }, 12);
    }

    header('Location: root_album.php?id=' . rawurlencode((string)$id) . '&selected=1');
    exit;
}

function delete_artwork_job_assets(string $jobId): void
{
    $jobId = basename($jobId);

    if ($jobId === '' || !preg_match('/^job_[0-9]+_[0-9]+$/', $jobId)) {
        return;
    }

    $jobDir = __DIR__ . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId;
    if (is_dir($jobDir)) {
        $deleteDirFunc = function (string $dir) use (&$deleteDirFunc): bool {
            if (!is_dir($dir)) return false;
            $items = @scandir($dir);
            if (!is_array($items)) return false;
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (is_dir($path)) {
                    $deleteDirFunc($path);
                } else {
                    @unlink($path);
                }
            }
            return @rmdir($dir);
        };
        $deleteDirFunc($jobDir);
    }

    $matchedFiles = glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*' . $jobId . '*');
    if (is_array($matchedFiles)) {
        foreach ($matchedFiles as $resFile) {
            if (is_file($resFile)) {
                @unlink($resFile);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id <= 0) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'clear_stuck') {
        $stmtJobs = $pdo->prepare("
            SELECT id, job_id, status, created_at
            FROM artworks
            WHERE user_id = :user_id
            AND status IN ('queued', 'processing', 'error')
        ");
        $stmtJobs->execute(['user_id' => $user['id']]);
        $candidates = $stmtJobs->fetchAll();

        $idsToDelete = [];
        $now = time();
        
        foreach ($candidates as $c) {
            $status = $c['status'];
            $createdAt = strtotime((string)$c['created_at']);
            
            if ($status === 'error' || ($now - $createdAt) > 300) {
                $idsToDelete[] = (int)$c['id'];
                $jobId = basename((string)$c['job_id']);
                delete_artwork_job_assets($jobId);
            }
        }

        if (!empty($idsToDelete)) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE id IN ($placeholders) AND user_id = ?");
            $params = array_merge($idsToDelete, [(int)$user['id']]);
            $stmtDelete->execute($params);
        }
        
        header('Location: root_album.php');
        exit;
    }

    if ($action === 'discard_pending') {
        $artworkId = (int)$_POST['artwork_id'];
        if ($artworkId > 0) {
            $stmtPending = $pdo->prepare("
                SELECT id, job_id
                FROM artworks
                WHERE id = :id
                AND user_id = :user_id
                AND status != 'done'
                LIMIT 1
            ");
            $stmtPending->execute([
                'id' => $artworkId,
                'user_id' => (int)$user['id'],
            ]);
            $pending = $stmtPending->fetch();

            if ($pending) {
                delete_artwork_job_assets((string)($pending['job_id'] ?? ''));
                $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE id = :id AND user_id = :user_id AND status != 'done'");
                $stmtDelete->execute([
                    'id' => $artworkId,
                    'user_id' => (int)$user['id'],
                ]);
            }
        }

        header('Location: root_album.php');
        exit;
    }

    if ($action === 'discard_all_pending') {
        $stmtPending = $pdo->prepare("
            SELECT id, job_id
            FROM artworks
            WHERE user_id = :user_id
            AND status != 'done'
        ");
        $stmtPending->execute(['user_id' => (int)$user['id']]);
        $pendingRows = $stmtPending->fetchAll();

        foreach ($pendingRows as $pending) {
            delete_artwork_job_assets((string)($pending['job_id'] ?? ''));
        }

        $stmtDelete = $pdo->prepare("DELETE FROM artworks WHERE user_id = :user_id AND status != 'done'");
        $stmtDelete->execute(['user_id' => (int)$user['id']]);

        header('Location: root_album.php');
        exit;
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function root_album_label(string $viewType): string
{
    return [
        'frontal' => 'Frontal',
        'three-quarter-left' => '3/4 Left',
        'three-quarter-right' => '3/4 Right',
    ][$viewType] ?? ucwords(str_replace(['-', '_'], ' ', $viewType));
}

function root_album_normalized_view_type(string $viewType): string
{
    $value = strtolower(trim($viewType));
    $value = str_replace(['_', ' '], '-', $value);

    if (in_array($value, ['front', 'frontal', 'straight', 'straight-on'], true)) {
        return 'frontal';
    }
    if (in_array($value, ['three-quarter-left', '3-4-left', '3/4-left', 'left'], true)) {
        return 'three-quarter-left';
    }
    if (in_array($value, ['three-quarter-right', '3-4-right', '3/4-right', 'right'], true)) {
        return 'three-quarter-right';
    }

    return $value;
}

function root_album_media_url(string $file): string
{
    return 'media.php?file=' . rawurlencode(basename($file));
}

function root_album_sibling_root_candidates(string $rootFile): array
{
    $rootFile = basename($rootFile);
    if (!preg_match('/^(.*)_v(\d+)(\.[A-Za-z0-9]+)$/', $rootFile, $matches)) {
        return [];
    }

    $labels = [
        1 => 'frontal',
        2 => 'three-quarter-left',
        3 => 'three-quarter-right',
    ];
    $candidates = [];
    for ($version = 1; $version <= 3; $version++) {
        $file = $matches[1] . '_v' . $version . $matches[3];
        if (!is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $candidates[] = [
            'id' => 0,
            'file_name' => $file,
            'view_type' => $labels[$version] ?? 'frontal',
            'is_selected' => $file === $rootFile,
        ];
    }

    return $candidates;
}

$albumArtworks = [];
$candidates = [];
$missing = [];
$albumLoadError = '';
$rootTotal = 0;
$mockupTotal = 0;
$variantRootTotal = 0;
$pendingArtworks = [];

if ($id <= 0) {
    try {
        (new ArtworkGroupService($pdo))->syncUser((int)$user['id']);

        $groupSql = "
            SELECT g.id AS group_id,
                   g.canonical_artwork_id,
                   g.official_root_artwork_ids,
                   g.title AS group_title,
                   g.updated_at,
                   g.created_at,
                   a.root_file,
                   a.final_title,
                   a.subtitle,
                   a.width,
                   a.height,
                   a.unit,
                   COUNT(DISTINCT roots.id) AS root_count,
                   SUM(CASE WHEN roots.root_view_status = 'official' THEN 1 ELSE 0 END) AS official_count,
                   SUM(CASE WHEN roots.root_view_status = 'variant' THEN 1 ELSE 0 END) AS variant_count,
                   COUNT(DISTINCT m.id) AS mockup_count
            FROM artwork_groups g
            INNER JOIN artworks a ON a.id = g.canonical_artwork_id
            LEFT JOIN artworks roots ON roots.artwork_group_id = g.id AND roots.user_id = g.user_id
            LEFT JOIN mockups m ON m.artwork_group_id = g.id AND m.user_id = g.user_id
            WHERE g.status = 'active'
        ";
        $groupSql .= " AND g.user_id = :user_id";
        $groupParams = ['user_id' => (int)$user['id']];
        $groupSql .= "
            GROUP BY g.id, g.canonical_artwork_id, g.official_root_artwork_ids, g.title, g.updated_at, g.created_at,
                     a.root_file, a.final_title, a.subtitle, a.width, a.height, a.unit
            ORDER BY g.updated_at DESC, g.created_at DESC
        ";

        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute($groupParams);
        foreach ($groupStmt->fetchAll() as $row) {
            $file = basename((string)($row['root_file'] ?? ''));
            if ($file === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
                continue;
            }
            $row['root_file'] = $file;
            $row['id'] = (int)$row['canonical_artwork_id'];
            $row['source'] = 'artwork_groups';
            $albumArtworks[] = $row;
        }

        // Get totals for stats header
        $rootCountSql = "SELECT COUNT(*) FROM artwork_groups WHERE status = 'active' AND user_id = :user_id";
        $rootParams = ['user_id' => (int)$user['id']];
        $rootCountStmt = $pdo->prepare($rootCountSql);
        $rootCountStmt->execute($rootParams);
        $rootTotal = (int)$rootCountStmt->fetchColumn();

        $variantCountSql = "SELECT COUNT(*) FROM artworks WHERE artwork_group_id IS NOT NULL AND root_view_status = 'variant' AND user_id = :user_id";
        $variantParams = ['user_id' => (int)$user['id']];
        $variantCountStmt = $pdo->prepare($variantCountSql);
        $variantCountStmt->execute($variantParams);
        $variantRootTotal = (int)$variantCountStmt->fetchColumn();

        $mockupCountSql = "SELECT COUNT(*) FROM mockups WHERE user_id = :user_id";
        $mockupParams = ['user_id' => (int)$user['id']];
        $mockupCountStmt = $pdo->prepare($mockupCountSql);
        $mockupCountStmt->execute($mockupParams);
        $mockupTotal = (int)$mockupCountStmt->fetchColumn();

        $pendingSql = "
            SELECT *
            FROM artworks
            WHERE (status != 'done' OR root_file IS NULL OR root_file = '')
        ";
        $pendingSql .= " AND user_id = :user_id";
        $pendingParams = ['user_id' => (int)$user['id']];
        $pendingSql .= " ORDER BY created_at DESC";
        $pendingStmt = $pdo->prepare($pendingSql);
        $pendingStmt->execute($pendingParams);
        $pendingArtworks = $pendingStmt->fetchAll();
    } catch (Throwable $e) {
        $albumLoadError = $e->getMessage();
    }
} else {
    $candidateStmt = $pdo->prepare('
        SELECT id, file_name, view_type, is_selected
        FROM root_artwork_candidates
        WHERE artwork_id = :artwork_id
        ORDER BY id ASC
    ');
    $candidateStmt->execute(['artwork_id' => $id]);
    foreach ($candidateStmt->fetchAll() as $candidate) {
        $file = basename((string)($candidate['file_name'] ?? ''));
        if ($file === '' || !is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $file)) {
            continue;
        }
        $candidates[] = [
            'id' => (int)$candidate['id'],
            'file_name' => $file,
            'view_type' => (string)($candidate['view_type'] ?? 'frontal'),
            'is_selected' => (bool)$candidate['is_selected'] || $file === basename((string)($artwork['root_file'] ?? '')),
        ];
    }

    if (!$candidates) {
        $rootFile = basename((string)($artwork['root_file'] ?? ''));
        if ($rootFile !== '' && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile)) {
            $candidates[] = [
                'id' => 0,
                'file_name' => $rootFile,
                'view_type' => 'frontal',
                'is_selected' => true,
            ];
        }
    }

    $rootFile = basename((string)($artwork['root_file'] ?? ''));
    $knownCandidateFiles = [];
    foreach ($candidates as $candidate) {
        $knownCandidateFiles[(string)$candidate['file_name']] = true;
    }
    foreach (root_album_sibling_root_candidates($rootFile) as $siblingCandidate) {
        if (isset($knownCandidateFiles[(string)$siblingCandidate['file_name']])) {
            continue;
        }
        $candidates[] = $siblingCandidate;
        $knownCandidateFiles[(string)$siblingCandidate['file_name']] = true;
    }

    $available = [];
    foreach ($candidates as $candidate) {
        $available[root_album_normalized_view_type((string)$candidate['view_type'])] = true;
    }
    foreach (['frontal', 'three-quarter-left', 'three-quarter-right'] as $viewType) {
        if (empty($available[$viewType])) {
            $missing[] = root_album_label($viewType);
        }
    }
    if (count($candidates) >= 3) {
        $missing = [];
    }
}

function root_album_adopt_root_artwork(PDO $pdo, int $userId, string $rootFile): array
{
    $rootFile = basename($rootFile);
    $jobId = 'adopted_root_' . $userId . '_' . substr(sha1($rootFile), 0, 16);

    $stmt = $pdo->prepare('SELECT id, final_title, subtitle, width, height, unit FROM artworks WHERE user_id = :user_id AND job_id = :job_id LIMIT 1');
    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $existing = $stmt->fetch();
    if (is_array($existing)) {
        return $existing;
    }

    $now = date('c');
    Database::withBusyRetry(function () use ($pdo, $userId, $jobId, $rootFile, $now): void {
        $stmt = $pdo->prepare('
            INSERT INTO artworks (user_id, job_id, main_file, root_file, status, width, height, depth, unit, created_at, updated_at)
            VALUES (:user_id, :job_id, :main_file, :root_file, :status, :width, :height, :depth, :unit, :created_at, :updated_at)
        ');
        $stmt->execute([
            'user_id' => $userId,
            'job_id' => $jobId,
            'main_file' => $rootFile,
            'root_file' => $rootFile,
            'status' => 'done',
            'width' => '',
            'height' => '',
            'depth' => '',
            'unit' => 'cm',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }, 12);

    $stmt->execute([
        'user_id' => $userId,
        'job_id' => $jobId,
    ]);
    $created = $stmt->fetch();
    return is_array($created) ? $created : [
        'id' => 0,
        'final_title' => '',
        'subtitle' => '',
        'width' => '',
        'height' => '',
        'unit' => 'cm',
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Root Album - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .root-album-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }
        .root-album-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 12px;
            box-shadow: var(--shadow);
        }
        .root-album-card.is-selected {
            border-color: var(--accent);
            background: #fbf7ef;
        }
        .root-album-card img {
            display: block;
            width: 100%;
            height: 210px;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface-soft);
        }
        .root-album-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
        }
        .root-album-meta strong {
            font-size: 11px;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .root-album-meta span {
            color: var(--accent);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .root-album-card button {
            width: 100%;
            margin-top: 12px;
        }
        .root-album-title {
            margin: 10px 0 0;
            font-family: var(--font-serif);
            font-size: 20px;
            line-height: 1.2;
            color: var(--ink);
        }
        .root-album-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 12px;
        }
        .root-album-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
        }
        .root-album-actions form {
            margin: 0;
        }
        .root-album-actions button,
        .root-album-actions .button-link {
            width: auto;
            margin: 0;
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
        <?php if ($id <= 0): ?>
            <div class="alert-strip">
                Artwork Mockups analyzes each artwork before generating mockups, helping artists choose visual environments that respect the work’s style, palette, composition and emotional atmosphere.
            </div>
        <?php endif; ?>
        <div class="workspace">
            <?php if ($id <= 0): ?>
                <div class="workspace-header">
                    <div>
                        <h1>Root Artworks</h1>
                        <p>Canonical artworks with official root views and attached mockups.</p>
                    </div>
                    <div class="topbar-actions">
                        <a class="button-link" href="artwork_new.php">Upload Artwork</a>
                        <a class="button-link secondary" href="account.php">Account</a>
                    </div>
                </div>

                <section class="stats">
                    <div class="stat-card">
                        <span>Artworks</span>
                        <strong><?= h($rootTotal) ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>Mockups</span>
                        <strong><?= h($mockupTotal) ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>Variants</span>
                        <strong><?= h($variantRootTotal) ?></strong>
                    </div>
                    <div class="stat-card">
                        <span>Credits</span>
                        <strong><?= h($user['credits']) ?></strong>
                    </div>
                </section>
            <?php else: ?>
                <div class="workspace-header">
                    <div>
                        <h1>Root Album</h1>
                        <p>Official root views for this artwork.</p>
                    </div>
                    <div class="root-album-actions">
                        <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                        <?php if (!empty($missing)): ?>
                            <form method="post" action="complete_root_views.php" onsubmit="return confirm('Generate missing root views from the current root image?');">
                                <input type="hidden" name="artwork_id" value="<?= (int)$id ?>">
                                <button type="submit">Complete Root Views</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($id <= 0 && !empty($pendingArtworks)): ?>
                <section class="panel" id="pendientes" style="border-left: 3px solid var(--accent); background: rgba(154, 123, 86, 0.02); margin-bottom: 30px;">
                    <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h2 style="margin:0;">Pending Artworks & Selections</h2>
                            <p style="margin:4px 0 0; font-size:12px; color:var(--muted);"><?= count($pendingArtworks) ?> pieces requiring attention</p>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <?php
                            $hasClearable = false;
                            $now = time();
                            foreach ($pendingArtworks as $pending) {
                                $status = $pending['status'];
                                $createdAt = strtotime((string)$pending['created_at']);
                                if ($status === 'error' || (($status === 'queued' || $status === 'processing') && ($now - $createdAt) > 300)) {
                                    $hasClearable = true;
                                    break;
                                }
                            }
                            if ($hasClearable):
                            ?>
                                <form method="post" onsubmit="return confirm('Are you sure you want to clean all stuck or failed uploads? Uploads started less than 5 minutes ago will be kept.');" style="margin: 0;">
                                    <input type="hidden" name="action" value="clear_stuck">
                                    <button type="submit" class="button-link secondary" style="font-size: 11px; padding: 6px 12px; border: 1px solid #e53e3e; color: #e53e3e; background: transparent; cursor: pointer; border-radius: 4px; transition: all 0.2s; width: auto; margin-top: 0;">Limpiar atascados</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('Discard all pending artworks and selections? This cannot be undone.');" style="margin: 0;">
                                <input type="hidden" name="action" value="discard_all_pending">
                                <button type="submit" class="button-link secondary" style="width: auto; margin-top: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard all pending</button>
                            </form>
                        </div>
                    </div>
                    <div class="grid">
                        <?php foreach ($pendingArtworks as $pending): ?>
                            <article class="item-card" style="opacity: 0.95; background: var(--surface); padding: 16px; border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow);">
                                <h3 style="margin: 0 0 8px; font-size: 14px; text-align: left; font-family: var(--font-sans); font-weight: 600;">
                                    <?= h($pending['final_title'] !== '' ? $pending['final_title'] : 'Artwork Upload (' . date('m/d H:i', strtotime($pending['created_at'])) . ')') ?>
                                </h3>
                                <div style="margin: 8px 0; text-align: left;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <span class="status-pill done" style="background: var(--accent); color: white; border-color: var(--accent); margin: 0;">Awaiting Selection</span>
                                    <?php else: ?>
                                        <span class="status-pill <?= h($pending['status']) ?>" style="margin: 0;"><?= h($pending['status']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="meta-line" style="text-align: left; margin: 4px 0 0; font-size: 11px;">Uploaded: <?= h(date('Y-m-d H:i', strtotime($pending['created_at']))) ?></p>
                                <div class="card-actions" style="margin-top: 14px; display: flex; gap: 8px; justify-content: flex-start;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <a class="button-link" href="root_select.php?job=<?= rawurlencode((string)$pending['job_id']) ?>" style="font-size: 11px; padding: 6px 12px; color: white !important; width: auto; margin-top: 0;">Select Version</a>
                                    <?php else: ?>
                                        <a class="button-link secondary" href="waiting.php?job=<?= rawurlencode((string)$pending['job_id']) ?>" style="font-size: 11px; padding: 6px 12px; width: auto; margin-top: 0;">View Status</a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Discard this pending artwork? This cannot be undone.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="discard_pending">
                                        <input type="hidden" name="artwork_id" value="<?= h($pending['id']) ?>">
                                        <button type="submit" class="button-link secondary" style="width: auto; margin-top: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (isset($_GET['selected'])): ?>
                <div class="notice">Official root view updated.</div>
            <?php endif; ?>

            <?php if ($id > 0 && !empty($missing)): ?>
                <div class="notice">Missing root views: <?= h(implode(', ', $missing)) ?>.</div>
            <?php endif; ?>
            <?php if ($albumLoadError !== ''): ?>
                <div class="notice error"><?= h($albumLoadError) ?></div>
            <?php endif; ?>

            <section class="panel">
                <?php if ($id <= 0): ?>
                    <?php if ($albumArtworks): ?>
                        <div class="root-album-grid">
                            <?php foreach ($albumArtworks as $albumArtwork): ?>
                                <?php
                                $title = trim((string)($albumArtwork['group_title'] ?? ''));
                                if ($title === '') {
                                    $title = trim((string)($albumArtwork['final_title'] ?? ''));
                                }
                                if ($title === '') {
                                    $title = 'Untitled';
                                }
                                $width = trim((string)($albumArtwork['width'] ?? ''));
                                $height = trim((string)($albumArtwork['height'] ?? ''));
                                $unit = trim((string)($albumArtwork['unit'] ?? 'cm'));
                                $size = ($width !== '' && $height !== '') ? trim($width . ' x ' . $height . ' ' . $unit) : '';
                                $targetUrl = 'artwork_details.php?id=' . (int)$albumArtwork['id'];
                                $detailsUrl = $targetUrl;
                                $rootCount = (int)($albumArtwork['root_count'] ?? 0);
                                $officialCount = (int)($albumArtwork['official_count'] ?? 0);
                                $variantCount = (int)($albumArtwork['variant_count'] ?? 0);
                                $mockupCount = (int)($albumArtwork['mockup_count'] ?? 0);
                                ?>
                                <article class="root-album-card">
                                    <a href="<?= h($targetUrl) ?>">
                                        <img src="<?= h(root_album_media_url((string)$albumArtwork['root_file'])) ?>" alt="<?= h($title) ?>" loading="lazy">
                                    </a>
                                    <h2 class="root-album-title"><?= h($title) ?></h2>
                                    <p class="root-album-subtitle">
                                        Group #<?= (int)($albumArtwork['group_id'] ?? 0) ?> · Artwork #<?= (int)($albumArtwork['id'] ?? 0) ?>
                                        <?= $size !== '' ? ' - ' . h($size) : '' ?>
                                        · <?= h((string)$officialCount) ?> official / <?= h((string)$rootCount) ?> roots
                                        <?= $variantCount > 0 ? ' · ' . h((string)$variantCount) . ' variants' : '' ?>
                                        <?= $mockupCount > 0 ? ' · ' . h((string)$mockupCount) . ' mockups' : '' ?>
                                    </p>
                                    <?php if ($detailsUrl !== ''): ?>
                                        <a class="button-link secondary" href="<?= h($detailsUrl) ?>">Artwork Details</a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="notice">No root artworks are available yet.</div>
                    <?php endif; ?>
                <?php elseif ($candidates): ?>
                    <div class="root-album-grid">
                        <?php foreach ($candidates as $candidate): ?>
                            <article class="root-album-card <?= $candidate['is_selected'] ? 'is-selected' : '' ?>">
                                <a href="<?= h('viewer.php?file=' . rawurlencode($candidate['file_name']) . '&back=' . rawurlencode('root_album.php?id=' . (int)$id)) ?>">
                                    <img src="<?= h(root_album_media_url($candidate['file_name'])) ?>" alt="<?= h(root_album_label($candidate['view_type'])) ?>" loading="lazy">
                                </a>
                                <div class="root-album-meta">
                                    <strong><?= h(root_album_label($candidate['view_type'])) ?></strong>
                                    <?php if ($candidate['is_selected']): ?>
                                        <span>Selected</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$candidate['is_selected']): ?>
                                    <form method="post">
                                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                                        <input type="hidden" name="action" value="select_root_candidate">
                                        <input type="hidden" name="candidate_id" value="<?= (int)$candidate['id'] ?>">
                                        <input type="hidden" name="candidate_file" value="<?= h($candidate['file_name']) ?>">
                                        <button type="submit" class="secondary">Set As Official Root</button>
                                    </form>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="notice">No root images are available for this artwork yet.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
