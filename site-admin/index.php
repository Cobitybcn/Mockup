<?php
declare(strict_types=1);

$localPlatformDirectory = __DIR__ . '/../platform';
$platformDirectory = is_file($localPlatformDirectory . '/app/bootstrap.php')
    ? $localPlatformDirectory
    : dirname(__DIR__);
define('SITE_MANAGER_PLATFORM_PREFIX', $platformDirectory === $localPlatformDirectory ? '../platform' : '..');
require_once $platformDirectory . '/app/bootstrap.php';
require_once __DIR__ . '/app/SiteManagerService.php';
require_once __DIR__ . '/app/EmbeddedNoteImage.php';

$user = Auth::user();
if (!$user) {
    header('Location: ' . SITE_MANAGER_PLATFORM_PREFIX . '/login.php');
    exit;
}
if (!FeatureAccess::allows($user, FeatureAccess::WEBSITE_MANAGE)) {
    header('Location: ' . SITE_MANAGER_PLATFORM_PREFIX . '/account.php?upgrade=artist_pro&feature=' . rawurlencode(FeatureAccess::WEBSITE_MANAGE));
    exit;
}

Auth::start();
$_SESSION['site_manager_csrf'] ??= bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['site_manager_csrf'];
$userId = (int)$user['id'];
$pdo = Database::connection();
$manager = new SiteManagerService($pdo);
$domainService = new ArtistDomainService($pdo);

function sm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function sm_series_title(string $title): string
{
    $title = trim($title);
    $title = trim((string)preg_replace('/\s+series\s*$/iu', '', $title));
    if ($title === '') return 'Series';
    if (function_exists('mb_strtolower')) {
        $title = mb_strtolower($title, 'UTF-8');
        $title = mb_strtoupper(mb_substr($title, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($title, 1, null, 'UTF-8');
    } else {
        $title = ucfirst(strtolower($title));
    }
    return $title . ' Series';
}

function sm_media(string $file, int $width = 720): string
{
    $file = basename($file);
    return $file === '' ? '' : SITE_MANAGER_PLATFORM_PREFIX . '/media.php?file=' . rawurlencode($file) . '&thumb=1&w=' . max(320, min(1200, $width));
}

function sm_money(int $minor, string $currency): string
{
    return number_format($minor / 100, 2, '.', ',') . ' ' . strtoupper($currency);
}

function sm_redirect(string $area, string $section, int $item = 0, int $variant = 0, int $order = 0): never
{
    $query = ['area' => $area, 'section' => $section];
    if ($item > 0) $query['item'] = $item;
    if ($variant > 0) $query['variant'] = $variant;
    if ($order > 0) $query['order'] = $order;
    header('Location: index.php?' . http_build_query($query));
    exit;
}

$sections = [
    'store' => ['orders', 'shipping', 'payments'],
];
$area = (string)($_GET['area'] ?? $_POST['return_area'] ?? 'store');
if (!isset($sections[$area])) $area = 'store';
$section = (string)($_GET['section'] ?? $_POST['return_section'] ?? $sections[$area][0]);
if (!in_array($section, $sections[$area], true)) $section = $sections[$area][0];
$itemId = max(0, (int)($_GET['item'] ?? $_POST['return_item'] ?? 0));
$variantId = max(0, (int)($_GET['variant'] ?? $_POST['return_variant'] ?? 0));
$orderId = max(0, (int)($_GET['order'] ?? $_POST['return_order'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedToken = (string)($_POST['csrf'] ?? '');
        if ($postedToken === '' || !hash_equals($csrf, $postedToken)) throw new RuntimeException('The form expired. Reload the page and try again.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'reorder_artworks') {
            $order = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['publication_order'] ?? '')))));
            $manager->reorderArtworks($userId, $order);
            $_SESSION['site_manager_notice'] = 'Website artwork order updated.';
        } elseif (in_array($action, ['save_note', 'publish_note', 'unpublish_note'], true)) {
            $verb = $action === 'publish_note' ? 'publish' : ($action === 'unpublish_note' ? 'unpublish' : 'save');
            $manager->saveNote($userId, (int)$_POST['note_id'], (string)($_POST['title'] ?? ''), (string)($_POST['body'] ?? ''), $verb);
            $itemId = (int)$_POST['note_id'];
            $_SESSION['site_manager_notice'] = $verb === 'publish' ? 'Studio Note published.' : 'Studio Note updated.';
        } elseif ($action === 'save_artist') {
            $manager->saveArtist($userId, $_POST);
            $_SESSION['site_manager_notice'] = 'Artist content saved.';
        } elseif ($action === 'save_inquire') {
            $manager->saveSettings($userId, 'inquire', $_POST);
            $_SESSION['site_manager_notice'] = 'Inquiry settings saved.';
        } elseif ($action === 'save_site') {
            $manager->saveSettings($userId, 'site', $_POST);
            $_SESSION['site_manager_notice'] = 'Site settings saved.';
        } elseif ($action === 'save_domain') {
            $manager->saveDomain($userId, $_POST);
            $_SESSION['site_manager_notice'] = 'Domain settings saved.';
        } elseif ($action === 'save_payments') {
            $manager->saveSettings($userId, 'payments', $_POST);
            $_SESSION['site_manager_notice'] = 'Store currency saved.';
        } elseif ($action === 'save_stripe') {
            $manager->saveStripeCredentials($userId, (string)($_POST['stripe_secret_key'] ?? ''), (string)($_POST['stripe_webhook_secret'] ?? ''));
            $_SESSION['site_manager_notice'] = 'Stripe account verified and saved.';
        } elseif ($action === 'refresh_stripe') {
            $manager->saveStripeCredentials($userId, '', '');
            $_SESSION['site_manager_notice'] = 'Stripe account status refreshed.';
        } elseif ($action === 'disconnect_stripe') {
            $manager->disconnectStripeConnection($userId);
            $_SESSION['site_manager_notice'] = 'Stripe account disconnected.';
        } elseif ($action === 'save_shipping') {
            $manager->saveSettings($userId, 'shipping', $_POST);
            $_SESSION['site_manager_notice'] = 'Shipping settings saved.';
        } elseif (in_array($action, ['confirm_order', 'mark_order_paid', 'mark_order_shipped', 'complete_order', 'cancel_order'], true)) {
            $orderId = max(0, (int)($_POST['order_id'] ?? 0));
            $verb = match ($action) {
                'confirm_order' => 'confirm',
                'mark_order_paid' => 'paid',
                'mark_order_shipped' => 'shipped',
                'complete_order' => 'complete',
                default => 'cancel',
            };
            $manager->updateOrder($userId, $orderId, $verb);
            $_SESSION['site_manager_notice'] = 'Order updated.';
        } else {
            throw new RuntimeException('Unknown Site Manager action.');
        }
    } catch (Throwable $error) {
        $_SESSION['site_manager_error'] = $error->getMessage();
    }
    sm_redirect($area, $section, $itemId, $variantId, $orderId);
}

