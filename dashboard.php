<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

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
            
            if ($jobId !== '' && preg_match('/^job_[0-9]+_[0-9]+$/', $jobId)) {
                // Delete job directory
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
                
                // Delete results candidates & meta files
                $resultsPattern = RESULTS_DIR . DIRECTORY_SEPARATOR . '*' . $jobId . '*';
                $matchedFiles = glob($resultsPattern);
                if (is_array($matchedFiles)) {
                    foreach ($matchedFiles as $resFile) {
                        if (is_file($resFile)) {
                            @unlink($resFile);
                        }
                    }
                }
            }
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

$stmt = $pdo->prepare("
    SELECT *
    FROM artworks
    WHERE user_id = :user_id
    AND status = 'done'
    AND root_file IS NOT NULL
    AND root_file != ''
    ORDER BY created_at DESC
");
$stmt->execute(['user_id' => $user['id']]);
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
                    <strong><?= count($artworks) ?></strong>
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
                            <form method="post" onsubmit="return confirm('¿Seguro que deseas limpiar todos los uploads atascados o con error? (Se mantendrán los iniciados hace menos de 5 minutos)');" style="margin: 0;">
                                <input type="hidden" name="action" value="clear_stuck">
                                <button type="submit" class="button-link secondary" style="font-size: 11px; padding: 6px 12px; border: 1px solid #e53e3e; color: #e53e3e; background: transparent; cursor: pointer; border-radius: 4px; transition: all 0.2s;">Limpiar atascados</button>
                            </form>
                        <?php endif; ?>
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
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="panel" id="obras">
                <div class="section-heading">
                    <h2>Root Images</h2>
                    <p><?= count($artworks) ?> pieces</p>
                </div>

                <?php if (!$artworks): ?>
                    <div class="empty-state">You have no root images uploaded yet.</div>
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
                                        <a href="form2.php?image=<?= rawurlencode(basename((string)$artwork['root_file'])) ?>">Mockups</a>
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
