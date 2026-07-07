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

$favoriteIds = MockupFavorites::idsForUser((int)$user['id']);
$favoriteLookup = array_fill_keys($favoriteIds, true);
$favoriteMockups = [];
if ($favoriteIds) {
    $favoriteParams = ['user_id' => (int)$user['id']];
    $favoritePlaceholders = [];
    foreach ($favoriteIds as $index => $favoriteId) {
        $key = 'favorite_id_' . $index;
        $favoritePlaceholders[] = ':' . $key;
        $favoriteParams[$key] = (int)$favoriteId;
    }

    $favoriteStmt = $pdo->prepare('
        SELECT *
        FROM mockups
        WHERE user_id = :user_id
        AND id IN (' . implode(',', $favoritePlaceholders) . ')
    ');
    foreach ($favoriteParams as $key => $value) {
        $favoriteStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
    }
    $favoriteStmt->execute();
    $favoriteRowsById = [];
    foreach ($favoriteStmt->fetchAll() as $favoriteRow) {
        $favoriteRowsById[(int)$favoriteRow['id']] = $favoriteRow;
    }
    foreach ($favoriteIds as $favoriteId) {
        if (isset($favoriteRowsById[$favoriteId])) {
            $favoriteMockups[] = $favoriteRowsById[$favoriteId];
        }
    }
}

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

function mockup_album_label(array $mockup): string
{
    $state = json_decode((string)($mockup['selector_state_json'] ?? ''), true);
    $combo = is_array($state) ? (array)($state['combination'] ?? []) : [];
    $label = trim((string)($combo['camera_slot_name'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    return Display::contextTitle((string)($mockup['context_id'] ?? ''));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Album - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .favorite-mockups-strip-panel {
            margin: 0 0 22px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .favorite-mockups-strip-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 10px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .favorite-mockups-strip-head::after {
            content: "";
            height: 1px;
            flex: 1;
            background: var(--line);
        }
        .favorite-mockups-count {
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 3px 8px;
            background: var(--surface-soft);
            color: var(--muted);
            font-size: 9px;
            white-space: nowrap;
        }
        .favorite-mockups-strip {
            display: grid;
            grid-auto-flow: column;
            grid-auto-columns: 164px;
            gap: 8px;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 1px 2px 10px;
            scrollbar-color: #d8cbbb transparent;
            scrollbar-width: thin;
        }
        .favorite-mockups-strip::-webkit-scrollbar {
            height: 6px;
        }
        .favorite-mockups-strip::-webkit-scrollbar-track {
            background: transparent;
        }
        .favorite-mockups-strip::-webkit-scrollbar-thumb {
            background: #d8cbbb;
            border-radius: 999px;
        }
        .favorite-mockup-card {
            position: relative;
            display: block;
            min-width: 0;
            padding: 7px;
            border: 1px solid var(--line);
            border-radius: 4px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
        }
        .favorite-mockup-card::before {
            content: "★";
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 1;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(183, 127, 134, .82);
            color: #fffaf7;
            font-size: 12px;
            box-shadow: 0 4px 12px rgba(20, 20, 18, .12);
        }
        .favorite-mockup-card img {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 4;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface);
        }
        .favorite-mockup-card strong {
            display: block;
            margin-top: 7px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 11px;
            line-height: 1.2;
        }
        .favorite-mockup-card small {
            display: block;
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.2;
        }
        .favorite-empty-strip {
            color: var(--muted);
            font-size: 12px;
            padding: 10px 2px 4px;
        }
        .mockup-album-header {
            background: rgba(183, 127, 134, 0.16);
            border: 1px solid rgba(183, 127, 134, 0.28);
            border-radius: var(--radius);
            padding: 24px 26px;
        }
        .mockup-image-wrap {
            position: relative;
        }
        .album-favorite-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 3;
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            min-height: 34px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .46);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(18, 17, 15, .18);
            color: rgba(255, 255, 255, .76);
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(20, 20, 18, .12);
            backdrop-filter: blur(7px);
            opacity: .48;
            transition: opacity .16s ease, background .16s ease, color .16s ease, border-color .16s ease;
        }
        .mockup-image-wrap:hover .album-favorite-btn,
        .album-favorite-btn:hover,
        .album-favorite-btn:focus-visible,
        .album-favorite-btn.active {
            background: rgba(183, 127, 134, .82);
            border-color: rgba(255, 255, 255, .7);
            color: #fffaf7;
            opacity: .96;
            outline: none;
        }
        .album-favorite-btn[disabled] {
            cursor: wait;
            opacity: .6;
        }
        .mockup-archive-panel .grid {
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 30px;
        }
        .mockup-archive-panel .item-card img {
            aspect-ratio: 4 / 3;
        }
        .mockup-archive-panel .item-card h3 {
            font-size: 18px;
            margin-top: 12px;
        }
        .mockup-archive-panel .meta-line {
            font-size: 11px;
        }
        @media (max-width: 760px) {
            .mockup-archive-panel .grid {
                grid-template-columns: 1fr;
            }
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

        <div class="alert-strip">
            Full archive of generated curatorial mockups.
        </div>

        <div class="workspace">
            <div class="workspace-header mockup-album-header">
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

            <section class="favorite-mockups-strip-panel" aria-label="Favorite mockups">
                <div class="favorite-mockups-strip-head">
                    <span>Favorite Mockups</span>
                    <span class="favorite-mockups-count"><?= count($favoriteMockups) ?> selected</span>
                </div>
                <?php if ($favoriteMockups): ?>
                    <div class="favorite-mockups-strip">
                        <?php foreach ($favoriteMockups as $favoriteMockup): ?>
                            <?php
                            $favoriteLabel = mockup_album_label($favoriteMockup);
                            $favoriteBackUrl = 'mockups.php' . ($query !== '' || $page > 1 ? '?' . http_build_query(array_filter(['page' => $page, 'q' => $query], static fn ($value) => $value !== '' && $value !== null)) : '');
                            ?>
                            <a class="favorite-mockup-card" href="viewer.php?id=<?= (int)$favoriteMockup['id'] ?>&back=<?= rawurlencode($favoriteBackUrl) ?>">
                                <img src="<?= h(result_url($favoriteMockup['mockup_file'])) ?>" alt="<?= h($favoriteLabel) ?>" loading="lazy">
                                <strong><?= h($favoriteLabel) ?></strong>
                                <small>#<?= (int)$favoriteMockup['id'] ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="favorite-empty-strip">Mark mockups as favorites from Generated Results, Variation Lab, or artwork detail pages to curate this strip.</div>
                <?php endif; ?>
            </section>

            <section class="panel mockup-archive-panel">
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
                                <div class="mockup-image-wrap">
                                    <a href="viewer.php?id=<?= h($mockup['id']) ?>&back=<?= rawurlencode(page_url($page, $query)) ?>" aria-label="Open mockup">
                                        <img src="<?= h(result_url($mockup['mockup_file'])) ?>" alt="Mockup">
                                    </a>
                                    <button
                                        class="album-favorite-btn <?= isset($favoriteLookup[(int)$mockup['id']]) ? 'active' : '' ?>"
                                        type="button"
                                        title="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                        aria-label="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                        data-favorite-mockup
                                        data-mockup-id="<?= (int)$mockup['id'] ?>"
                                    >★</button>
                                </div>
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
<script>
function parseAlbumJson(response) {
    return response.text().then(text => {
        let parsed;
        try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
        return parsed;
    });
}

document.addEventListener('click', event => {
    const button = event.target.closest('[data-favorite-mockup]');
    if (!button) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const formData = new FormData();
    formData.append('mockup_id', button.getAttribute('data-mockup-id') || '');
    button.disabled = true;

    fetch('toggle_mockup_favorite.php', { method: 'POST', body: formData })
        .then(parseAlbumJson)
        .then(result => {
            if (!result.ok) {
                throw new Error(result.error || 'Could not update favorite.');
            }
            button.classList.toggle('active', !!result.favorite);
            button.title = result.favorite ? 'Remove favorite' : 'Add favorite';
            button.setAttribute('aria-label', button.title);
        })
        .catch(err => {
            alert(err.message);
        })
        .finally(() => {
            button.disabled = false;
        });
});
</script>
</body>
</html>
