<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

Auth::requireUser();

header('Location: root_album.php');
exit;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_stuck') {
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
        
        // Delete if error, or if queued/processing and older than 5 minutes
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
    
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discard_pending') {
    $artworkId = (int)($_POST['artwork_id'] ?? 0);

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

    header('Location: dashboard.php#pendientes');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'discard_all_pending') {
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

    header('Location: dashboard.php#pendientes');
    exit;
}

$where = "WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != ''";
$params = ['user_id' => (int)$user['id']];

if ($query !== '') {
    $where .= ' AND (final_title LIKE :query OR root_file LIKE :query OR series LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

$rootCountStmt = $pdo->prepare("SELECT COUNT(*) FROM artworks {$where}");
$rootCountStmt->execute($params);
$rootTotal = (int)$rootCountStmt->fetchColumn();
$totalPages = max(1, (int)ceil($rootTotal / $perPage));

$stmt = $pdo->prepare("
    SELECT *
    FROM artworks
    {$where}
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}

$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$artworks = $stmt->fetchAll();

$pendingStmt = $pdo->prepare("
    SELECT *
    FROM artworks
    WHERE user_id = :user_id
    AND (status != 'done' OR root_file IS NULL OR root_file = '')
    ORDER BY created_at DESC
");
$pendingStmt->execute(['user_id' => $user['id']]);
$pendingArtworks = $pendingStmt->fetchAll();

$mockupStmt = $pdo->prepare("
    SELECT *
    FROM mockups
    WHERE user_id = :user_id
    ORDER BY created_at DESC
    LIMIT 12
");
$mockupStmt->execute(['user_id' => $user['id']]);
$mockups = $mockupStmt->fetchAll();

$mockupCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM mockups
    WHERE user_id = :user_id
");
$mockupCountStmt->execute(['user_id' => $user['id']]);
$mockupTotal = (int)$mockupCountStmt->fetchColumn();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function result_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) : '';
}

function download_url(?string $file): string
{
    return $file ? 'media.php?file=' . rawurlencode(basename($file)) . '&download=1' : '';
}

function dashboard_page_url(int $page, string $query): string
{
    $params = ['page' => $page];

    if ($query !== '') {
        $params['q'] = $query;
    }

    return 'dashboard.php?' . http_build_query($params) . '#obras';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Dashboard - The Artwork Curator</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            The Artwork Curator analyzes each artwork before generating mockups, helping artists choose visual environments that respect the work’s style, palette, composition and emotional atmosphere.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Dashboard</h1>
                    <p>Private archive of artworks, root images and mockups.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="artwork_new.php">Upload Artwork</a>
                    <a class="button-link secondary" href="account.php">Account</a>
                </div>
            </div>

            <section class="stats">
                <div class="stat-card">
                    <span>Root Images</span>
                    <strong><?= h($rootTotal) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Mockups</span>
                    <strong><?= h($mockupTotal) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Credits</span>
                    <strong><?= h($user['credits']) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Status</span>
                    <strong>Beta</strong>
                </div>
            </section>

            <?php if (!empty($pendingArtworks)): ?>
                <section class="panel" id="pendientes" style="border-left: 3px solid var(--gal-accent); background: rgba(154, 123, 86, 0.02); margin-bottom: 30px;">
                    <div class="section-heading" style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                        <div>
                            <h2>Pending Artworks & Selections</h2>
                            <p><?= count($pendingArtworks) ?> pieces requiring attention</p>
                        </div>
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
                                <button type="submit" class="button-link secondary" style="font-size: 11px; padding: 6px 12px; border: 1px solid #e53e3e; color: #e53e3e; background: transparent; cursor: pointer; border-radius: 4px; transition: all 0.2s;">Limpiar atascados</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Discard all pending artworks and selections? This cannot be undone.');" style="margin: 0;">
                            <input type="hidden" name="action" value="discard_all_pending">
                            <button type="submit" class="button-link secondary" style="width: auto; margin: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard all pending</button>
                        </form>
                    </div>
                    <div class="grid">
                        <?php foreach ($pendingArtworks as $pending): ?>
                            <article class="item-card" style="opacity: 0.95;">
                                <h3>
                                    <?= h($pending['final_title'] !== '' ? $pending['final_title'] : 'Artwork Upload (' . date('m/d H:i', strtotime($pending['created_at'])) . ')') ?>
                                </h3>
                                <div style="margin: 10px 0;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <span class="status-pill done" style="background: var(--gal-accent); color: white; border-color: var(--gal-accent);">Awaiting Selection</span>
                                    <?php else: ?>
                                        <span class="status-pill <?= h($pending['status']) ?>"><?= h($pending['status']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="meta-line">Uploaded: <?= h(date('Y-m-d H:i', strtotime($pending['created_at']))) ?></p>
                                <div class="card-actions" style="margin-top: 14px;">
                                    <?php if ($pending['status'] === 'awaiting_selection'): ?>
                                        <a class="button-link" href="root_select.php?job=<?= rawurlencode((string)$pending['job_id']) ?>" style="font-size: 11px; padding: 6px 12px; color: white !important;">Select Version</a>
                                    <?php else: ?>
                                        <a href="waiting.php?job=<?= rawurlencode((string)$pending['job_id']) ?>">View Status</a>
                                    <?php endif; ?>
                                    <form method="post" onsubmit="return confirm('Discard this pending artwork? This cannot be undone.');" style="margin: 0;">
                                        <input type="hidden" name="action" value="discard_pending">
                                        <input type="hidden" name="artwork_id" value="<?= h($pending['id']) ?>">
                                        <button type="submit" class="button-link secondary" style="width: auto; margin: 0; font-size: 11px; padding: 6px 12px; border-color: var(--danger); color: var(--danger); background: transparent;">Discard</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel" id="obras">
                <div class="section-heading">
                    <h2>Root Images</h2>
                    <p><?= h($rootTotal) ?> pieces</p>
                </div>

                <form class="toolbar-form" method="get" action="dashboard.php">
                    <input type="text" name="q" value="<?= h($query) ?>" placeholder="Search by title, series, or filename">
                    <button type="submit">Search</button>
                    <?php if ($query !== ''): ?>
                        <a class="button-link secondary" href="dashboard.php#obras">Clear</a>
                    <?php endif; ?>
                </form>

                <?php if (!$artworks): ?>
                    <div class="empty-state"><?= $query !== '' ? 'No root images found for this search.' : 'You have no root images uploaded yet.' ?></div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($artworks as $artwork): ?>
                            <article class="item-card">
                                <?php if (!empty($artwork['root_file']) && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$artwork['root_file']))): ?>
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>" aria-label="Open artwork file">
                                        <img src="<?= h(result_url($artwork['root_file'])) ?>" alt="Root artwork">
                                    </a>
                                <?php endif; ?>

                                <h3><?= h(Display::artworkTitle($artwork['root_file'], (string)$artwork['job_id'])) ?></h3>
                                <span class="status-pill <?= h($artwork['status']) ?>"><?= h($artwork['status']) ?></span>
                                <p class="meta-line">Dimensions: <?= h(trim(($artwork['width'] ?: '-') . ' x ' . ($artwork['height'] ?: '-') . ' ' . $artwork['unit'])) ?></p>

                                <div class="card-actions">
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>">Details</a>
                                    <?php if (!empty($artwork['root_file'])): ?>
                                        <a href="report.php?image=<?= rawurlencode(basename((string)$artwork['root_file'])) ?>">Mockups</a>
                                        <a href="<?= h(download_url($artwork['root_file'])) ?>" aria-label="Download root image" title="Download">
                                            <span class="download-icon" aria-hidden="true"></span>
                                        </a>
                                    <?php else: ?>
                                        <a href="waiting.php?job=<?= rawurlencode((string)$artwork['job_id']) ?>">View status</a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Root images pagination">
                        <?php if ($page > 1): ?>
                            <a class="button-link secondary" href="<?= h(dashboard_page_url($page - 1, $query)) ?>">Previous</a>
                        <?php endif; ?>

                        <span>Page <?= h($page) ?> / <?= h($totalPages) ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a class="button-link secondary" href="<?= h(dashboard_page_url($page + 1, $query)) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>

            <section class="panel" id="mockups">
                <div class="section-heading">
                    <h2>Recent Mockups</h2>
                    <p><a href="mockups.php"><?= h($mockupTotal) ?> total</a></p>
                </div>

                <?php if (!$mockups): ?>
                    <div class="empty-state">You have no mockups generated yet.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <a href="viewer.php?id=<?= h($mockup['id']) ?>" aria-label="Open mockup">
                                    <img src="<?= h(result_url($mockup['mockup_file'])) ?>" alt="Mockup">
                                </a>
                                <h3><?= h(Display::contextTitle($mockup['context_id'])) ?></h3>
                                <div class="card-actions">
                                    <a href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Download mockup" title="Download">
                                        <span class="download-icon" aria-hidden="true"></span>
                                    </a>
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
