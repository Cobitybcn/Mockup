<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
ArtworkSeries::ensureSchema($pdo);
ArtworkSeries::syncUser($pdo, (int)$user['id']);
Auth::start();
$pinterestBatchError=(string)($_SESSION['pinterest_batch_error']??'');unset($_SESSION['pinterest_batch_error']);
$_SESSION['pinterest_batch_create_csrf']=bin2hex(random_bytes(24));

$query = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 60;
$offset = ($page - 1) * $perPage;

$where = 'WHERE m.user_id = :user_id';
$params = ['user_id' => (int)$user['id']];

if ($query !== '') {
    $where .= ' AND (m.context_id LIKE :query OR m.mockup_file LIKE :query OR m.artwork_file LIKE :query OR s.title LIKE :query)';
    $params['query'] = '%' . $query . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mockups m LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id {$where}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $pdo->prepare("
    SELECT m.*, s.title AS series_title
    FROM mockups m
    LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
    {$where}
    ORDER BY m.created_at DESC
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
        SELECT m.*, s.title AS series_title
        FROM mockups m
        LEFT JOIN artwork_series s ON s.id = m.series_id AND s.user_id = m.user_id
        WHERE m.user_id = :user_id
        AND m.id IN (' . implode(',', $favoritePlaceholders) . ')
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

function result_url(?string $file, int $thumbWidth = 0): string
{
    if (!$file) {
        return '';
    }
    $url = 'media.php?file=' . rawurlencode(basename($file));
    return $thumbWidth > 0 ? $url . '&thumb=1&w=' . max(240, min(1200, $thumbWidth)) : $url;
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
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
        .luxury-delete-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 4;
            width: 34px !important;
            height: 34px !important;
            min-width: 34px !important;
            min-height: 34px !important;
            margin: 0 !important;
            padding: 0 !important;
            border: 1px solid rgba(255, 255, 255, .52);
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(23, 21, 20, .28);
            color: rgba(255, 255, 255, .88);
            box-shadow: 0 7px 18px rgba(20, 18, 17, .16);
            backdrop-filter: blur(9px) saturate(115%);
            opacity: .68;
            cursor: pointer;
            transition: opacity .16s ease, background .16s ease, border-color .16s ease;
        }
        .luxury-delete-btn svg {
            width: 15px;
            height: 15px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.45;
            stroke-linecap: round;
            stroke-linejoin: round;
            pointer-events: none;
        }
        .luxury-delete-btn:hover,
        .luxury-delete-btn:focus-visible {
            background: rgba(145, 85, 93, .82);
            border-color: rgba(255, 255, 255, .76);
            opacity: 1;
            outline: none;
        }
        .luxury-delete-btn[disabled] {
            cursor: wait;
            opacity: .5;
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
        .mobile-album-slider {
            display: none;
        }
        .mobile-album-slide {
            color: var(--ink);
            text-decoration: none;
            position: relative;
        }
        .mobile-album-slide img {
            display: block;
            width: 100%;
            aspect-ratio: 3 / 4;
            object-fit: cover;
            border: 1px solid var(--line);
            border-radius: 5px;
            background: var(--surface-soft);
        }
        @media (max-width: 760px) {
            .app-header,
            .alert-strip {
                display: none;
            }
            .workspace {
                padding-left: 10px;
                padding-right: 10px;
            }
            .mockup-album-header {
                display: block;
                margin-bottom: 14px;
                padding: 0 0 14px;
                border: 0;
                border-bottom: 1px solid var(--line);
                border-radius: 0;
                background: transparent;
            }
            .mockup-album-header h1 {
                margin-bottom: 8px;
                font-size: 31px;
                line-height: 1.04;
            }
            .mockup-album-header p {
                margin: 0;
                font-size: 12px;
                line-height: 1.45;
            }
            .mockup-album-header .topbar-actions {
                display: none;
            }
            .toolbar-form {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                margin-bottom: 14px;
            }
            .toolbar-form input[type="text"] {
                min-width: 0;
                min-height: 46px;
                font-size: 14px;
            }
            .toolbar-form button,
            .toolbar-form .button-link {
                min-height: 46px;
                padding-left: 14px;
                padding-right: 14px;
                font-size: 11px;
            }
            .toolbar-form .button-link.secondary {
                grid-column: 1 / -1;
                width: 100%;
            }
            .mockup-archive-panel {
                margin-left: -10px;
                margin-right: -10px;
                padding: 12px 10px;
                border-left: 0;
                border-right: 0;
                border-radius: 0;
                box-shadow: none;
            }
            .favorite-mockups-strip-panel {
                margin: 0 -10px 14px;
                padding: 14px 10px 16px;
                border: 0;
                border-top: 1px solid rgba(183, 127, 134, 0.18);
                border-bottom: 1px solid rgba(183, 127, 134, 0.18);
                border-radius: 0;
                background:
                    linear-gradient(180deg, rgba(183, 127, 134, 0.13), rgba(183, 127, 134, 0.06)),
                    #fbf7f4;
                box-shadow: inset 0 1px 0 rgba(255, 250, 247, 0.82);
            }
            .favorite-mockups-strip-head {
                margin-bottom: 8px;
                padding: 0 2px;
            }
            .favorite-mockups-strip-head::after,
            .favorite-mockups-count {
                display: none;
            }
            .favorite-empty-strip {
                display: none;
            }
            .favorite-mockups-strip {
                grid-auto-columns: calc(100% - 18px);
                gap: 10px;
                padding: 0 2px 2px;
                scroll-snap-type: x mandatory;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            .favorite-mockups-strip::-webkit-scrollbar {
                display: none;
            }
            .favorite-mockup-card {
                scroll-snap-align: start;
                scroll-snap-stop: always;
                padding: 8px;
                border-radius: 6px;
                border-color: rgba(183, 127, 134, 0.28);
                background: rgba(255, 250, 247, 0.86);
            }
            .favorite-mockup-card img {
                aspect-ratio: 3 / 4;
                object-fit: cover;
            }
            .favorite-mockup-card strong,
            .favorite-mockup-card small {
                display: none;
            }
            .favorite-empty-strip {
                padding: 4px 2px 2px;
                font-size: 11px;
            }
            .mockup-archive-panel .section-heading {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 12px;
            }
            .mockup-archive-panel .section-heading h2 {
                margin: 0;
                font-size: 13px;
                letter-spacing: .08em;
                text-transform: uppercase;
            }
            .mockup-archive-panel .section-heading p {
                margin: 0;
                white-space: nowrap;
                font-size: 11px;
            }
            .mobile-album-slider {
                display: grid;
                grid-auto-flow: column;
                grid-auto-columns: calc(100% - 18px);
                gap: 10px;
                overflow-x: auto;
                overflow-y: hidden;
                scroll-snap-type: x mandatory;
                overscroll-behavior-x: contain;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 0 0 14px;
                margin-bottom: 12px;
                border-bottom: 1px solid var(--line);
            }
            .mobile-album-slider::-webkit-scrollbar {
                display: none;
            }
            .mobile-album-slide {
                scroll-snap-align: start;
                scroll-snap-stop: always;
                padding: 8px;
                border: 1px solid var(--line);
                border-radius: 6px;
                background: var(--surface-soft);
            }
            .mobile-album-slide:first-child {
                border-color: #b77f86;
                box-shadow: inset 0 0 0 2px #b77f86;
            }
            .mobile-album-slide img {
                border-radius: 4px;
            }
            .mockup-archive-panel .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
            .mockup-archive-panel .item-card {
                padding: 6px;
                border-radius: 6px;
            }
            .mockup-archive-panel .item-card img {
                aspect-ratio: 3 / 4;
                object-fit: cover;
                border-radius: 4px;
            }
            .mockup-archive-panel .item-card h3 {
                display: none;
            }
            .mockup-archive-panel .meta-line {
                display: none;
            }
            .card-actions {
                display: none;
            }
            .album-favorite-btn {
                top: 8px;
                left: 8px;
                width: 34px !important;
                height: 34px !important;
                min-width: 34px !important;
                min-height: 34px !important;
                opacity: .9;
            }
            .pagination {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                margin-top: 16px;
            }
            .pagination .button-link,
            .pagination form,
            .pagination select {
                width: 100%;
            }
            .pagination > span:not(.button-link) {
                display: none;
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
            <?php if($pinterestBatchError!==''):?><div class="notice error"><?=h($pinterestBatchError)?></div><?php endif;?>
            <style>.pinterest-batch-picker{display:none;margin-bottom:18px}.pinterest-batch-picker.is-active{display:block}.pinterest-batch-fields{display:flex;gap:12px;align-items:end;flex-wrap:wrap}.pinterest-batch-mobile-toggle{display:none}.pinterest-batch-destination{flex:1;min-width:300px}.pinterest-batch-destination strong,.pinterest-batch-destination small{display:block}.pinterest-batch-destination small{margin:5px 0 8px;color:#796457}.pinterest-thumb-select{position:absolute;z-index:4;left:12px;bottom:12px;width:38px;height:38px;border:0;border-radius:50%;display:grid;place-items:center;background:#bd081c!important;box-shadow:0 3px 12px rgba(0,0,0,.2);cursor:pointer;opacity:.78;transition:opacity .18s,transform .18s,box-shadow .18s,background .18s}.pinterest-thumb-select:hover{opacity:.94;transform:scale(1.05);background:#a50718!important}.pinterest-thumb-select input{position:absolute;opacity:0;pointer-events:none}.pinterest-thumb-select svg{display:block;width:19px;height:26px;fill:#fff}.pinterest-thumb-select:has(input:checked){opacity:1;transform:scale(1.06);background:#bd081c!important;box-shadow:0 0 0 3px rgba(189,8,28,.3),0 4px 14px rgba(0,0,0,.22)}@media(max-width:760px){.pinterest-batch-picker.is-active{position:sticky;bottom:8px;z-index:20;padding:10px;margin:0 0 14px;box-shadow:0 8px 28px rgba(0,0,0,.18)}.pinterest-batch-mobile-toggle{display:flex;width:100%;align-items:center;justify-content:space-between;border:0;background:transparent;padding:8px 10px;font-weight:700;color:#342b26}.pinterest-batch-mobile-toggle::after{content:'+';font-size:1.4rem}.pinterest-batch-picker.is-open .pinterest-batch-mobile-toggle::after{content:'−'}.pinterest-batch-fields{display:none;padding:12px 8px 6px}.pinterest-batch-picker.is-open .pinterest-batch-fields{display:flex}.pinterest-batch-destination{min-width:100%}.pinterest-batch-fields label:not(.pinterest-batch-destination),.pinterest-batch-fields button{width:100%}}</style>
            <form id="pinterest-batch-form" class="panel pinterest-batch-picker" method="post" action="pinterest_batch_create.php" aria-hidden="true">
                <input type="hidden" name="csrf" value="<?=h($_SESSION['pinterest_batch_create_csrf'])?>">
                <button class="pinterest-batch-mobile-toggle" type="button" aria-expanded="false">Pinterest batch <span data-batch-count-mobile>(0)</span></button>
                <div class="pinterest-batch-fields">
                <label class="pinterest-batch-destination"><strong>Destination link for this batch</strong><small>Every selected Pin in this batch will open this page.</small><input type="url" name="destination_url" value="<?=h(app_env('APP_PUBLIC_URL','https://artworkmockups.com'))?>" placeholder="https://example.com/landing-page" required></label>
                <?php if($isAdmin):?><label>Identity<select name="purpose"><option value="platform">Artwork Mockups</option><option value="artist">Artist</option></select></label><?php else:?><input type="hidden" name="purpose" value="artist"><?php endif;?>
                <button class="button-link primary" type="submit">Prepare selected mockups <span data-batch-count>(0)</span></button>
                <small style="width:100%">Select between 1 and 10 mockups below. Nothing is published during preparation.</small>
                </div>
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
                                <img src="<?= h(result_url($favoriteMockup['mockup_file'], 640)) ?>" alt="<?= h($favoriteLabel) ?>" loading="lazy" decoding="async">
                                <strong><?= h($favoriteLabel) ?><?php $favoriteSeriesTitle = ArtworkSeries::display((string)($favoriteMockup['series_title'] ?? '')); if ($favoriteSeriesTitle !== ''): ?> <span class="title-series-soft">(<?= h($favoriteSeriesTitle) ?>)</span><?php endif; ?></strong>
                                <small>#<?= (int)$favoriteMockup['id'] ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="favorite-empty-strip">No favorites yet.</div>
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
                    <div class="mobile-album-slider" aria-label="Featured mockups">
                        <?php foreach ($mockups as $mockup): ?>
                            <div class="mobile-album-slide">
                                <label class="pinterest-thumb-select" title="Add to Pinterest batch" aria-label="Add to Pinterest batch"><input form="pinterest-batch-form" type="checkbox" name="mockup_ids[]" value="<?=(int)$mockup['id']?>" data-pinterest-batch-item><svg viewBox="0 0 384 512" aria-hidden="true"><path d="M204 6.5C101.4 6.5 0 74.9 0 185.6 0 256 39.6 296 63.6 296c9.9 0 15.6-27.6 15.6-35.4 0-9.3-23.7-29.1-23.7-67.8 0-80.4 61.2-137.4 140.4-137.4 68.1 0 118.5 38.7 118.5 109.8 0 53.1-21.3 152.7-90.3 152.7-24.9 0-46.2-18-46.2-43.8 0-37.8 26.4-74.4 26.4-113.4 0-66.2-93.9-54.2-93.9 25.8 0 16.8 2.1 35.4 9.6 50.7-13.8 59.4-42 147.9-42 209.1 0 18.9 2.7 37.5 4.5 56.4 3.4 3.8 1.7 3.4 6.9 1.5 50.4-69 48.6-82.5 71.4-172.8 12.3 23.4 44.1 36 69.3 36 106.2 0 153.9-103.5 153.9-196.8C384 71.3 298.2 6.5 204 6.5z"/></svg></label>
                                <a href="viewer.php?id=<?= h($mockup['id']) ?>&back=<?= rawurlencode(page_url($page, $query)) ?>" aria-label="Open mockup">
                                    <img src="<?= h(result_url($mockup['mockup_file'], 640)) ?>" alt="" loading="lazy" decoding="async">
                                </a>
                                <button
                                    class="album-favorite-btn <?= isset($favoriteLookup[(int)$mockup['id']]) ? 'active' : '' ?>"
                                    type="button"
                                    title="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                    aria-label="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                    data-favorite-mockup
                                    data-mockup-id="<?= (int)$mockup['id'] ?>"
                                >★</button>
                                <button class="luxury-delete-btn" type="button" title="Delete mockup" aria-label="Delete mockup" data-delete-mockup data-mockup-id="<?= (int)$mockup['id'] ?>">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.5h7l-.55 9h-5.9l-.55-9Z"/><path d="M7.5 6.5h9M10 6.5V5h4v1.5M10.5 11v4.2M13.5 11v4.2"/></svg>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="grid">
                        <?php foreach ($mockups as $mockup): ?>
                            <article class="item-card">
                                <div class="mockup-image-wrap">
                                    <label class="pinterest-thumb-select" title="Add to Pinterest batch" aria-label="Add to Pinterest batch"><input form="pinterest-batch-form" type="checkbox" name="mockup_ids[]" value="<?=(int)$mockup['id']?>" data-pinterest-batch-item><svg viewBox="0 0 384 512" aria-hidden="true"><path d="M204 6.5C101.4 6.5 0 74.9 0 185.6 0 256 39.6 296 63.6 296c9.9 0 15.6-27.6 15.6-35.4 0-9.3-23.7-29.1-23.7-67.8 0-80.4 61.2-137.4 140.4-137.4 68.1 0 118.5 38.7 118.5 109.8 0 53.1-21.3 152.7-90.3 152.7-24.9 0-46.2-18-46.2-43.8 0-37.8 26.4-74.4 26.4-113.4 0-66.2-93.9-54.2-93.9 25.8 0 16.8 2.1 35.4 9.6 50.7-13.8 59.4-42 147.9-42 209.1 0 18.9 2.7 37.5 4.5 56.4 3.4 3.8 1.7 3.4 6.9 1.5 50.4-69 48.6-82.5 71.4-172.8 12.3 23.4 44.1 36 69.3 36 106.2 0 153.9-103.5 153.9-196.8C384 71.3 298.2 6.5 204 6.5z"/></svg></label>
                                    <a href="viewer.php?id=<?= h($mockup['id']) ?>&back=<?= rawurlencode(page_url($page, $query)) ?>" aria-label="Open mockup">
                                        <img src="<?= h(result_url($mockup['mockup_file'], 520)) ?>" alt="Mockup" loading="lazy" decoding="async">
                                    </a>
                                    <button
                                        class="album-favorite-btn <?= isset($favoriteLookup[(int)$mockup['id']]) ? 'active' : '' ?>"
                                        type="button"
                                        title="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                        aria-label="<?= isset($favoriteLookup[(int)$mockup['id']]) ? 'Remove favorite' : 'Add favorite' ?>"
                                        data-favorite-mockup
                                        data-mockup-id="<?= (int)$mockup['id'] ?>"
                                    >★</button>
                                    <button class="luxury-delete-btn" type="button" title="Delete mockup" aria-label="Delete mockup" data-delete-mockup data-mockup-id="<?= (int)$mockup['id'] ?>">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.5 8.5h7l-.55 9h-5.9l-.55-9Z"/><path d="M7.5 6.5h9M10 6.5V5h4v1.5M10.5 11v4.2M13.5 11v4.2"/></svg>
                                    </button>
                                </div>
                                <?php $mockupSeriesTitle = ArtworkSeries::display((string)($mockup['series_title'] ?? '')); ?>
                                <h3><?= h(Display::contextTitle($mockup['context_id'])) ?><?php if ($mockupSeriesTitle !== ''): ?> <span class="title-series-soft">(<?= h($mockupSeriesTitle) ?>)</span><?php endif; ?></h3>
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
    const deleteButton = event.target.closest('[data-delete-mockup]');
    if (deleteButton) {
        event.preventDefault();
        event.stopPropagation();
        if (!confirm('Delete this mockup permanently? This cannot be undone.')) {
            return;
        }
        const formData = new FormData();
        const mockupId = deleteButton.getAttribute('data-mockup-id') || '';
        formData.append('mockup_id', mockupId);
        document.querySelectorAll('[data-delete-mockup][data-mockup-id="' + CSS.escape(mockupId) + '"]').forEach(item => item.disabled = true);
        fetch('delete_mockup_result.php', { method: 'POST', body: formData })
            .then(parseAlbumJson)
            .then(result => {
                if (!result.ok) throw new Error(result.error || 'Could not delete mockup.');
                window.location.reload();
            })
            .catch(err => {
                alert(err.message);
                document.querySelectorAll('[data-delete-mockup][data-mockup-id="' + CSS.escape(mockupId) + '"]').forEach(item => item.disabled = false);
            });
        return;
    }

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
<script>
(()=>{
    const form=document.getElementById('pinterest-batch-form');
    const boxes=[...document.querySelectorAll('[data-pinterest-batch-item]')];
    const refresh=()=>{
        const checked=boxes.filter(box=>box.checked);
        document.querySelector('[data-batch-count]').textContent='('+checked.length+')';
        document.querySelector('[data-batch-count-mobile]').textContent='('+checked.length+')';
        form?.classList.toggle('is-active',checked.length>0);
        form?.setAttribute('aria-hidden',checked.length>0?'false':'true');
        if(checked.length===0){form?.classList.remove('is-open');form?.querySelector('.pinterest-batch-mobile-toggle')?.setAttribute('aria-expanded','false');}
    };
    boxes.forEach(box=>box.addEventListener('change',()=>{
        if(boxes.filter(item=>item.checked).length>10){box.checked=false;alert('Choose up to 10 mockups.');}
        refresh();
    }));
    form?.addEventListener('submit',e=>{if(boxes.filter(box=>box.checked).length<1){e.preventDefault();alert('Select at least one mockup.');}});
    form?.querySelector('.pinterest-batch-mobile-toggle')?.addEventListener('click',event=>{
        const open=form.classList.toggle('is-open');
        event.currentTarget.setAttribute('aria-expanded',open?'true':'false');
    });
    refresh();
})();
</script>
</body>
</html>
