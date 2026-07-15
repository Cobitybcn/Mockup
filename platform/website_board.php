<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
Auth::start();
$_SESSION['website_board_csrf'] ??= bin2hex(random_bytes(32));

$service = new WebsiteBoardService(Database::connection());
$sources = $service->sources($userId);
$catalog = $service->catalogEntries($userId);
$notes = $service->notes($userId);
$eligibleCatalogSources = array_values(array_filter($sources, static fn(array $source): bool => ($source['type'] ?? '') === 'artwork' && empty($source['websitePublished'])));
$showCatalogBoard = (bool)$catalog || (bool)$eligibleCatalogSources;
$defaultSourceType = $showCatalogBoard ? 'artwork' : 'mockup';

function wbb_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wbb_media_url(string $file, int $width = 900): string
{
    return 'media.php?file=' . rawurlencode(basename($file)) . '&thumb=1&w=' . max(320, min(1200, $width));
}

foreach ($sources as &$source) $source['image'] = wbb_media_url((string)$source['file']);
unset($source);
foreach ($catalog as &$entry) {
    foreach ($entry['media'] as &$media) $media['image'] = wbb_media_url((string)$media['file']);
    unset($media);
}
unset($entry);
foreach ($notes as &$note) {
    foreach ($note['media'] as &$media) $media['image'] = wbb_media_url((string)$media['file']);
    unset($media);
    if (is_array($note['source'] ?? null)) $note['source']['image'] = wbb_media_url((string)$note['source']['file']);
}
unset($note);

$payload = [
    'sources' => $sources,
    'catalog' => $catalog,
    'notes' => $notes,
    'config' => [
        'csrf' => (string)$_SESSION['website_board_csrf'],
        'initialFocus' => in_array((string)($_GET['focus'] ?? ''), ['catalog', 'notes'], true) ? (string)$_GET['focus'] : '',
        'defaultSourceType' => $defaultSourceType,
        'showCatalogBoard' => $showCatalogBoard,
    ],
];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Website Publisher - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="social_media_board.css?v=10">
    <link rel="stylesheet" href="website_board.css?v=15">
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
</head>
<body data-website-board-user="<?= $userId ?>">
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= wbb_h($user['email']) ?></a></header>
        <div class="smb-page wbb-page" data-website-board>
            <section class="smb-catalog wbb-source-catalog" aria-labelledby="wbb-source-title">
                <div class="smb-catalog-head wbb-source-head">
                    <div>
                        <h2 id="wbb-source-title">Catálogo de imágenes</h2>
                        <label class="wbb-category-filter">
                            <span>Categoría</span>
                            <select data-source-type-select aria-label="Categoría de imagen">
                                <option value="mockup"<?= $defaultSourceType === 'mockup' ? ' selected' : '' ?>>Mockups</option>
                                <option value="artwork"<?= $defaultSourceType === 'artwork' ? ' selected' : '' ?>>Obras</option>
                                <option value="series">Series</option>
                            </select>
                        </label>
                    </div>
                    <button class="smb-focus-exit wbb-focus-exit" type="button" data-exit-board-focus>Vista<br>general</button>
                </div>
                <div class="smb-catalog-rail-wrap wbb-source-rail-wrap">
                    <button class="smb-rail-arrow smb-rail-arrow--left wbb-rail-arrow" type="button" data-scroll-source="-1" aria-label="Ver imágenes anteriores">‹</button>
                    <div class="smb-catalog-rail wbb-source-rail" data-source-rail>
                        <?php foreach ($sources as $source): ?>
                            <?php $catalogEligible = ($source['type'] ?? '') === 'artwork' && empty($source['websitePublished']); ?>
                            <article class="smb-catalog-card wbb-source-card<?= $catalogEligible ? ' is-catalog-eligible' : '' ?>" data-source-card data-source-type="<?= wbb_h($source['type']) ?>" data-source-favorite="<?= !empty($source['favorite']) ? '1' : '0' ?>" data-source-published="<?= !empty($source['websitePublished']) ? '1' : '0' ?>" data-source-key="<?= wbb_h($source['key']) ?>" title="<?= wbb_h($source['label']) ?>">
                                <img src="<?= wbb_h($source['image']) ?>" alt="<?= wbb_h($source['label']) ?>" loading="lazy">
                                <?php if ($catalogEligible): ?><span class="wbb-source-state">Sin publicar</span><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$sources): ?><div class="wbb-empty">Todavía no hay imágenes disponibles.</div><?php endif; ?>
                    </div>
                    <button class="smb-rail-arrow smb-rail-arrow--right wbb-rail-arrow" type="button" data-scroll-source="1" aria-label="Ver más imágenes">›</button>
                </div>
            </section>

            <section class="smb-boards wbb-boards<?= $showCatalogBoard ? '' : ' has-single-board' ?>" data-website-boards aria-label="Tableros del website">
                <?php if ($showCatalogBoard): ?>
                <article class="smb-board smb-board--pinterest wbb-board wbb-board--catalog" data-board="catalog">
                    <header class="smb-board-head wbb-board-head">
                        <button type="button" class="smb-board-title wbb-board-title" data-focus-board="catalog" aria-label="Trabajar individualmente en Catálogo">
                            <span class="wbb-board-mark" aria-hidden="true">C</span><h2>Catálogo</h2>
                        </button>
                        <span class="smb-board-count wbb-board-count" data-board-count="catalog"><?= count($catalog) ?> obras</span>
                    </header>
                    <p>Publica fichas de obras en el catálogo del website.</p>
                    <div class="smb-pinterest-items wbb-catalog-list" data-catalog-list></div>
                </article>
                <?php endif; ?>

                <article class="smb-board smb-board--instagram wbb-board wbb-board--notes" data-board="notes">
                    <header class="smb-board-head wbb-board-head">
                        <button type="button" class="smb-board-title wbb-board-title" data-focus-board="notes" aria-label="Trabajar individualmente en Notas de estudio">
                            <span class="wbb-board-mark" aria-hidden="true">N</span><h2>Notas de estudio</h2>
                        </button>
                        <span class="smb-board-count wbb-board-count" data-board-count="notes"><?= count($notes) ?> notas</span>
                    </header>
                    <p>Cada nota comienza desde una imagen de serie, obra o mockup.</p>
                    <div class="smb-publication-stack wbb-notes-list" data-notes-list></div>
                </article>
            </section>
            <div class="wbb-toast" data-website-toast role="status" aria-live="polite"></div>
        </div>
    </main>
</div>
<script type="application/json" id="website-board-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script src="website_board.js?v=11"></script>
</body>
</html>