$notice = (string)($_SESSION['site_manager_notice'] ?? '');
$error = (string)($_SESSION['site_manager_error'] ?? '');
unset($_SESSION['site_manager_notice'], $_SESSION['site_manager_error']);
$profile = ArtistProfile::findForUser($userId);
$settings = $manager->settings($userId);
$paymentConnection = $manager->paymentConnection($userId);
$artistName = trim((string)($profile['artist_name'] ?? '')) ?: trim((string)$user['name']) ?: 'Artist';
$domainDestination = $domainService->configuration($userId);
$websiteHost = trim((string)$domainDestination['public_host']);
if ($websiteHost !== '') {
    $websiteUrl = 'https://' . $websiteHost;
    $websiteLabel = $websiteHost;
} else {
    $websiteUrl = '../artist-site/';
    $websiteLabel = 'Local artist website';
}

$areaLabels = ['store' => 'Store'];
$sectionLabels = [
    'artworks' => 'Artworks', 'series' => 'Series',
    'studio-notes' => 'Studio Notes', 'artist' => 'Artist', 'inquire' => 'Inquire',
    'prints' => 'Prices & Stock', 'orders' => 'Orders', 'site' => 'Site', 'domain' => 'Domain',
    'payments' => 'Payments', 'shipping' => 'Shipping', 'activity' => 'Activity',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= sm_h($sectionLabels[$section]) ?> · Store Admin</title>
    <link rel="stylesheet" href="style.css?v=40">
    <?php if ($section === 'studio-notes'): ?><link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet"><?php endif; ?>
</head>
<body>
<header class="manager-header">
    <a class="manager-brand" href="index.php">
        <span>Store Admin</span>
        <small><?= sm_h($sectionLabels[$section]) ?> · <?= sm_h(strtoupper((string)$settings['currency'])) ?></small>
    </a>
    <a class="manager-return-to-app" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/root_album.php">← Return to App</a>
    <div class="manager-header-actions">
        <a href="<?= sm_h($websiteUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="Preview <?= sm_h($websiteLabel) ?>">Preview website ↗</a>
    </div>
</header>
<main class="manager-main">
    <nav class="secondary-tabs store-admin-tabs" aria-label="Store sections">
        <?php foreach ($sections[$area] as $key): ?>
            <a class="<?= $section === $key ? 'is-active' : '' ?>" href="?area=<?= sm_h($area) ?>&section=<?= sm_h($key) ?>"><?= sm_h($sectionLabels[$key]) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($notice !== ''): ?><div class="message message--success" role="status"><?= sm_h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="message message--error" role="alert"><?= sm_h($error) ?></div><?php endif; ?>

    <?php if ($section === 'artworks'): ?>
        <?php
        $artworks = $manager->artworks($userId);
        if ($itemId <= 0 && $artworks) $itemId = (int)$artworks[0]['artwork_id'];
        $selected = $itemId > 0 ? $manager->artwork($userId, $itemId) : null;
        ?>
        <?php $publishedOrder = array_values(array_filter($artworks, static fn(array $artwork): bool => (string)$artwork['publication_status'] === 'published')); ?>
        <form method="post" class="catalog-order" data-public-order-form>
            <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="artworks"><input type="hidden" name="return_item" value="<?= $itemId ?>">
            <input type="hidden" name="publication_order" value="<?= sm_h(implode(',', array_column($publishedOrder, 'publication_id'))) ?>" data-public-order-input>
            <?php if (count($publishedOrder) > 1): ?><p class="order-instruction">Drag published works to set the website order. Keyboard: Alt + ← or →.</p><?php endif; ?>
            <div class="visual-rail" aria-label="Artworks" data-public-order-list>
            <?php $publicPosition = 0; foreach ($artworks as $artwork): $image = (string)($artwork['header_file'] ?: $artwork['image_file']); $isPublished = (string)$artwork['publication_status'] === 'published'; if ($isPublished) $publicPosition++; ?>
                <a class="visual-card <?= (int)$artwork['artwork_id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=artworks&item=<?= (int)$artwork['artwork_id'] ?>" aria-label="<?= sm_h((string)$artwork['artwork_title']) ?> · <?= sm_h((string)$artwork['publication_status']) ?>"<?= $isPublished ? ' draggable="true" data-public-order-card data-publication-id="' . (int)$artwork['publication_id'] . '" aria-keyshortcuts="Alt+ArrowLeft Alt+ArrowRight"' : '' ?>>
                    <?php if ($image !== ''): ?><img src="<?= sm_h(sm_media($image)) ?>" alt="<?= sm_h((string)$artwork['artwork_title']) ?>"><?php endif; ?>
                    <span class="visual-card-state"><?= sm_h((string)$artwork['publication_status']) ?></span>
                    <?php if ($isPublished): ?><span class="visual-card-order" data-public-order-number><?= str_pad((string)$publicPosition, 2, '0', STR_PAD_LEFT) ?></span><?php endif; ?>
                    <strong><?= sm_h((string)$artwork['artwork_title']) ?></strong>
                    <small><?= sm_h((string)($artwork['series_title'] ?: 'Independent work')) ?><?= (string)$artwork['constellation_country'] !== '' ? ' · ' . sm_h((string)$artwork['constellation_country']) : '' ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$artworks): ?><p class="empty-state">No artworks are ready for the website yet.</p><?php endif; ?>
            </div>
            <div class="order-save" data-public-order-save hidden><span aria-live="polite">Website order changed.</span><button class="primary-action" name="action" value="reorder_artworks">Save website order</button></div>
        </form>
        <?php if ($selected): ?>
            <section class="website-content-handoff">
                <div>
                    <p class="editor-context">Selected artwork</p>
                    <h2 class="editor-selected-title"><?= sm_h((string)$selected['artwork_title']) ?></h2>
                    <p>Content and metadata are edited once in Artwork. Public order remains automatic.</p>
                </div>
                <dl>
                    <div><dt>Status</dt><dd><?= sm_h(ucwords(str_replace('_', ' ', (string)$selected['publication_status']))) ?></dd></div>
                    <div><dt>Visibility</dt><dd><?= sm_h(ucfirst((string)$selected['visibility'])) ?></dd></div>
                    <div><dt>Content source</dt><dd>Artwork Metadata</dd></div>
                </dl>
                <a class="primary-action website-content-handoff__link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/artwork.php?id=<?= (int)$selected['artwork_id'] ?>#website-publication">Open artwork website settings</a>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'series'): ?>
        <?php
        $seriesItems = $manager->series($userId);
        if ($itemId <= 0 && $seriesItems) $itemId = (int)$seriesItems[0]['id'];
        $selected = $itemId > 0 ? $manager->seriesItem($userId, $itemId) : null;
        ?>
        <div class="visual-rail">
            <?php foreach ($seriesItems as $series): ?>
                <a class="visual-card <?= (int)$series['id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=series&item=<?= (int)$series['id'] ?>" aria-label="<?= sm_h((string)$series['title']) ?> · <?= (int)$series['published'] === 1 ? 'published' : 'draft' ?>">
                    <?php if ((string)$series['header_file'] !== ''): ?><img src="<?= sm_h(sm_media((string)$series['header_file'])) ?>" alt="<?= sm_h((string)$series['title']) ?>"><?php else: ?><span class="visual-placeholder"><?= sm_h((string)$series['title']) ?></span><?php endif; ?>
                    <span class="visual-card-state"><?= (int)$series['published'] === 1 ? 'published' : 'draft' ?></span>
                    <strong><?= sm_h((string)$series['title']) ?></strong>
                    <small><?= (int)$series['artwork_count'] ?> works · <?= (int)$series['published_artwork_count'] ?> published</small>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($selected): ?>
            <section class="website-content-handoff">
                <div>
                    <p class="editor-context">Selected series</p>
                    <h2 class="editor-selected-title editor-selected-title--series"><?= sm_h(sm_series_title((string)$selected['title'])) ?></h2>
                    <p>Series text, SEO, tags and images are edited once in the canonical Series workspace.</p>
                </div>
                <dl>
                    <div><dt>Status</dt><dd><?= (int)$selected['published'] === 1 ? 'Published' : 'Draft' ?></dd></div>
                    <div><dt>Works</dt><dd><?= (int)$selected['artwork_count'] ?></dd></div>
                    <div><dt>Content source</dt><dd>Series Metadata</dd></div>
                </dl>
                <a class="primary-action website-content-handoff__link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/series.php?series=<?= (int)$selected['id'] ?>">Open Series workspace</a>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'studio-notes'): ?>
        <?php
        $notes = $manager->notes($userId);
        if ($itemId <= 0 && $notes) $itemId = (int)$notes[0]['id'];
        $selected = $itemId > 0 ? $manager->note($userId, $itemId) : null;
        ?>
        <div class="section-tools"><a class="quiet-link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/website_studio_notes.php">Create note in Artwork Mockups</a></div>
        <div class="visual-rail">
            <?php foreach ($notes as $note): $source = (array)($note['source'] ?? []); $hasSourceImage = !empty($source['file']); $hasEmbeddedImage = !$hasSourceImage && EmbeddedNoteImage::has((string)$note['objective']); $hasCardImage = $hasSourceImage || $hasEmbeddedImage; ?>
                <a class="visual-card <?= !$hasCardImage ? 'visual-card--text' : '' ?> <?= (int)$note['id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=studio-notes&item=<?= (int)$note['id'] ?>" aria-label="<?= sm_h((string)$note['title']) ?> · <?= sm_h((string)$note['status']) ?>">
                    <?php if ($hasSourceImage): ?><img src="<?= sm_h(sm_media((string)$source['file'])) ?>" alt="<?= sm_h((string)$note['title']) ?>"><?php elseif ($hasEmbeddedImage): ?><img src="note_thumbnail.php?note=<?= (int)$note['id'] ?>" alt="<?= sm_h((string)$note['title']) ?>"><?php endif; ?>
                    <span class="visual-card-state"><?= sm_h((string)$note['status']) ?></span><strong><?= sm_h((string)$note['title']) ?></strong><small><?= sm_h((string)$note['sourceLabel']) ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$notes): ?><p class="empty-state">No Studio Notes have been prepared.</p><?php endif; ?>
        </div>
        <?php if ($selected): $source = (array)($selected['source'] ?? []); $hasSourceImage = !empty($source['file']); ?>
            <section class="editor-panel <?= !$hasSourceImage ? 'editor-panel--text' : 'editor-panel--compact-visual' ?>">
                <?php if ($hasSourceImage): ?><div class="editor-visual"><img src="<?= sm_h(sm_media((string)$source['file'], 1000)) ?>" alt="<?= sm_h((string)$selected['title']) ?>"><p><?= sm_h((string)$selected['sourceLabel']) ?></p></div><?php endif; ?>
                <form method="post" class="editor-form">
                    <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="studio-notes"><input type="hidden" name="return_item" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="note_id" value="<?= (int)$selected['id'] ?>">
                    <?php if (!$hasSourceImage): ?><p class="editor-context"><?= sm_h((string)$selected['sourceLabel']) ?></p><?php endif; ?>
                    <h2 class="editor-selected-title"><?= sm_h((string)$selected['title']) ?></h2>
                    <label>Title<input name="title" value="<?= sm_h((string)$selected['title']) ?>"></label>
                    <div class="editor-field"><span>Note body</span><div class="rich-note-editor" data-note-editor hidden></div><textarea name="body" rows="14" data-note-source><?= sm_h((string)$selected['objective']) ?></textarea></div>
                    <div class="form-actions"><?php if ((string)$selected['status'] === 'published'): ?><button class="primary-action" name="action" value="save_note">Update website</button><button class="danger-action" name="action" value="unpublish_note" data-confirm="Unpublish this note?">Unpublish</button><?php else: ?><button class="primary-action" name="action" value="publish_note">Publish note</button><button name="action" value="save_note">Save draft</button><?php endif; ?></div>
                </form>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'artist'): ?>
        <section class="editor-panel editor-panel--profile">
            <div class="editor-visual"><?php if ((string)$profile['photo_file'] !== ''): ?><img src="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/profile_media.php?file=<?= rawurlencode(basename((string)$profile['photo_file'])) ?>" alt=""><?php else: ?><div class="large-placeholder"><?= sm_h($artistName) ?></div><?php endif; ?><a class="quiet-link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/artist_profile.php">Manage source profile and photo in Artwork Mockups</a></div>
            <form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="artist"><label>Artist name<input name="artist_name" value="<?= sm_h((string)$profile['artist_name']) ?>"></label><label>Short biography<textarea name="short_bio" rows="7"><?= sm_h((string)$profile['short_bio']) ?></textarea></label><label>Artist statement<textarea name="statement" rows="12"><?= sm_h((string)$profile['statement']) ?></textarea></label><div class="form-actions"><button class="primary-action" name="action" value="save_artist">Update artist content</button></div></form>
        </section>

    <?php elseif ($section === 'inquire'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="inquire"><label>Contact email<input type="email" name="contact_email" value="<?= sm_h((string)$settings['contact_email']) ?>" placeholder="<?= sm_h((string)$user['email']) ?>"></label><label>Public introduction<textarea name="inquiry_intro" rows="8"><?= sm_h((string)$settings['inquiry_intro']) ?></textarea></label><details><summary>Incoming messages</summary><p class="supporting-copy">The public contact form remains active. Tenant-safe message assignment will be migrated before the legacy inbox is retired.</p></details><div class="form-actions"><button class="primary-action" name="action" value="save_inquire">Save inquiry settings</button></div></form></section>

    <?php elseif ($section === 'prints'): ?>
        <?php
        $allArtworks = array_values(array_filter($manager->artworks($userId), static fn(array $artwork): bool => (string)$artwork['publication_status'] === 'published'));
        if ($itemId <= 0 && $allArtworks) $itemId = (int)$allArtworks[0]['artwork_id'];
        $selectedArtwork = $itemId > 0 ? $manager->artwork($userId, $itemId) : null;
        $variants = $itemId > 0 ? $manager->prints($userId, $itemId) : [];
        $selectedVariant = $variants[0] ?? null;
        if ($selectedVariant) $variantId = (int)$selectedVariant['id'];
        $selectedStockLabel = !$selectedVariant ? 'Stock not set' : match ((string)$selectedVariant['status']) {
            'sold_out' => 'Sold', 'paused' => 'Unavailable', 'draft' => 'Draft',
            default => (int)$selectedVariant['stock_available'] . ' available',
        };
        ?>
        <div class="section-intro">
            <h2>Artwork prices and availability</h2>
            <p>Select an artwork to set its public price, currency, availability and stock.</p>
        </div>
        <div class="visual-rail" aria-label="Artwork prices and stock">
            <?php foreach ($allArtworks as $artwork): $stockRecords = $manager->prints($userId, (int)$artwork['artwork_id']); $stock = $stockRecords[0] ?? null; ?>
                <?php
                $stockLabel = 'Stock not set';
                if ($stock) {
                    $stockLabel = match ((string)$stock['status']) {
                        'sold_out' => 'Sold',
                        'paused' => 'Unavailable',
                        'draft' => 'Draft',
                        default => (int)$stock['stock_available'] . ' available',
                    };
                }
                ?>
                <a class="visual-card <?= (int)$artwork['artwork_id'] === $itemId ? 'is-selected' : '' ?>" href="?area=store&section=prints&item=<?= (int)$artwork['artwork_id'] ?>" aria-label="<?= sm_h((string)$artwork['artwork_title']) ?> · <?= sm_h($stockLabel) ?>"><img src="<?= sm_h(sm_media((string)$artwork['image_file'])) ?>" alt="<?= sm_h((string)$artwork['artwork_title']) ?>"><span class="visual-card-state"><?= sm_h($stockLabel) ?></span><strong><?= sm_h((string)$artwork['artwork_title']) ?></strong><small><?= sm_h((string)($artwork['series_title'] ?: 'Independent work')) ?></small></a>
            <?php endforeach; ?>
            <?php if (!$allArtworks): ?><p class="empty-state">Publish an artwork before configuring its stock.</p><?php endif; ?>
        </div>
        <?php if ($selectedArtwork): ?>
            <section class="website-content-handoff">
                <div>
                    <p class="editor-context">Selected artwork</p>
                    <h2 class="editor-selected-title"><?= sm_h((string)$selectedArtwork['artwork_title']) ?></h2>
                    <p>Price and available units are maintained once inside the artwork Website panel.</p>
                </div>
                <dl>
                    <div><dt>Availability</dt><dd><?= sm_h($selectedStockLabel) ?></dd></div>
                    <div><dt>Price</dt><dd><?= $selectedVariant ? sm_h(sm_money((int)$selectedVariant['price_minor'], (string)$selectedVariant['currency'])) : 'Not set' ?></dd></div>
                    <div><dt>Available units</dt><dd><?= (int)($selectedVariant['stock_available'] ?? 0) ?></dd></div>
                </dl>
                <a class="primary-action website-content-handoff__link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/artwork.php?id=<?= (int)$selectedArtwork['artwork_id'] ?>#website-publication">Open price and availability</a>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'orders'): ?>
        <?php $orders = $manager->orders($userId); $selectedOrder = $orderId > 0 ? $manager->order($userId, $orderId) : null; ?>
        <?php if (!$orders): ?>
            <div class="empty-state empty-state--large"><h3>No orders yet</h3><p>Acquisition requests from available artworks will appear here.</p></div>
        <?php else: ?>
            <div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Order status</th><th>Total</th><th>Date</th><th></th></tr></thead><tbody><?php foreach ($orders as $order): ?><tr><td><strong><?= sm_h((string)$order['public_number']) ?></strong></td><td><?= sm_h((string)$order['customer_name']) ?><small><?= sm_h((string)$order['customer_email']) ?></small></td><td><?= sm_h(ucwords(str_replace('_', ' ', (string)$order['payment_status']))) ?></td><td><?= sm_h(ucwords(str_replace('_', ' ', (string)$order['order_status']))) ?></td><td><?= sm_h(sm_money((int)$order['total_minor'], (string)$order['currency'])) ?></td><td><?= sm_h((string)$order['created_at']) ?></td><td><a class="quiet-link" href="?area=store&section=orders&order=<?= (int)$order['id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
        <?php if ($selectedOrder): $shipping = (array)$selectedOrder['shipping']; ?>
            <section class="single-panel order-workspace">
                <div class="order-workspace__heading"><div><p class="editor-context">Acquisition request</p><h2 class="editor-selected-title"><?= sm_h((string)$selectedOrder['public_number']) ?></h2></div><a class="quiet-link" href="?area=store&section=orders">Close</a></div>
                <div class="order-summary-grid">
                    <div><span>Collector</span><strong><?= sm_h((string)$selectedOrder['customer_name']) ?></strong><a href="mailto:<?= sm_h((string)$selectedOrder['customer_email']) ?>"><?= sm_h((string)$selectedOrder['customer_email']) ?></a><?php if ((string)($shipping['phone'] ?? '') !== ''): ?><small><?= sm_h((string)$shipping['phone']) ?></small><?php endif; ?></div>
                    <div><span>Delivery</span><strong><?= sm_h((string)($shipping['country_name'] ?? '')) ?></strong><small><?= sm_h(implode(', ', array_filter([(string)($shipping['address_line_1'] ?? ''), (string)($shipping['address_line_2'] ?? ''), (string)($shipping['city'] ?? ''), (string)($shipping['region'] ?? ''), (string)($shipping['postal_code'] ?? '')]))) ?></small></div>
                    <div><span>Status</span><strong><?= sm_h(ucwords(str_replace('_', ' ', (string)$selectedOrder['order_status']))) ?></strong><small>Payment · <?= sm_h(ucwords(str_replace('_', ' ', (string)$selectedOrder['payment_status']))) ?></small></div>
                </div>
                <div class="order-line-items">
                    <?php foreach ((array)$selectedOrder['items'] as $orderItem): ?><div><span><?= sm_h((string)$orderItem['title']) ?><small><?= sm_h((string)$orderItem['sku']) ?></small></span><strong><?= sm_h(sm_money((int)$orderItem['total_minor'], (string)$selectedOrder['currency'])) ?></strong></div><?php endforeach; ?>
                    <div><span>Shipping · <?= sm_h((string)($shipping['continent_label'] ?? '')) ?></span><strong><?= sm_h(sm_money((int)$selectedOrder['shipping_minor'], (string)$selectedOrder['currency'])) ?></strong></div>
                    <div class="order-line-items__total"><span>Total</span><strong><?= sm_h(sm_money((int)$selectedOrder['total_minor'], (string)$selectedOrder['currency'])) ?></strong></div>
                </div>
                <?php if ((string)($shipping['message'] ?? '') !== ''): ?><div class="order-message"><span>Collector note</span><p><?= nl2br(sm_h((string)$shipping['message'])) ?></p></div><?php endif; ?>
                <?php if (!in_array((string)$selectedOrder['order_status'], ['cancelled', 'completed'], true)): ?>
                    <form method="post" class="form-actions order-actions">
                        <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="store"><input type="hidden" name="return_section" value="orders"><input type="hidden" name="return_order" value="<?= (int)$selectedOrder['id'] ?>"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                        <?php if ((string)$selectedOrder['order_status'] === 'request_received'): ?><button class="primary-action" name="action" value="confirm_order">Confirm order</button><?php endif; ?>
                        <?php if ((string)$selectedOrder['payment_status'] !== 'paid'): ?><button name="action" value="mark_order_paid">Mark paid</button><?php endif; ?>
                        <?php if ((string)$selectedOrder['payment_status'] === 'paid' && (string)$selectedOrder['order_status'] !== 'shipped'): ?><button name="action" value="mark_order_shipped">Mark shipped</button><?php endif; ?>
                        <?php if ((string)$selectedOrder['payment_status'] === 'paid'): ?><button name="action" value="complete_order" data-confirm="Complete this order and mark the reserved artwork as sold?">Complete and mark sold</button><?php endif; ?>
                        <button class="danger-action" name="action" value="cancel_order" data-confirm="Cancel this order and release its reserved stock?">Cancel order</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'site'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="site"><label>Site title<input name="site_title" value="<?= sm_h((string)$settings['site_title']) ?>"></label><label>Tagline<input name="tagline" value="<?= sm_h((string)$settings['tagline']) ?>"></label><div class="form-grid"><label>Language<input name="locale" value="<?= sm_h((string)$settings['locale']) ?>"></label><label>Site status<select name="site_status"><?php foreach (['draft'=>'Draft','active'=>'Active','suspended'=>'Suspended'] as $value=>$label): ?><option value="<?= $value ?>" <?= (string)$settings['site_status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label></div><div class="form-actions"><button class="primary-action" name="action" value="save_site">Save site settings</button></div></form></section>

    <?php elseif ($section === 'domain'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="domain"><label>Artwork Mockups subdomain<div class="input-suffix"><input name="subdomain" value="<?= sm_h((string)$profile['subdomain']) ?>"><span>.artworkmockups.com</span></div></label><label>Custom domain<input name="custom_domain" value="<?= sm_h((string)$profile['custom_domain']) ?>" placeholder="artist.com"></label><p class="supporting-copy">Domain verification remains pending until DNS provisioning is connected.</p><div class="form-actions"><button class="primary-action" name="action" value="save_domain">Save domain</button></div></form></section>

    <?php elseif ($section === 'payments'): ?>
        <?php
        $stripeAccountId = (string)$paymentConnection['external_account_id'];
        $stripeConnected = str_starts_with($stripeAccountId, 'acct_');
        $stripeStatus = (string)$paymentConnection['connection_status'];
        $maskedStripeAccount = $stripeConnected ? 'acct_••••' . substr($stripeAccountId, -6) : '';
        $stripeAccountMode = !empty($paymentConnection['livemode']) ? 'live' : 'test';
        $stripeArtistReady = $stripeConnected
            && $stripeStatus === 'connected'
            && !empty($paymentConnection['charges_enabled'])
            && !empty($paymentConnection['payouts_enabled'])
            && !empty($paymentConnection['has_secret_key'])
            && !empty($paymentConnection['has_webhook_secret']);
        $stripeStorageReady = StripeArtistCredentials::encryptionConfigured();
        $stripeWebhookUrl = rtrim(app_env('APP_PUBLIC_URL', 'https://artworkmockups.com'), '/') . '/integrations/stripe/webhook/';
        ?>
        <section class="single-panel payment-settings">
            <div class="section-intro"><p class="editor-context">Payments</p><h2>Your Stripe account</h2><p>Enter the credentials from your own Stripe account. This artist website will create Checkout payments directly in that account; no Artwork Mockups Stripe account is involved.</p></div>
            <div class="payment-path" aria-label="Stripe setup status">
                <div class="payment-path__step">
                    <span class="payment-path__number">1</span>
                    <div><strong>Account credentials</strong><small><?= !empty($paymentConnection['has_secret_key']) ? 'The secret key is stored encrypted.' : 'Add this artist’s Stripe secret key.' ?></small></div>
                    <span class="payment-state <?= !empty($paymentConnection['has_secret_key']) ? 'is-ready' : '' ?>"><?= !empty($paymentConnection['has_secret_key']) ? 'Saved' : 'Required' ?></span>
                </div>
                <div class="payment-path__step">
                    <span class="payment-path__number">2</span>
                    <div><strong>This artist’s account</strong><small><?= $stripeConnected ? sm_h($maskedStripeAccount . ' · ' . ucfirst($stripeAccountMode) . ' mode') : 'The account will be identified when the key is verified.' ?></small></div>
                    <span class="payment-state <?= $stripeArtistReady ? 'is-ready' : '' ?>"><?= $stripeArtistReady ? 'Connected' : ($stripeConnected ? sm_h(ucwords(str_replace('_', ' ', $stripeStatus))) : 'Not connected') ?></span>
                </div>
                <div class="payment-path__step">
                    <span class="payment-path__number">3</span>
                    <div><strong>Website checkout</strong><small><?= $stripeArtistReady ? 'Payments and payouts are enabled for this artist.' : 'Checkout remains unavailable until the account and webhook are ready.' ?></small></div>
                    <span class="payment-state <?= $stripeArtistReady ? 'is-ready' : '' ?>"><?= $stripeArtistReady ? 'Active' : 'Inactive' ?></span>
                </div>
            </div>
            <form method="post" class="editor-form stripe-credentials-form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="store"><input type="hidden" name="return_section" value="payments">
                <label>Stripe secret key<input type="password" name="stripe_secret_key" spellcheck="false" autocomplete="new-password" placeholder="<?= !empty($paymentConnection['has_secret_key']) ? 'Saved — leave blank to keep it' : 'sk_live_… or sk_test_…' ?>"></label>
                <p class="supporting-copy">Use the secret key from this artist’s Stripe account. A publishable key is not needed because Checkout is created on the server.</p>
                <label>Webhook signing secret<input type="password" name="stripe_webhook_secret" spellcheck="false" autocomplete="new-password" placeholder="<?= !empty($paymentConnection['has_webhook_secret']) ? 'Saved — leave blank to keep it' : 'whsec_…' ?>"></label>
                <p class="supporting-copy">In Stripe, add endpoint <code><?= sm_h($stripeWebhookUrl) ?></code> and subscribe to <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>checkout.session.async_payment_failed</code> and <code>checkout.session.expired</code>.</p>
                <?php if (!$stripeStorageReady): ?><p class="supporting-copy payment-warning">Encrypted Stripe credential storage is not configured on this server yet.</p><?php endif; ?>
                <?php if ($stripeConnected && $stripeStatus !== 'connected'): ?><p class="supporting-copy payment-warning">Stripe verified the account, but it still requires account details before charges and payouts can be enabled.</p><?php endif; ?>
                <div class="form-actions"><button class="primary-action" name="action" value="save_stripe" <?= $stripeStorageReady ? '' : 'disabled' ?>>Verify and save Stripe</button><?php if ($stripeConnected): ?><button name="action" value="refresh_stripe">Refresh status</button><button class="danger-action" name="action" value="disconnect_stripe" data-confirm="Remove this artist’s saved Stripe credentials?">Disconnect</button><?php endif; ?></div>
            </form>
            <details class="payment-currency-settings">
                <summary>Store currency · <?= sm_h(strtoupper((string)$settings['currency'])) ?></summary>
                <form method="post" class="editor-form payment-currency"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="store"><input type="hidden" name="return_section" value="payments"><label>Store currency<input name="currency" maxlength="3" value="<?= sm_h((string)$settings['currency']) ?>"></label><p class="supporting-copy">Artwork prices and shipping must use this same currency.</p><div class="form-actions"><button name="action" value="save_payments">Save currency</button></div></form>
            </details>
        </section>

    <?php elseif ($section === 'shipping'): ?>
        <?php
        $shippingContinents = [
            'europe' => 'Europe',
            'africa' => 'Africa',
            'asia' => 'Asia',
            'north_america' => 'North America',
            'south_america' => 'South America',
            'oceania' => 'Oceania',
        ];
        $shippingRates = json_decode((string)($settings['shipping_rates_json'] ?? ''), true);
        if (!is_array($shippingRates)) $shippingRates = [];
        ?>
        <section class="single-panel">
            <form method="post" class="editor-form">
                <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="store"><input type="hidden" name="return_section" value="shipping">
                <h2 class="editor-selected-title">Shipping by continent</h2>
                <p class="supporting-copy">Set the standard shipping charge for each destination. Every new order will preserve the rate applied at checkout.</p>
                <div class="form-grid shipping-rate-grid">
                    <?php foreach ($shippingContinents as $continentKey => $continentLabel): $rateMinor = max(0, (int)($shippingRates[$continentKey] ?? 25000)); ?>
                        <label><?= sm_h($continentLabel) ?><span class="input-suffix"><input inputmode="decimal" name="shipping_rate_<?= sm_h($continentKey) ?>" value="<?= sm_h(number_format($rateMinor / 100, 2, '.', '')) ?>"><span><?= sm_h((string)$settings['currency']) ?></span></span></label>
                    <?php endforeach; ?>
                </div>
                <label>Shipping and returns policy<textarea name="shipping_policy" rows="12"><?= sm_h((string)$settings['shipping_policy']) ?></textarea></label>
                <div class="form-actions"><button class="primary-action" name="action" value="save_shipping">Save shipping rates</button></div>
            </form>
        </section>

    <?php else: ?>
        <?php $events = $manager->activity($userId); ?>
        <?php if (!$events): ?><div class="empty-state empty-state--large"><h3>No activity yet</h3><p>Changes made in Site Manager will be recorded here.</p></div><?php else: ?><div class="activity-list"><?php foreach ($events as $event): ?><article><div><strong><?= sm_h((string)$event['message']) ?></strong><span><?= sm_h(str_replace('.', ' · ', (string)$event['event_type'])) ?></span></div><time><?= sm_h((string)$event['created_at']) ?></time></article><?php endforeach; ?></div><?php endif; ?>
    <?php endif; ?>
</main>
<?php if ($section === 'studio-notes'): ?><script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script><?php endif; ?>
<script>
document.addEventListener('click', (event) => {
    const target = event.target.closest('[data-confirm]');
    if (target && !window.confirm(target.dataset.confirm || 'Continue?')) event.preventDefault();
});
document.addEventListener('error', (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement)) return;
    const card = image.closest('.visual-card');
    if (!card) return;
    image.remove();
    card.classList.add('visual-card--text');
}, true);
const noteSource = document.querySelector('[data-note-source]');
const noteEditor = document.querySelector('[data-note-editor]');
if (noteSource && noteEditor && window.Quill) {
    noteEditor.hidden = false;
    noteSource.hidden = true;
    const quill = new Quill(noteEditor, {
        theme: 'snow',
        modules: { toolbar: [['bold', 'italic'], [{ header: [2, 3, false] }], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'blockquote'], ['clean']] },
    });
    quill.clipboard.dangerouslyPasteHTML(noteSource.value);
    noteSource.form?.addEventListener('submit', () => { noteSource.value = quill.root.innerHTML; });
}
const keepSelectedCardsVisible = () => {
    document.querySelectorAll('.visual-rail').forEach(rail => {
        const selected = rail.querySelector('.visual-card.is-selected');
        if (!selected) return;
        const centered = selected.offsetLeft - ((rail.clientWidth - selected.offsetWidth) / 2);
        rail.scrollLeft = Math.max(0, Math.min(centered, rail.scrollWidth - rail.clientWidth));
    });
};
window.requestAnimationFrame(keepSelectedCardsVisible);
const publicOrderForm = document.querySelector('[data-public-order-form]');
if (publicOrderForm) {
    const list = publicOrderForm.querySelector('[data-public-order-list]');
    const input = publicOrderForm.querySelector('[data-public-order-input]');
    const save = publicOrderForm.querySelector('[data-public-order-save]');
    let dragged = null;
    let didDrag = false;
    const cards = () => Array.from(list.querySelectorAll('[data-public-order-card]'));
    const changed = () => {
        const ordered = cards();
        input.value = ordered.map(card => card.dataset.publicationId).join(',');
        ordered.forEach((card, index) => {
            const number = card.querySelector('[data-public-order-number]');
            if (number) number.textContent = String(index + 1).padStart(2, '0');
        });
        save.hidden = false;
    };
    list.addEventListener('dragstart', event => {
        dragged = event.target.closest('[data-public-order-card]');
        if (!dragged) return;
        didDrag = true;
        dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', event => {
        if (!dragged) return;
        event.preventDefault();
        const target = event.target.closest('[data-public-order-card]');
        if (!target || target === dragged) return;
        const box = target.getBoundingClientRect();
        list.insertBefore(dragged, event.clientX < box.left + box.width / 2 ? target : target.nextSibling);
    });
    list.addEventListener('drop', event => { if (dragged) { event.preventDefault(); changed(); } });
    list.addEventListener('dragend', () => {
        dragged?.classList.remove('is-dragging');
        dragged = null;
        window.setTimeout(() => { didDrag = false; }, 0);
    });
    list.addEventListener('click', event => { if (didDrag) event.preventDefault(); });
    list.addEventListener('keydown', event => {
        if (!event.altKey || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        const card = event.target.closest('[data-public-order-card]');
        if (!card) return;
        const ordered = cards();
        const index = ordered.indexOf(card);
        const neighbor = ordered[index + (event.key === 'ArrowLeft' ? -1 : 1)];
        if (!neighbor) return;
        event.preventDefault();
        if (event.key === 'ArrowLeft') list.insertBefore(card, neighbor);
        else list.insertBefore(neighbor, card);
        changed();
        card.focus();
    });
}
</script>
</body>
</html>
