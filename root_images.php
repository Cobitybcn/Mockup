<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

$query = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset = ($page - 1) * $perPage;

$where = "WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != ''";
$params = ['user_id' => (int)$user['id']];

if ($query !== '') {
    $where .= ' AND (final_title LIKE :query OR root_file LIKE :query OR series LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM artworks {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

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

function page_url(int $page, string $query): string
{
    $params = ['page' => $page];

    if ($query !== '') {
        $params['q'] = $query;
    }

    return 'root_images.php?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Root Images - Artwork Mockups</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            Your high-fidelity curated root images, isolated and verified, ready for context mockups.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Root Images</h1>
                    <p><?= h($total) ?> verified art pieces in your collection.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="artwork_new.php">Upload Artwork</a>
                    <a class="button-link secondary" href="root_album.php">Root Artworks</a>
                </div>
            </div>

            <form class="toolbar-form" method="get">
                <input type="text" name="q" value="<?= h($query) ?>" placeholder="Search by title, series, or filename">
                <button type="submit">Search</button>
                <?php if ($query !== ''): ?>
                    <a class="button-link secondary" href="root_images.php">Clear</a>
                <?php endif; ?>
            </form>

            <section class="panel">
                <div class="section-heading">
                    <h2>Root Image Library</h2>
                    <p>Page <?= h($page) ?> of <?= h($totalPages) ?></p>
                </div>

                <?php if (!$artworks): ?>
                    <div class="empty-state">No root images found. Get started by uploading a new artwork.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($artworks as $artwork): ?>
                            <article class="item-card">
                                <?php if (!empty($artwork['root_file']) && is_file(RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$artwork['root_file']))): ?>
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>" aria-label="Open artwork details">
                                        <img src="<?= h(result_url($artwork['root_file'])) ?>" alt="<?= h($artwork['final_title'] !== '' ? $artwork['final_title'] : 'Root artwork') ?>">
                                    </a>
                                <?php endif; ?>

                                <h3><?= h(Display::artworkTitle($artwork['root_file'], (string)$artwork['job_id'])) ?></h3>
                                <p class="meta-line">Dimensions: <?= h(trim(($artwork['width'] ?: '-') . ' x ' . ($artwork['height'] ?: '-') . ' ' . $artwork['unit'])) ?></p>

                                <div class="card-actions">
                                    <a href="artwork.php?id=<?= h($artwork['id']) ?>">Details</a>
                                    <?php if (!empty($artwork['root_file'])): ?>
                                        <a href="report.php?image=<?= rawurlencode(basename((string)$artwork['root_file'])) ?>">Mockups</a>
                                        <a href="<?= h(download_url($artwork['root_file'])) ?>" aria-label="Download root image" title="Download">
                                            <span class="download-icon" aria-hidden="true"></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a class="button-link secondary" href="<?= h(page_url($page - 1, $query)) ?>">Previous</a>
                        <?php endif; ?>

                        <span>Page <?= h($page) ?> / <?= h($totalPages) ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a class="button-link secondary" href="<?= h(page_url($page + 1, $query)) ?>">Next</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
