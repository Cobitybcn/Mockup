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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockups - The Artwork Curator</title>
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
                    <h1>Generated Mockups</h1>
                    <p><?= h($total) ?> images saved in your private archive.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="artwork_new.php">Upload Artwork</a>
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
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
                    <h2>Mockup Archive</h2>
                    <p>Page <?= h($page) ?> of <?= h($totalPages) ?></p>
                </div>

                <?php if (!$mockups): ?>
                    <div class="empty-state">No mockups to display.</div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <a href="viewer.php?id=<?= h($mockup['id']) ?>" aria-label="Open mockup">
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
