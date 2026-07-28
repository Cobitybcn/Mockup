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
    return MockupFavorites::idsForUser($userId);
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
function wc_favorite_sheets(PDO $pdo, int $userId, int $artworkId, array $favoriteIds, string $artworkTitle = '', string $artworkSlug = ''): array
{
    if (!$favoriteIds) return [];
    $marks = implode(',', array_fill(0, count($favoriteIds), '?'));
    $sql = "SELECT m.id mockup_id,m.context_id,m.selector_state_json,ms.* FROM mockups m
        JOIN mockup_sheets ms ON ms.user_id=m.user_id AND ms.artwork_id=m.source_artwork_id AND ms.mockup_file=m.mockup_file
        WHERE m.user_id=? AND m.source_artwork_id=? AND m.id IN ($marks)
        ORDER BY FIELD(m.id,$marks)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$userId, $artworkId], $favoriteIds, $favoriteIds));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $artworkSlug = PublicSlug::universal($artworkTitle, $artworkSlug ?: 'obra-' . $artworkId);
    $usedEnglish = [];
    $usedSpanish = [];
    $bilingual = new BilingualEditorialService($pdo);
    foreach ($rows as &$row) {
        $spanish = [];
        $english = [];
        try {
            $spanish = (array)($bilingual->get($userId, 'mockup', (int)$row['mockup_id'], 'es')['content'] ?? []);
            $english = (array)($bilingual->get($userId, 'mockup', (int)$row['mockup_id'], 'en')['content'] ?? []);
        } catch (Throwable) {
        }
        $technicalContexts = PublicSlug::technicalMockupContexts(
            (string)($row['selector_state_json'] ?? ''),
            (string)($row['context_id'] ?? ''),
            (string)($row['mockup_file'] ?? '')
        );
        $fallbackTitle = trim((string)($row['title'] ?? ''));
        $row['public_slug_en'] = PublicSlug::uniqueMockup(
            PublicSlug::mockup(
                $artworkSlug,
                PublicSlug::mockupContext(
                    $artworkTitle,
                    $english,
                    $fallbackTitle !== '' ? $fallbackTitle : $technicalContexts['en']
                )
            ),
            $usedEnglish
        );
        $row['public_slug_es'] = PublicSlug::uniqueMockup(
            PublicSlug::mockup($artworkSlug, PublicSlug::mockupContext($artworkTitle, $spanish, $technicalContexts['es'])),
            $usedSpanish
        );
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
    return match ($state) { 'published' => t('Published', 'Publicado'), 'unlisted' => t('Unlisted', 'No listado'), default => t('Pending', 'Pendiente') };
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
    if (!$sheet) return ['ok' => false, 'message' => t('Artwork not found.', 'Obra no encontrada.')];
    $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
    $label = (string)($sheet['title'] ?: t('Untitled artwork', 'Obra sin título'));

    switch ($action) {
        case 'publish':
            $favoriteSheets = wc_favorite_sheets($pdo, $userId, (int)$sheet['artwork_id'], wc_favorites($userId), (string)$sheet['title']);
            $missing = [];
            if (trim((string)$sheet['title']) === '') $missing[] = t('title', 'título');
            if (trim((string)($sheet['short_description'] ?: $sheet['description'])) === '') $missing[] = t('description', 'descripción');
            if (trim((string)$sheet['source_image_file']) === '') $missing[] = t('main image', 'imagen principal');
            if (!$favoriteSheets) $missing[] = t('favorite mockups', 'mockups favoritos');
            if ($missing) return ['ok' => false, 'message' => t('Cannot publish "', 'No se puede publicar "') . $label . t('". Missing: ', '". Falta: ') . implode(', ', $missing) . '.'];
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $service->save($publicationId, $userId, [
                'title' => $sheet['title'], 'description' => $sheet['description'], 'short_description' => $sheet['short_description'],
                'visibility' => 'public', 'publish' => true,
            ], array_map(fn(array $row): int => (int)$row['id'], $favoriteSheets));
            return ['ok' => true, 'message' => '"' . $label . '" ' . t('published.', 'publicada.')];
        case 'unpublish':
            if (!$publication) return ['ok' => false, 'message' => '"' . $label . '" ' . t('is not published.', 'no está publicada.')];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'private', 'unpublish' => true], null);
            return ['ok' => true, 'message' => '"' . $label . '" ' . t('removed from the website.', 'eliminada del sitio web.')];
        case 'hide':
            if (!$publication || $publication['status'] !== 'published') return ['ok' => false, 'message' => t('Publish "', 'Publicá "') . $label . t('" before hiding it.', '" antes de ocultarla.')];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'unlisted'], null);
            return ['ok' => true, 'message' => '"' . $label . '" ' . t('hidden from the catalog (still reachable by direct link).', 'oculta del catálogo (sigue accesible por enlace directo).')];
        case 'show':
            if (!$publication) return ['ok' => false, 'message' => '"' . $label . '" ' . t('is not published.', 'no está publicada.')];
            $service->save((int)$publication['id'], $userId, ['visibility' => 'public'], null);
            return ['ok' => true, 'message' => '"' . $label . '" ' . t('visible again.', 'visible de nuevo.')];
        case 'delete':
            if (!$publication) return ['ok' => false, 'message' => '"' . $label . '" ' . t('has nothing to remove.', 'no tiene nada para eliminar.')];
            $service->remove((int)$publication['id'], $userId);
            return ['ok' => true, 'message' => '"' . $label . '" ' . t('removed from the website.', 'eliminada del sitio web.')];
        default:
            return ['ok' => false, 'message' => t('Unknown action.', 'Acción desconocida.')];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['website_catalog_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException(t('The session expired. Reload and try again.', 'La sesión expiró. Recargá e intentá de nuevo.'));
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'pin_header') {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT s.*,a.id artwork_id,a.root_file FROM artwork_sheets s JOIN artworks a ON a.id=s.canonical_artwork_id AND a.user_id=s.user_id WHERE s.id=? AND s.user_id=? LIMIT 1');
            $stmt->execute([$sheetId, $userId]);
            $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sheet) throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));
            $file = basename((string)($_POST['file'] ?? ''));
            $allowed = [basename((string)$sheet['source_image_file'])];
            $views = $pdo->prepare('SELECT file_name FROM root_artwork_candidates WHERE artwork_id=? AND user_id=?');
            $views->execute([(int)$sheet['artwork_id'], $userId]);
            foreach ($views->fetchAll(PDO::FETCH_COLUMN) as $viewFile) $allowed[] = basename((string)$viewFile);
            foreach (wc_favorite_sheets($pdo, $userId, (int)$sheet['artwork_id'], wc_favorites($userId), (string)$sheet['title']) as $mockup) $allowed[] = basename((string)$mockup['mockup_file']);
            $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $stmt = $pdo->prepare('UPDATE publications SET header_file = ?, updated_at = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$file, date('c'), $publicationId, $userId]);
            $notice = t('Catalog header updated.', 'Encabezado del catálogo actualizado.');
        } elseif ($action === 'edit') {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $stmt = $pdo->prepare('SELECT id FROM artwork_sheets WHERE id=? AND user_id=? LIMIT 1');
            $stmt->execute([$sheetId, $userId]);
            if (!$stmt->fetchColumn()) throw new RuntimeException(t('Artwork not found.', 'Obra no encontrada.'));
            $publication = wc_publication_for_sheet($pdo, $sheetId, $userId);
            $publicationId = $publication ? (int)$publication['id'] : $service->createForSheet($sheetId, $userId);
            $service->save($publicationId, $userId, [
                'short_description' => trim((string)($_POST['short_description'] ?? '')),
                'cta_label' => trim((string)($_POST['cta_label'] ?? '')),
                'cta_url' => trim((string)($_POST['cta_url'] ?? '')),
            ], null);
            $notice = t('Website copy updated.', 'Texto del sitio web actualizado.');
        } elseif (str_starts_with($action, 'bulk_')) {
            $subAction = substr($action, 5);
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['sheet_ids'] ?? [])))));
            if (!$ids) {
                $error = t('Select at least one artwork first.', 'Seleccioná al menos una obra primero.');
            } else {
                $okCount = 0;
                $failures = [];
                foreach ($ids as $sid) {
                    $result = wc_perform($pdo, $service, $userId, $subAction, $sid);
                    if ($result['ok']) $okCount++; else $failures[] = $result['message'];
                }
                if ($okCount) $notice = $okCount . ' ' . t('of', 'de') . ' ' . count($ids) . ' ' . t('artwork(s) updated.', 'obra(s) actualizada(s).');
                if ($failures) $error = implode(' ', array_slice($failures, 0, 4));
            }
        } elseif (in_array($action, ['publish', 'unpublish', 'hide', 'show', 'delete'], true)) {
            $sheetId = max(0, (int)($_POST['sheet_id'] ?? 0));
            $result = wc_perform($pdo, $service, $userId, $action, $sheetId);
            if ($result['ok']) $notice = $result['message']; else $error = $result['message'];
        } else {
            throw new RuntimeException(t('Unknown action.', 'Acción desconocida.'));
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
    $artwork['publication'] = wc_publication_for_sheet($pdo, (int)$artwork['id'], $userId);
    $artwork['favorite_sheets'] = wc_favorite_sheets(
        $pdo,
        $userId,
        (int)$artwork['artwork_id'],
        $favoriteIds,
        (string)$artwork['title'],
        (string)($artwork['publication']['slug'] ?? '')
    );
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
<html lang="<?= wc_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= wc_h(t('Website Catalog - Artwork Mockups', 'Catálogo del sitio web - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= wc_h($user['email']) ?></a></header>
        <div class="website-catalog">
            <div class="catalog-heading"><div><h1><?= $selectedArtwork ? wc_h($selectedArtwork['title'] ?: t('Untitled artwork', 'Obra sin título')) : wc_h(t('Website Catalog', 'Catálogo del sitio web')) ?></h1><?php if(!$selectedArtwork): ?><p><?= wc_h(t('Review and manage the artworks and selected Mockups reflected on your artist website.', 'Revisá y gestioná las obras y los mockups seleccionados que se reflejan en tu sitio web de artista.')) ?></p><?php endif; ?></div><?php if($selectedArtwork): ?><a class="button-link secondary" href="website_catalog.php"><?= wc_h(t('Back to catalog', 'Volver al catálogo')) ?></a><?php endif; ?></div>
            <?php if ($notice): ?><div class="notice-card notice-ok"><?= wc_h($notice) ?></div><?php endif; ?>
            <?php if ($error): ?><div class="notice-card notice-error"><?= wc_h($error) ?></div><?php endif; ?>
            <?php if (!$artworks): ?><div class="empty-catalog"><h2><?= wc_h(t('No artworks yet', 'Todavía no hay obras')) ?></h2><p><?= wc_h(t('Your finished artworks will appear here.', 'Tus obras terminadas van a aparecer acá.')) ?></p></div><?php endif; ?>
            <?php if (!$selectedArtwork && $artworks): ?>
            <div class="catalog-filters">
                <a class="<?= $activeFilter==='all'?'active':'' ?>" href="website_catalog.php"><?= wc_h(t('All', 'Todas')) ?> <span class="catalog-filters__count"><?= count($artworks) ?></span></a>
                <a class="<?= $activeFilter==='published'?'active':'' ?>" href="website_catalog.php?filter=published"><?= wc_h(t('Published', 'Publicadas')) ?> <span class="catalog-filters__count"><?= $stateCounts['published'] ?></span></a>
                <a class="<?= $activeFilter==='unlisted'?'active':'' ?>" href="website_catalog.php?filter=unlisted"><?= wc_h(t('Unlisted', 'No listadas')) ?> <span class="catalog-filters__count"><?= $stateCounts['unlisted'] ?></span></a>
                <a class="<?= $activeFilter==='draft'?'active':'' ?>" href="website_catalog.php?filter=draft"><?= wc_h(t('Pending', 'Pendientes')) ?> <span class="catalog-filters__count"><?= $stateCounts['draft'] ?></span></a>
            </div>
            <form id="bulk-actions-form" method="post" class="bulk-toolbar">
                <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                <span class="bulk-toolbar__label"><?= wc_h(t('With selected:', 'Con las seleccionadas:')) ?></span>
                <button class="button-link mini secondary" name="action" value="bulk_publish"><?= wc_h(t('Publish', 'Publicar')) ?></button>
                <button class="button-link mini secondary" name="action" value="bulk_hide"><?= wc_h(t('Hide', 'Ocultar')) ?></button>
                <button class="button-link mini secondary" name="action" value="bulk_show"><?= wc_h(t('Show', 'Mostrar')) ?></button>
                <button class="button-link mini secondary" name="action" value="bulk_unpublish"><?= wc_h(t('Unpublish', 'Despublicar')) ?></button>
                <button class="button-link mini danger" name="action" value="bulk_delete" onclick="return confirm(<?= json_encode(t('Remove the selected artworks from the website catalog?', '¿Eliminar las obras seleccionadas del catálogo del sitio web?')) ?>);"><?= wc_h(t('Remove', 'Eliminar')) ?></button>
            </form>
            <section class="catalog-panel catalog-table-panel">
                <table class="catalog-table">
                    <thead>
                        <tr>
                            <th class="col-check"><input type="checkbox" onclick="this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(c=>c.checked=this.checked)" aria-label="<?= wc_h(t('Select all', 'Seleccionar todo')) ?>"></th>
                            <th class="col-thumb"></th>
                            <th><?= wc_h(t('Title', 'Título')) ?></th>
                            <th><?= wc_h(t('Status', 'Estado')) ?></th>
                            <th><?= wc_h(t('Updated', 'Actualizado')) ?></th>
                            <th class="col-actions"><?= wc_h(t('Quick action', 'Acción rápida')) ?></th>
                            <th class="col-manage"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($visibleArtworks as $artwork): $state = $artwork['state']; ?>
                        <tr>
                            <td class="col-check"><input type="checkbox" form="bulk-actions-form" name="sheet_ids[]" value="<?= (int)$artwork['id'] ?>" aria-label="<?= wc_h(t('Select artwork', 'Seleccionar obra')) ?>"></td>
                            <td class="col-thumb"><img src="<?= wc_h(wc_result_url((string)$artwork['source_image_file'], 240)) ?>" alt=""></td>
                            <td class="col-title">
                                <span class="catalog-table__title"><?= wc_h($artwork['title'] ?: t('Untitled artwork', 'Obra sin título')) ?></span>
                                <?php if(trim((string)$artwork['subtitle'])!==''): ?><span class="catalog-table__subtitle"><?= wc_h($artwork['subtitle']) ?></span><?php endif; ?>
                            </td>
                            <td><span class="status-pill <?= wc_state_pill_class($state) ?>"><?= wc_state_label($state) ?></span></td>
                            <td class="catalog-table__updated"><?= wc_h(date('M j, Y', strtotime((string)$artwork['updated_at']))) ?></td>
                            <td class="col-actions">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                                    <input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>">
                                    <?php if ($state === 'draft'): ?>
                                        <button class="button-link mini" name="action" value="publish"><?= wc_h(t('Publish', 'Publicar')) ?></button>
                                    <?php elseif ($state === 'published'): ?>
                                        <button class="button-link mini secondary" name="action" value="hide"><?= wc_h(t('Hide', 'Ocultar')) ?></button>
                                    <?php else: ?>
                                        <button class="button-link mini secondary" name="action" value="show"><?= wc_h(t('Show', 'Mostrar')) ?></button>
                                    <?php endif; ?>
                                </form>
                            </td>
                            <td class="col-manage"><a href="website_catalog.php?artwork=<?= (int)$artwork['artwork_id'] ?>"><?= wc_h(t('Manage', 'Gestionar')) ?> &rarr;</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$visibleArtworks): ?>
                        <tr><td colspan="7" class="catalog-table__empty"><?= wc_h(t('No artworks in this view.', 'No hay obras en esta vista.')) ?></td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </section>
            <?php elseif ($selectedArtwork):
                $artwork=$selectedArtwork; $publication=$artwork['publication']; $state=$artwork['state']; $published=(bool)$artwork['published'];
                $missing=[]; if(trim((string)$artwork['title'])==='')$missing[]=t('Title', 'Título'); if(trim((string)($artwork['short_description']?:$artwork['description']))==='')$missing[]=t('Description', 'Descripción'); if(!$artwork['favorite_sheets'])$missing[]=t('Favorite mockups', 'Mockups favoritos');
            ?>
                <section class="catalog-panel">
                    <div class="detail-heading"><h2><?= wc_h(t('Website Mockups', 'Mockups del sitio web')) ?></h2><span class="status-pill <?= wc_state_pill_class($state) ?>"><?= wc_state_label($state) ?></span></div>
                    <?php if ($missing): ?><div class="warning-list"><?= wc_h(t('Complete before publishing:', 'Completá antes de publicar:')) ?> <?= wc_h(implode(' · ', $missing)) ?></div><?php endif; ?>
                    <div class="catalog-actions">
                        <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link primary" name="action" value="publish" <?= $missing?'disabled':'' ?>><?= $state==='draft'?wc_h(t('Publish', 'Publicar')):wc_h(t('Re-publish', 'Volver a publicar')) ?></button></form>
                        <?php if ($state === 'published'): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="hide"><?= wc_h(t('Hide from catalog', 'Ocultar del catálogo')) ?></button></form>
                        <?php elseif ($state === 'unlisted'): ?>
                            <form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="show"><?= wc_h(t('Show in catalog', 'Mostrar en el catálogo')) ?></button></form>
                        <?php endif; ?>
                        <?php if ($published): ?><form method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link secondary" name="action" value="unpublish"><?= wc_h(t('Unpublish', 'Despublicar')) ?></button></form><?php endif; ?>
                        <?php if ($publication): ?><form method="post" onsubmit="return confirm(<?= json_encode(t('Remove this artwork from the website catalog entirely?', '¿Eliminar completamente esta obra del catálogo del sitio web?')) ?>);"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><button class="button-link danger" name="action" value="delete"><?= wc_h(t('Remove from website', 'Eliminar del sitio web')) ?></button></form><?php endif; ?>
                    </div>
                    <h3><?= wc_h(t('Website copy', 'Texto del sitio web')) ?></h3>
                    <form method="post" class="catalog-edit-form">
                        <input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>">
                        <input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>">
                        <input type="hidden" name="action" value="edit">
                        <label><?= wc_h(t('Short description', 'Descripción breve')) ?><textarea name="short_description" rows="3"><?= wc_h($publication['short_description'] ?? $artwork['short_description']) ?></textarea></label>
                        <div class="catalog-edit-form__row">
                            <label><?= wc_h(t('Call-to-action label', 'Texto del llamado a la acción')) ?><input type="text" name="cta_label" value="<?= wc_h($publication['cta_label'] ?? t('Inquire about this artwork', 'Consultar sobre esta obra')) ?>"></label>
                            <label><?= wc_h(t('Call-to-action URL', 'URL del llamado a la acción')) ?><input type="text" name="cta_url" value="<?= wc_h($publication['cta_url'] ?? '') ?>"></label>
                        </div>
                        <button class="button-link secondary" type="submit"><?= wc_h(t('Save website copy', 'Guardar texto del sitio web')) ?></button>
                    </form>
                    <h3><?= wc_h(t('Artwork Views', 'Vistas de la obra')) ?></h3>
                    <div class="website-mockup-grid">
                        <?php foreach(array_merge([['file_name'=>$artwork['source_image_file'],'view_type'=>t('Main view', 'Vista principal')]],$artwork['views']) as $view): $viewFile=basename((string)$view['file_name']); ?>
                            <article class="website-mockup">
                                <div class="pin-image"><img src="<?= wc_h(wc_result_url($viewFile,900)) ?>" alt="<?= wc_h(str_replace('-',' ',(string)$view['view_type'])) ?>">
                                    <form class="header-pin-form" method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><input type="hidden" name="file" value="<?= wc_h($viewFile) ?>"><button class="header-pin <?= $headerFile===$viewFile?'is-active':'' ?>" name="action" value="pin_header" title="<?= wc_h(t('Set as catalog header', 'Usar como encabezado del catálogo')) ?>" aria-label="<?= wc_h(t('Set as catalog header', 'Usar como encabezado del catálogo')) ?>"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg></button></form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <h3 style="margin-top:30px"><?= wc_h(t('Website Mockups', 'Mockups del sitio web')) ?></h3>
                    <div class="website-mockup-grid">
                            <?php foreach($artwork['favorite_sheets'] as $mockup): ?>
                                <article class="website-mockup">
                                    <?php $mockupFile=basename((string)$mockup['mockup_file']); ?><div class="pin-image"><img src="<?= wc_h(wc_result_url($mockupFile, 900)) ?>" alt="<?= wc_h($mockup['alt_text'] ?: $mockup['title']) ?>">
                                        <form class="header-pin-form" method="post"><input type="hidden" name="csrf" value="<?= wc_h($_SESSION['website_catalog_csrf']) ?>"><input type="hidden" name="sheet_id" value="<?= (int)$artwork['id'] ?>"><input type="hidden" name="file" value="<?= wc_h($mockupFile) ?>"><button class="header-pin <?= $headerFile===$mockupFile?'is-active':'' ?>" name="action" value="pin_header" title="<?= wc_h(t('Set as catalog header', 'Usar como encabezado del catálogo')) ?>" aria-label="<?= wc_h(t('Set as catalog header', 'Usar como encabezado del catálogo')) ?>"><svg viewBox="0 0 64 64" aria-hidden="true"><path d="M32 4 37.2 27.6 60 32 37.2 36.4 32 60 26.8 36.4 4 32 26.8 27.6 32 4Z" opacity=".72"/><path d="M32 14 35 28.9 50 32 35 35.1 32 50 29 35.1 14 32 29 28.9 32 14Z" opacity=".46"/><path d="M43.8 20.2 37.5 30.1 54.2 13.8 37.9 30.5 47.8 24.2Z" opacity=".5"/><path d="M20.2 43.8 26.5 33.9 9.8 50.2 26.1 33.5 16.2 39.8Z" opacity=".5"/></svg></button></form>
                                    </div>
                                    <h3><?= wc_h($mockup['title']) ?></h3>
                                    <p><?= wc_h($mockup['description']) ?></p>
                                    <details class="mockup-meta"><summary><?= wc_h(t('Metadata', 'Metadatos')) ?></summary><dl>
                                        <dt><?= wc_h(t('Slug EN', 'Slug EN')) ?></dt><dd><?= wc_h($mockup['public_slug_en']) ?></dd>
                                        <dt><?= wc_h(t('Slug ES', 'Slug ES')) ?></dt><dd><?= wc_h($mockup['public_slug_es']) ?></dd>
                                        <dt><?= wc_h(t('Alt', 'Alt')) ?></dt><dd><?= wc_h($mockup['alt_text']) ?></dd>
                                        <dt><?= wc_h(t('Tags', 'Etiquetas')) ?></dt><dd><?= wc_h($mockup['tags']) ?></dd>
                                        <dt><?= wc_h(t('Caption', 'Pie de imagen')) ?></dt><dd><?= wc_h($mockup['caption']) ?></dd>
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
