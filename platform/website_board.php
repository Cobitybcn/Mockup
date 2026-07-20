<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::WEBSITE_MANAGE, 'Website Catalog Sync');
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

function wbb_website_destination(string $domain): ?array
{
    $domain = trim($domain);
    if ($domain === '') return null;

    $candidate = preg_match('#^https?://#i', $domain) ? $domain : 'https://' . ltrim($domain, '/');
    $host = strtolower(trim((string)(parse_url($candidate, PHP_URL_HOST) ?: ''), '.'));
    if ($host === '') return null;

    return ['url' => 'https://' . $host, 'label' => $host];
}

$artistProfile = ArtistProfile::findForUser($userId);
$artistWebsites = [];
$customWebsite = wbb_website_destination((string)($artistProfile['custom_domain'] ?? ''));
if ($customWebsite) $artistWebsites[$customWebsite['label']] = $customWebsite;

$artistSubdomain = strtolower(trim((string)($artistProfile['subdomain'] ?? '')));
if (preg_match('/^[a-z0-9-]+$/', $artistSubdomain)) {
    $subdomainWebsite = wbb_website_destination($artistSubdomain . '.artworkmockups.com');
    if ($subdomainWebsite) $artistWebsites[$subdomainWebsite['label']] = $subdomainWebsite;
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
    <title>Website Catalog Sync - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="social_media_board.css?v=15">
    <link rel="stylesheet" href="website_board.css?v=23">
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
                        <span class="smb-catalog-kicker">Image Catalog</span>
                        <h2 id="wbb-source-title">Website Catalog Sync</h2>
                        <label class="wbb-category-filter">
                            <span>Category</span>
                            <select data-source-type-select aria-label="Image category">
                                <option value="mockup"<?= $defaultSourceType === 'mockup' ? ' selected' : '' ?>>Mockups</option>
                                <option value="artwork"<?= $defaultSourceType === 'artwork' ? ' selected' : '' ?>>Artworks</option>
                                <option value="series">Series</option>
                            </select>
                        </label>
                    </div>
                    <div class="wbb-head-actions">
                        <a class="wbb-site-manager-link" href="../site-admin/">Open Site Manager</a>
                        <?php if ($artistWebsites): ?>
                            <div class="wbb-artist-websites" role="group" aria-label="Artist website">
                                <span>Artist website</span>
                                <div>
                                    <?php foreach ($artistWebsites as $website): ?>
                                        <a href="<?= wbb_h($website['url']) ?>" target="_blank" rel="noopener noreferrer"><?= wbb_h($website['label']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a class="wbb-artist-website-setup" href="artist_profile.php">Set artist website</a>
                        <?php endif; ?>
                        <button class="smb-focus-exit wbb-focus-exit" type="button" data-exit-board-focus>Overview</button>
                    </div>
                </div>
                <div class="smb-catalog-rail-wrap wbb-source-rail-wrap">
                    <button class="smb-rail-arrow smb-rail-arrow--left wbb-rail-arrow" type="button" data-scroll-source="-1" aria-label="Previous images">‹</button>
                    <div class="smb-catalog-rail wbb-source-rail" data-source-rail>
                        <?php foreach ($sources as $source): ?>
                            <?php $catalogEligible = ($source['type'] ?? '') === 'artwork' && empty($source['websitePublished']); ?>
                            <article class="smb-catalog-card wbb-source-card<?= $catalogEligible ? ' is-catalog-eligible' : '' ?>" data-source-card data-source-type="<?= wbb_h($source['type']) ?>" data-source-favorite="<?= !empty($source['favorite']) ? '1' : '0' ?>" data-source-published="<?= !empty($source['websitePublished']) ? '1' : '0' ?>" data-source-key="<?= wbb_h($source['key']) ?>" title="<?= wbb_h($source['label']) ?>">
                                <img src="<?= wbb_h($source['image']) ?>" alt="<?= wbb_h($source['label']) ?>" loading="lazy">
                                <?php if ($catalogEligible): ?><span class="wbb-source-state">Unpublished</span><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$sources): ?><div class="wbb-empty">No images available yet.</div><?php endif; ?>
                    </div>
                    <button class="smb-rail-arrow smb-rail-arrow--right wbb-rail-arrow" type="button" data-scroll-source="1" aria-label="More images">›</button>
                </div>
            </section>

            <section class="smb-boards wbb-boards<?= $showCatalogBoard ? '' : ' has-single-board' ?>" data-website-boards aria-label="Website boards">
                <?php if ($showCatalogBoard): ?>
                <article class="smb-board smb-board--pinterest wbb-board wbb-board--catalog" data-board="catalog">
                    <header class="smb-board-head wbb-board-head">
                        <button type="button" class="smb-board-title wbb-board-title" data-focus-board="catalog" aria-label="Work in Catalog">
                            <span class="wbb-board-mark" aria-hidden="true">C</span><h2>Catalog</h2>
                        </button>
                        <button type="button" class="wbb-publish-all" data-catalog-publish-all<?= !$catalog ? ' hidden' : '' ?>>Publish <?= count($catalog) ?> <?= count($catalog) === 1 ? 'artwork' : 'artworks' ?></button>
                    </header>
                    <p>Publish artwork pages in the website catalog.</p>
                    <div class="smb-pinterest-items wbb-catalog-list" data-catalog-list></div>
                </article>
                <?php endif; ?>

                <article class="smb-board smb-board--instagram wbb-board wbb-board--notes" data-board="notes">
                    <header class="smb-board-head wbb-board-head">
                        <button type="button" class="smb-board-title wbb-board-title" data-focus-board="notes" aria-label="Work in Studio Notes">
                            <span class="wbb-board-mark" aria-hidden="true">N</span><h2>Studio Notes</h2>
                        </button>
                        <span class="smb-board-count wbb-board-count" data-board-count="notes"><?= count($notes) ?> <?= count($notes) === 1 ? 'note' : 'notes' ?></span>
                    </header>
                    <p>Each note starts from a series, artwork, or mockup image.</p>
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
<script src="website_board.js?v=14"></script>
</body>
</html>
