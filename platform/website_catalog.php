<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Website');
$pdo = Database::connection();
$userId = (int)$user['id'];
$service = new PublicationService($pdo);
Auth::start();
$_SESSION['website_catalog_csrf'] ??= bin2hex(random_bytes(32));
$notice = '';
$error = '';

function wc_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function wc_favorites(int $userId): array
{
    $path = __DIR__ . '/storage/mockup_favorites/user_' . $userId . '.json';
    $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
    return array_values(array_unique(array_filter(array_map('intval', is_array($decoded) ? $decoded : []))));
}
function wc_public_url(string $slug = ''): string
{
    $base = rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'http://localhost/artworkmockups/artist-site/artworks'), '/');
    return $slug === '' ? $base : $base . '/' . rawurlencode($slug);
}
function wc_result_url(string $file, int $width = 0): string
{
    $url = 'media.php?file=' . rawurlencode(basename($file));
    return $width > 0 ? $url . '&thumb=1&w=' . max(240, min(1200, $width)) : $url;
}
function wc_publication_for_sheet(PDO $pdo, int $sheetId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM publications WHERE artwork_sheet_id=? AND user_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sheetId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function wc_header_key(int $userId): string { return 'website_catalog_header_user_' . $userId; }
function wc_header_file(PDO $pdo, int $userId): string
{
    $column = Database::isMysql() ? '`key`' : 'key';
    $stmt = $pdo->prepare("SELECT value FROM app_settings WHERE {$column}=? LIMIT 1");
    $stmt->execute([wc_header_key($userId)]);
    return basename((string)($stmt->fetchColumn() ?: ''));
}
function wc_favorite_sheets(PDO $pdo, int $userId, int $artworkId, array $favoriteIds): array
{
    if (!$favoriteIds) return [];
    $marks = implode(',', array_fill(0, count($favoriteIds), '?'));
    $sql = "SELECT m.id mockup_id,ms.* FROM mockups m
        JOIN mockup_sheets ms ON ms.user_id=m.user_id AND ms.artwork_id=m.source_artwork_id AND ms.mockup_file=m.mockup_file
        WHERE m.user_id=? AND m.source_artwork_id=? AND m.id IN ($marks)
        ORDER BY FIELD(m.id,$marks)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId, $artworkId], $favoriteIds, $favoriteIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($row['title'] ?: 'mockup')) ?: (string)($row['title'] ?: 'mockup');
        $base = trim(strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $base)), '-');
        $row['public_slug'] = ($base ?: 'mockup') . '-' . (int)$row['id'];
    }
    unset($row);
    return $rows;
}
/** Catalog state derived from the publication row: draft (never published / pulled back), unlisted (published but hidden from the main catalog) or published (live and listed). */
function wc_state(?array $publication): string
{
    if (!$publication || $publication['status'] !== 'published') return 'draft';
    return $publication['visibility'] === 'unlisted' ? 'unlisted' : 'published';
}
function wc_state_label(string $state): string
{
    return match ($state) { 'published' => 'Published', 'unlisted' => 'Unlisted', default => 'Pending' };
}
function wc_state_pill_class(string $state): string
{
    return match ($state) { 'published' => 'status-published', 'unlisted' => 'status-scheduled', default => 'status-pending' };
}

