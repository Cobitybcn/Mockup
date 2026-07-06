<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();

$query = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;
$offset = ($page - 1) * $perPage;

$where = 'WHERE user_id = :user_id';
$params = ['user_id' => (int)$user['id']];

if ($query !== '') {
    $where .= ' AND (context_id LIKE :query OR mockup_file LIKE :query OR artwork_file LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mockups {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT *
    FROM mockups
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
$mockups = $stmt->fetchAll();

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

    return 'mockups.php?' . http_build_query($params);
}

function pagination_pages(int $current, int $total): array
{
    $pages = [1, $total];
    for ($i = $current - 2; $i <= $current + 2; $i++) {
        if ($i >= 1 && $i <= $total) {
            $pages[] = $i;
        }
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    return $pages;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Album - The Artwork Curator</title>
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
            Full archive of generated curatorial mockups.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Mockup Album</h1>
                    <p><?= h($total) ?> images saved in your private archive.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="artwork_new.php">Upload Artwork</a>
                </div>
            </div>

            <form class="toolbar-form" method="get">
                <input type="text" name="q" value="<?= h($query) ?>" placeholder="Search by context, file or artwork title">
                <button type="submit">Search</button>
                <?php if ($query !== ''): ?>
                    <a class="button-link secondary" href="mockups.php">Clear</a>
                <?php endif; ?>
            </form>

            <section class="panel">
                <div class="section-heading">
                    <h2>Mockup Album Archive</h2>
                    <p>Page <?= h($page) ?> of <?= h($totalPages) ?></p>
                </div>

                <?php if (!$mockups): ?>
                    <div class="empty-state">No mockups to display.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <a href="viewer.php?id=<?= h($mockup['id']) ?>&back=<?= rawurlencode(page_url($page, $query)) ?>" aria-label="Open mockup">
                                    <img src="<?= h(result_url($mockup['mockup_file'])) ?>" alt="Mockup">
                                </a>
                                <h3><?= h(Display::contextTitle($mockup['context_id'])) ?></h3>
                                <p class="meta-line"><?= h(date('m/d/Y H:i', strtotime((string)$mockup['created_at']))) ?></p>
                                <div class="card-actions">
                                    <a href="<?= h(download_url($mockup['mockup_file'])) ?>" aria-label="Download mockup" title="Download">
                                        <span class="download-icon" aria-hidden="true"></span>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a class="button-link secondary" href="<?= h(page_url(1, $query)) ?>">First</a>
                            <a class="button-link secondary" href="<?= h(page_url($page - 1, $query)) ?>">Previous</a>
                        <?php endif; ?>

                        <?php $visiblePages = pagination_pages($page, $totalPages); ?>
                        <?php $previousVisible = 0; ?>
                        <?php foreach ($visiblePages as $visiblePage): ?>
                            <?php if ($previousVisible > 0 && $visiblePage > $previousVisible + 1): ?>
                                <span>...</span>
                            <?php endif; ?>
                            <?php if ($visiblePage === $page): ?>
                                <span class="button-link" aria-current="page"><?= h($visiblePage) ?></span>
                            <?php else: ?>
                                <a class="button-link secondary" href="<?= h(page_url($visiblePage, $query)) ?>"><?= h($visiblePage) ?></a>
                            <?php endif; ?>
                            <?php $previousVisible = $visiblePage; ?>
                        <?php endforeach; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="button-link secondary" href="<?= h(page_url($page + 1, $query)) ?>">Next</a>
                            <a class="button-link secondary" href="<?= h(page_url($totalPages, $query)) ?>">Last</a>
                        <?php endif; ?>
                        <form method="get" style="display:inline-flex; gap:6px; align-items:center; margin:0;">
                            <?php if ($query !== ''): ?>
                                <input type="hidden" name="q" value="<?= h($query) ?>">
                            <?php endif; ?>
                            <label style="margin:0; font-size:11px; color:var(--muted);">Go to</label>
                            <select name="page" onchange="this.form.submit()" style="width:auto; min-width:72px; padding:8px 10px;">
                                <?php for ($jumpPage = 1; $jumpPage <= $totalPages; $jumpPage++): ?>
                                    <option value="<?= $jumpPage ?>" <?= $jumpPage === $page ? 'selected' : '' ?>><?= $jumpPage ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                    </nav>
                <?php endif; ?>
            </section>
        </div>
    </main>
</div>
</body>
</html>