/** Performs one catalog action against one artwork sheet. Shared by the single-item and bulk code paths. */
function wc_perform(PDO $pdo, PublicationService $service, int $userId, string $action, int $sheetId): array
{
    $stmt = $pdo->prepare('SELECT s.*,a.id artwork_id,a.root_file FROM artwork_sheets s JOIN artworks a ON a.id=s.canonical_artwork_id AND a.user_id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');
    $stmt->execute([$sheetId, $userId]);
    $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sheet) return ['ok' => false, 'message' => 'Artwork not found.'];
    $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
    $label = (string)($sheet['title'] ?: 'Untitled artwork');

    switch ($action) {
        case 'publish':
            $favoriteSheets = wc_favorite_sheets($pdo, $userId, (int)$sheet['artwork_id'], wc_favorites($userId));
            $missing = [];
            if (trim((string)$sheet['title']) === '') $missing[] = 'title';
            if (trim((string)($sheet['short_description'] ?: $sheet['description'])) === '') $missing[] = 'description';
            if (trim((string)$sheet['source_image_file']) === '') $missing[] = 'main image';
            if (!$favoriteSheets) $missing[] = 'favorite mockups';
            if ($missing) return ['ok' => false, 'message' => "Cannot publish \"$label\". Missing: " . implode(', ', $missing) . '.'];
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $service->save($publicationId, $userId, [
                'title' => $sheet['title'], 'description' => $sheet['description'], 'short_description' => $sheet['short_description'],
                'visibility' => 'public', 'publish' => true,
            ], array_map(fn(array $row): int => (int)$row['id'], $favoriteSheets));
            return ['ok' => true, 'message' => "\"$label\" published."];
        case 'unpublish':
            if (!$publication) return ['ok' => false, 'message' => "\"$label\" is not published."];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'private', 'unpublish' => true], null);
            return ['ok' => true, 'message' => "\"$label\" removed from the website."];
        case 'hide':
            if (!$publication || $publication['status'] !== 'published') return ['ok' => false, 'message' => "Publish \"$label\" before hiding it."];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'unlisted'], null);
            return ['ok' => true, 'message' => "\"$label\" hidden from the catalog (still reachable by direct link)."];
        case 'show':
            if (!$publication) return ['ok' => false, 'message' => "\"$label\" is not published."];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'public'], null);
            return ['ok' => true, 'message' => "\"$label\" visible again."];
        case 'delete':
            if (!$publication) return ['ok' => false, 'message' => "\"$label\" has nothing to remove."];
            $service->remove((int)$publication['id'], $userId);
            return ['ok' => true, 'message' => "\"$label\" removed from the website."];
        default:
            return ['ok' => false, 'message' => 'Unknown action.'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['website_catalog_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('The session expired. Reload and try again.');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'pin_header') {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT s.*,a.id artwork_id,a.root_file FROM artwork_sheets s JOIN artworks a ON a.id=s.canonical_artwork_id AND a.user_id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');
            $stmt->execute([$sheetId, $userId]);
            $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sheet) throw new RuntimeException('Artwork not found.');
            $file = basename((string)($_POST['file'] ?? ''));
            $allowed = [basename((string)$sheet['source_image_file'])];
            $views = $pdo->prepare('SELECT file_name FROM root_artwork_candidates WHERE artwork_id=? AND user_id=?');
            $views->execute([(int)$sheet['artwork_id'], $userId]);
            foreach ($views->fetchAll(PDO::FETCH_COLUMN) as $viewFile) $allowed[] = basename((string)$viewFile);
            foreach (wc_favorite_sheets($pdo, $userId, (int)$sheet['artwork_id'], wc_favorites($userId)) as $mockup) $allowed[] = basename((string)$mockup['mockup_file']);
            $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $stmt = $pdo->prepare('UPDATE publications SET header_file = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$file, date('c'), $publicationId, $userId]);
            $notice = 'Catalog header updated.';
        } elseif ($action === 'edit') {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT id FROM artwork_sheets WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$sheetId, $userId]);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Artwork not found.');
            $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $service->save($publicationId, $userId, [
                'short_description' => trim((string)($_POST['short_description'] ?? '')),
                'cta_label' => trim((string)($_POST['cta_label'] ?? '')),
                'cta_url' => trim((string)($_POST['cta_url'] ?? '')),
            ], null);
            $notice = 'Website copy updated.';
        } elseif (str_starts_with($action, 'bulk_')) {
            $subAction = substr($action, 5);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sheet_ids'] ?? [])))));
            if (!$ids) {
                $error = 'Select at least one artwork first.';
            } else {
                $okCount = 0;
                $failures = [];
                foreach ($ids as $sid) {
                    $result = wc_perform($pdo, $service, $userId, $subAction, $sid);
                    if ($result['ok']) $okCount++; else $failures[] = $result['message'];
                }
                if ($okCount) $notice = $okCount . ' of ' . count($ids) . ' artwork(s) updated.';
                if ($failures) $error = implode(' ', array_slice($failures, 0, 4));
            }
        } elseif (in_array($action, ['publish', 'unpublish', 'hide', 'show', 'delete'], true)) {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $result = wc_perform($pdo, $service, $userId, $action, $sheetId);
            if ($result['ok']) $notice = $result['message']; else $error = $result['message'];
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$favoriteIds = wc_favorites($userId);
$stmt = $pdo->prepare("SELECT s.*,a.id artwork_id,a.root_file,a.width,a.height,a.depth,a.unit,a.medium,a.artwork_year,a.series,
    (SELECT COUNT(*) FROM root_artwork_candidates r WHERE r.artwork_id=a.id AND r.user_id=a.user_id) additional_view_count
    FROM artwork_sheets s JOIN artworks a ON a.id=s.canonical_artwork_id AND a.user_id=s.user_id
    WHERE s.user_id=? ORDER BY s.updated_at DESC,s.id DESC");
$stmt->execute([$userId]);
$artworks = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($artworks as &$artwork) {
    $artwork['favorite_sheets'] = wc_favorite_sheets($pdo, $userId, (int)$artwork['artwork_id'], $favoriteIds);
    $artwork['publication'] = wc_publication_for_sheet($pdo, (int)$artwork['id'], $userId);
    $artwork['state'] = wc_state($artwork['publication']);
    $artwork['published'] = $artwork['state'] !== 'draft';
    $views = $pdo->prepare('SELECT file_name,view_type FROM root_artwork_candidates WHERE artwork_id=? AND user_id=? ORDER BY id');
    $views->execute([(int)$artwork['artwork_id'], $userId]);
    $artwork['views'] = $views->fetchAll(PDO::FETCH_ASSOC);
}
unset($artwork);

$stateCounts = ['published' => 0, 'unlisted' => 0, 'draft' => 0];
foreach ($artworks as $artwork) $stateCounts[$artwork['state']]++;
$activeFilter = in_array((string)($_GET['filter'] ?? ''), ['published', 'unlisted', 'draft'], true) ? (string)$_GET['filter'] : 'all';
$visibleArtworks = $activeFilter === 'all' ? $artworks : array_values(array_filter($artworks, fn(array $a): bool => $a['state'] === $activeFilter));

$selectedArtwork = null;
$selectedArtworkId = max(0, (int)($_GET['artwork'] ?? 0));
foreach ($artworks as $candidate) {
    if ((int)$candidate['artwork_id'] === $selectedArtworkId) { $selectedArtwork = $candidate; break; }
}
$headerFile = $selectedArtwork ? ($selectedArtwork['publication']['header_file'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website Catalog - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= wc_h($user['email']) ?></a></header>
        <div class="website-catalog">
            <div class="catalog-heading"><div><h1><?= $selectedArtwork ? wc_h($selectedArtwork['title'] ?: 'Untitled artwork') : 'Website Catalog' ?></h1><?php if(!$selectedArtwork): ?><p>Review and manage the artworks and selected Mockups reflected on your artist website.</p><?php endif; ?></div><?php if($selectedArtwork): ?><a class="button-link secondary" href="website_catalog.php">Back to catalog</a><?php endif; ?></div>
            <?php if ($notice): ?><div class="notice-card notice-ok"><?= wc_h($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice-card notice-error"><?= wc_h($error) ?></div><?php endif; ?>
            <?php if (!$artworks): ?><div class="empty-catalog"><h2>No artworks yet</h2><p>Your finished artworks will appear here.</p></div><?php endif; ?>
            <?php if (!$selectedArtwork && $artworks): ?>
            <div class="catalog-filters">
                <a class="<?= $activeFilter==='all'?'active':'' ?>" href="website_catalog.php">All <span class="catalog-filters__count"><?= count($artworks) ?></span></a>
                <a class="<?= $activeFilter==='published'?'active':'' ?>" href="website_catalog.php?filter=published">Published <span class="catalog-filters__count"><?= $stateCounts['published'] ?></span></a>
                <a class="<?= $activeFilter==='unlisted'?'active':'' ?>" href="website_catalog.php?filter=unlisted">Unlisted <span class="catalog-filters__count"><?= $stateCounts['unlisted'] ?></span></a>
                <a class="<?= $activeFilter==='draft'?'active':'' ?>" href="website_catalog.php?filter=draft">Pending <span class="catalog-filters__count"><?= $stateCounts['draft'] ?></span></a>
            </div>
            <form id="bulk-actions-form" method="post" class="bulk-toolbar">
                <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                <span class="bulk-toolbar__label">With selected:</span>
                <button class="button-link mini secondary" name="action" value="bulk_publish">Publish</button>
                <button class="button-link mini secondary" name="action" value="bulk_hide">Hide</button>
                <button class="button-link mini secondary" name="action" value="bulk_show">Show</button>
                <button class="button-link mini secondary" name="action" value="bulk_unpublish">Unpublish</button>
                <button class="button-link mini danger" name="action" value="bulk_delete" onclick="return confirm('Remove the selected artworks from the website catalog?');">Remove</button>
            </form>
            <section class="catalog-panel catalog-table-panel">
                <table class="catalog-table">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(c=>c.checked=this.checked)" aria-label="Select all"></th>
                            <th class="col-thumb"></th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="col-actions">Quick action</th>
                            <th class="col-manage"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visibleArtworks as $artwork): $state = $artwork['state']; ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" form="bulk-actions-form" name="sheet_ids[]" value="<?= (int)$artwork['id'] ?>" aria-label="Select artwork"></td>
                            <td class="col-thumb"><img src="<?= wc_h(wc_result_url((string)$artwork['source_image_file'], 240)) ?>" alt=""></td>
                            <td class="col-title">
                                <span class="catalog-table__title"><?= wc_h($artwork['title'] ?: 'Untitled artwork') ?></span>
                                <?php if(trim((string)$artwork['subtitle'])!==''): ?><span class="catalog-table__subtitle"><?= wc_h($artwork['subtitle']) ?></span><?php endif; ?>
                            </td>
                            <td><span class="status-pill <?= wc_state_pill_class($state) ?>"><?= wc_state_label($state) ?></span></td>
                            <td class="catalog-table__updated"><?= wc_h(date('M j, Y', strtotime((string)$artwork['updated_at']))) ?></td>
                            <td class="col-actions">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                                    <input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>">
                                    <?php if ($state === 'draft'): ?>
                                        <button class="button-link mini" name="action" value="publish">Publish</button>
                                    <?php elseif ($state === 'published'): ?>
                                        <button class="button-link mini secondary" name="action" value="hide">Hide</button>
                                    <?php else: ?>
                                        <button class="button-link mini secondary" name="action" value="show">Show</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td class="col-manage"><a href="website_catalog.php?artwork=<?= (int)$artwork['artwork_id'] ?>">Manage &rarr;</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$visibleArtworks): ?>
                        <tr><td colspan="7" class="catalog-table__empty">No artworks in this view.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
            <?php elseif ($selectedArtwork):
                $artwork=$selectedArtwork; $publication=$artwork['publication']; $state=$artwork['state']; $published=(bool)$artwork['published'];
                $missing=[]; if(trim((string)$artwork['title'])==='')$missing[]='Title'; if(trim((string)($artwork['short_description']?:$artwork['description']))==='')$missing[]='Description'; if(!$artwork['favorite_sheets'])$missing[]='Favorite mockups';
            ?>
                <section class="catalog-panel">
                    <div class="detail-heading"><h2>Website Mockups</h2><span class="status-pill <?= wc_state_pill_class($state) ?>"><?= wc_state_label($state) ?></span></div>
                    <?php if ($missing): ?><div class="warning-list">Complete before publishing: <?= wc_h(implode(' · ', $missing)) ?></div><?php endif; ?>
                    <div class="catalog-actions">
                        <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link primary" name="action" value="publish" <?= $missing?'disabled':'' ?>><?= $state==='draft'?'Publish':'Re-publish' ?></button></form>
                        <?php if ($state === 'published'): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="hide">Hide from catalog</button></form>
                        <?php elseif ($state === 'unlisted'): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="show">Show in catalog</button></form>
                        <?php endif; ?>
                        <?php if ($published): ?><form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="unpublish">Unpublish</button></form><?php endif; ?>
                        <?php if ($publication): ?><form method="post" onsubmit="return confirm('Remove this artwork from the website catalog entirely?');"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link danger" name="action" value="delete">Remove from website</button></form><?php endif; ?>
                    </div>
                    <h3>Website copy</h3>
                    <form method="post" class="catalog-edit-form">
                        <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                        <input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>">
                        <input type="hidden" name="action" value="edit">
                        <label>Short description<textarea name="short_description" rows="3"><?= wc_h($publication['short_description'] ?? $artwork['short_description']) ?></textarea></label>
                        <div class="catalog-edit-form__row">
                            <label>Call-to-action label<input type="text" name="cta_label" value="<?= wc_h($publication['cta_label'] ?? 'Inquire about this artwork') ?>"></label>
                            <label>Call-to-action URL<input type="text" name="cta_url" value="<?= wc_h($publication['cta_url'] ?? '') ?>"></label>
                        </div>
                        <button class="button-link secondary" type="submit">Save website copy</button>
                    </form>
                    <h3>Artwork Views</h3>
                    <div class="website-mockup-grid">
                        <?php foreach(array_merge([['file_name'=>$artwork['source_image_file'],'view_type'=>'Main view']],$artwork['views']) as $view): $viewFile=basename((string)$view['file_name']); ?>
                            <article class="website-mockup">
                                <div class="pin-image"><img src="<?= wc_h(wc_result_url($viewFile,900)) ?>" alt="<?= wc_h(str_replace('-',' ',(string)$view['view_type'])) ?>">
                                    <form class="header-pin-form" method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><input type="hidden" name="file" value="<?= wc_h($viewFile) ?>"><button class="header-pin <?= $headerFile===$viewFile?'is-active':'' ?>" name="action" value="pin_header" title="Set as catalog header" aria-label="Set as catalog header"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg></button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <h3 style="margin-top:30px">Website Mockups</h3>
                    <div class="website-mockup-grid">
                            <?php foreach($artwork['favorite_sheets'] as $mockup): ?>
                                <article class="website-mockup">
                                    <?php $mockupFile=basename((string)$mockup['mockup_file']); ?><div class="pin-image"><img src="<?= wc_h(wc_result_url($mockupFile, 900)) ?>" alt="<?= wc_h($mockup['alt_text'] ?: $mockup['title']) ?>">
                                        <form class="header-pin-form" method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><input type="hidden" name="file" value="<?= wc_h($mockupFile) ?>"><button class="header-pin <?= $headerFile===$mockupFile?'is-active':'' ?>" name="action" value="pin_header" title="Set as catalog header" aria-label="Set as catalog header"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg></button></form>
                                    </div>
                                    <h3><?= wc_h($mockup['title']) ?></h3>
                                    <p><?= wc_h($mockup['description']) ?></p>
                                    <details class="mockup-meta"><summary>Metadata</summary><dl>
                                        <dt>Slug</dt><dd><?= wc_h($mockup['public_slug']) ?></dd>
                                        <dt>Alt</dt><dd><?= wc_h($mockup['alt_text']) ?></dd>
                                        <dt>Tags</dt><dd><?= wc_h($mockup['tags']) ?></dd>
                                        <dt>Caption</dt><dd><?= wc_h($mockup['caption']) ?></dd>
                                    </dl></details>
                                </article>
                            <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
</body>
</html>
