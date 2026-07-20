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

function sm_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function sm_redirect(string $area, string $section, int $item = 0, int $variant = 0): never
{
    $query = ['area' => $area, 'section' => $section];
    if ($item > 0) $query['item'] = $item;
    if ($variant > 0) $query['variant'] = $variant;
    header('Location: index.php?' . http_build_query($query));
    exit;
}

$sections = [
    'content' => ['artworks', 'series', 'studio-notes', 'artist', 'inquire'],
    'store' => ['prints', 'orders'],
    'settings' => ['site', 'domain', 'payments', 'shipping'],
    'activity' => ['activity'],
];
$area = (string)($_GET['area'] ?? $_POST['return_area'] ?? 'content');
if (!isset($sections[$area])) $area = 'content';
$section = (string)($_GET['section'] ?? $_POST['return_section'] ?? $sections[$area][0]);
if (!in_array($section, $sections[$area], true)) $section = $sections[$area][0];
$itemId = max(0, (int)($_GET['item'] ?? $_POST['return_item'] ?? 0));
$variantId = max(0, (int)($_GET['variant'] ?? $_POST['return_variant'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $postedToken = (string)($_POST['csrf'] ?? '');
        if ($postedToken === '' || !hash_equals($csrf, $postedToken)) throw new RuntimeException('The form expired. Reload the page and try again.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'reorder_artworks') {
            $order = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['publication_order'] ?? '')))));
            $manager->reorderArtworks($userId, $order);
            $_SESSION['site_manager_notice'] = 'Website artwork order updated.';
        } elseif (in_array($action, ['save_artwork', 'publish_artwork', 'unpublish_artwork', 'hide_artwork', 'show_artwork'], true)) {
            $verb = match ($action) {
                'publish_artwork' => 'publish', 'unpublish_artwork' => 'unpublish',
                'hide_artwork' => 'hide', 'show_artwork' => 'show', default => 'save',
            };
            $manager->saveArtwork($userId, (int)$_POST['artwork_id'], $_POST, $verb);
            $itemId = (int)$_POST['artwork_id'];
            $_SESSION['site_manager_notice'] = $verb === 'publish' ? 'Artwork published.' : 'Artwork website entry updated.';
        } elseif (in_array($action, ['save_series', 'publish_series', 'unpublish_series'], true)) {
            $verb = $action === 'publish_series' ? 'publish' : ($action === 'unpublish_series' ? 'unpublish' : 'save');
            $manager->saveSeries($userId, (int)$_POST['series_id'], $_POST, $verb);
            $itemId = (int)$_POST['series_id'];
            $_SESSION['site_manager_notice'] = $verb === 'publish' ? 'Series published.' : 'Series website entry updated.';
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
            $_SESSION['site_manager_notice'] = 'Payment preferences saved. No live provider connection was created.';
        } elseif ($action === 'save_shipping') {
            $manager->saveSettings($userId, 'shipping', $_POST);
            $_SESSION['site_manager_notice'] = 'Shipping settings saved.';
        } elseif ($action === 'save_print') {
            $itemId = (int)$_POST['artwork_id'];
            $variantId = $manager->savePrint($userId, $itemId, (int)($_POST['variant_id'] ?? 0), $_POST);
            $_SESSION['site_manager_notice'] = 'Artwork stock saved.';
        } else {
            throw new RuntimeException('Unknown Site Manager action.');
        }
    } catch (Throwable $error) {
        $_SESSION['site_manager_error'] = $error->getMessage();
    }
    sm_redirect($area, $section, $itemId, $variantId);
}

$notice = (string)($_SESSION['site_manager_notice'] ?? '');
$error = (string)($_SESSION['site_manager_error'] ?? '');
unset($_SESSION['site_manager_notice'], $_SESSION['site_manager_error']);
$profile = ArtistProfile::findForUser($userId);
$settings = $manager->settings($userId);
$artistName = trim((string)($profile['artist_name'] ?? '')) ?: trim((string)$user['name']) ?: 'Artist';
$customDomain = trim((string)($profile['custom_domain'] ?? ''));
$subdomain = trim((string)($profile['subdomain'] ?? ''));
if ($customDomain !== '') {
    $websiteUrl = 'https://' . $customDomain;
    $websiteLabel = $customDomain;
} elseif ($subdomain !== '') {
    $websiteUrl = 'https://' . $subdomain . '.artworkmockups.com';
    $websiteLabel = $subdomain . '.artworkmockups.com';
} else {
    $websiteUrl = '../artist-site/';
    $websiteLabel = 'Local artist website';
}

$areaLabels = ['content' => 'Content', 'store' => 'Store', 'settings' => 'Settings', 'activity' => 'Activity'];
$sectionLabels = [
    'artworks' => 'Artworks', 'series' => 'Series',
    'studio-notes' => 'Studio Notes', 'artist' => 'Artist', 'inquire' => 'Inquire',
    'prints' => 'Stock', 'orders' => 'Orders', 'site' => 'Site', 'domain' => 'Domain',
    'payments' => 'Payments', 'shipping' => 'Shipping', 'activity' => 'Activity',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= sm_h($sectionLabels[$section]) ?> · Artist Site Manager</title>
    <link rel="stylesheet" href="style.css?v=7">
    <?php if ($section === 'studio-notes'): ?><link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet"><?php endif; ?>
</head>
<body>
<header class="manager-header">
    <a class="manager-brand" href="index.php">
        <span>Artist Site Manager</span>
        <small><?= sm_h($artistName) ?> · <?= sm_h((string)$settings['site_status']) ?></small>
    </a>
    <nav class="primary-tabs" aria-label="Site Manager sections">
        <?php foreach ($areaLabels as $key => $label): ?>
            <a class="<?= $area === $key ? 'is-active' : '' ?>" href="?area=<?= sm_h($key) ?>&section=<?= sm_h($sections[$key][0]) ?>"><?= sm_h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="manager-header-actions">
        <a href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/website_board.php">Back to Artwork Mockups</a>
        <a href="<?= sm_h($websiteUrl) ?>" target="_blank" rel="noopener noreferrer">View <?= sm_h($websiteLabel) ?></a>
    </div>
</header>

<main class="manager-main">
    <nav class="secondary-tabs" aria-label="<?= sm_h($areaLabels[$area]) ?> sections">
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
                <a class="visual-card <?= (int)$artwork['artwork_id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=artworks&item=<?= (int)$artwork['artwork_id'] ?>"<?= $isPublished ? ' draggable="true" data-public-order-card data-publication-id="' . (int)$artwork['publication_id'] . '" aria-keyshortcuts="Alt+ArrowLeft Alt+ArrowRight"' : '' ?>>
                    <?php if ($image !== ''): ?><img src="<?= sm_h(sm_media($image)) ?>" alt=""><?php endif; ?>
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
        <?php if ($selected): $selectedImage = (string)($selected['header_file'] ?: $selected['image_file']); $coverOptions = $manager->artworkCoverOptions($userId, (int)$selected['artwork_id']); ?>
            <section class="editor-panel">
                <div class="editor-visual">
                    <?php if ($selectedImage !== ''): ?><img src="<?= sm_h(sm_media($selectedImage, 1000)) ?>" alt=""><?php endif; ?>
                    <p><?= sm_h((string)($selected['series_title'] ?: 'No series')) ?> · <?= sm_h((string)$selected['publication_status']) ?></p>
                </div>
                <form method="post" class="editor-form">
                    <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>">
                    <input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="artworks">
                    <input type="hidden" name="return_item" value="<?= (int)$selected['artwork_id'] ?>">
                    <input type="hidden" name="artwork_id" value="<?= (int)$selected['artwork_id'] ?>">
                    <label>Public title<input name="title" value="<?= sm_h((string)($selected['public_title'] ?: $selected['artwork_title'])) ?>"></label>
                    <label>Short description<textarea name="short_description" rows="4"><?= sm_h((string)$selected['short_description']) ?></textarea></label>
                    <label>Full description<textarea name="description" rows="9"><?= sm_h((string)$selected['description']) ?></textarea></label>
                    <label>Constellation country<input name="constellation_country" value="<?= sm_h((string)$selected['constellation_country']) ?>" placeholder="Optional · leave empty to hide"></label>
                    <details>
                        <summary>Catalog cover</summary>
                        <div class="cover-choice-grid">
                            <?php foreach ($coverOptions as $cover): ?>
                                <label class="cover-choice"><input type="radio" name="header_file" value="<?= sm_h($cover['file']) ?>" <?= basename($selectedImage) === $cover['file'] ? 'checked' : '' ?>><img src="<?= sm_h(sm_media($cover['file'])) ?>" alt=""><span><?= sm_h($cover['label']) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                    <details>
                        <summary>Inquiry and external destination</summary>
                        <div class="form-grid">
                            <label>CTA label<input name="cta_label" value="<?= sm_h((string)($selected['cta_label'] ?: 'Inquire about this work')) ?>"></label>
                            <label>CTA URL<input name="cta_url" type="url" value="<?= sm_h((string)$selected['cta_url']) ?>"></label>
                        </div>
                    </details>
                    <div class="form-actions">
                        <?php if ((string)$selected['publication_status'] === 'published'): ?>
                            <button class="primary-action" name="action" value="save_artwork">Update website</button>
                            <?php if ((string)$selected['visibility'] === 'unlisted'): ?><button name="action" value="show_artwork">Show</button><?php else: ?><button name="action" value="hide_artwork">Hide</button><?php endif; ?>
                            <button class="danger-action" name="action" value="unpublish_artwork" data-confirm="Unpublish this artwork?">Unpublish</button>
                        <?php else: ?>
                            <button class="primary-action" name="action" value="publish_artwork">Publish artwork</button>
                            <button name="action" value="save_artwork">Save draft</button>
                        <?php endif; ?>
                    </div>
                </form>
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
                <a class="visual-card <?= (int)$series['id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=series&item=<?= (int)$series['id'] ?>">
                    <?php if ((string)$series['header_file'] !== ''): ?><img src="<?= sm_h(sm_media((string)$series['header_file'])) ?>" alt=""><?php else: ?><span class="visual-placeholder"><?= sm_h((string)$series['title']) ?></span><?php endif; ?>
                    <span class="visual-card-state"><?= (int)$series['published'] === 1 ? 'published' : 'draft' ?></span>
                    <strong><?= sm_h((string)$series['title']) ?></strong>
                    <small><?= (int)$series['artwork_count'] ?> works · <?= (int)$series['published_artwork_count'] ?> published</small>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($selected): ?>
            <section class="editor-panel">
                <div class="editor-visual">
                    <?php if ((string)$selected['header_file'] !== ''): ?><img src="<?= sm_h(sm_media((string)$selected['header_file'], 1000)) ?>" alt=""><?php else: ?><div class="large-placeholder"><?= sm_h((string)$selected['title']) ?></div><?php endif; ?>
                    <p><?= (int)$selected['artwork_count'] ?> associated · <?= (int)$selected['published_artwork_count'] ?> published</p>
                    <a class="quiet-link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/series.php?series=<?= (int)$selected['id'] ?>">Manage source images in Artwork Mockups</a>
                </div>
                <form method="post" class="editor-form">
                    <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="series"><input type="hidden" name="return_item" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="series_id" value="<?= (int)$selected['id'] ?>">
                    <div class="form-grid"><label>Title<input name="title" value="<?= sm_h((string)$selected['title']) ?>"></label><label>Subtitle<input name="subtitle" value="<?= sm_h((string)$selected['subtitle']) ?>"></label><label>URL slug<input name="slug" value="<?= sm_h((string)$selected['slug']) ?>"></label><label>Years<input name="year_start" inputmode="numeric" value="<?= sm_h((string)$selected['year_start']) ?>" placeholder="From"><input name="year_end" inputmode="numeric" value="<?= sm_h((string)$selected['year_end']) ?>" placeholder="Present"></label></div>
                    <label>Short description<textarea name="description" rows="5"><?= sm_h((string)$selected['description']) ?></textarea></label>
                    <label>Long description<textarea name="long_description" rows="9"><?= sm_h((string)$selected['long_description']) ?></textarea></label>
                    <details><summary>Search and classification</summary><div class="form-grid"><label>Tags<textarea name="tags" rows="4"><?= sm_h((string)$selected['tags']) ?></textarea></label><label>Long-tail keywords<textarea name="keywords" rows="4"><?= sm_h((string)$selected['keywords']) ?></textarea></label></div><label>SEO description<textarea name="seo_description" rows="3"><?= sm_h((string)$selected['seo_description']) ?></textarea></label></details>
                    <div class="form-actions">
                        <?php if ((int)$selected['published'] === 1): ?><button class="primary-action" name="action" value="save_series">Update website</button><button class="danger-action" name="action" value="unpublish_series" data-confirm="Unpublish this series?">Unpublish</button><?php else: ?><button class="primary-action" name="action" value="publish_series">Publish series</button><button name="action" value="save_series">Save draft</button><?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'studio-notes'): ?>
        <?php
        $notes = $manager->notes($userId);
        if ($itemId <= 0 && $notes) $itemId = (int)$notes[0]['id'];
        $selected = $itemId > 0 ? $manager->note($userId, $itemId) : null;
        ?>
        <div class="section-tools"><a class="quiet-link" href="<?= sm_h(SITE_MANAGER_PLATFORM_PREFIX) ?>/website_board.php?focus=notes">Create note in Artwork Mockups</a></div>
        <div class="visual-rail">
            <?php foreach ($notes as $note): $source = (array)($note['source'] ?? []); $hasSourceImage = !empty($source['file']); $hasEmbeddedImage = !$hasSourceImage && EmbeddedNoteImage::has((string)$note['objective']); $hasCardImage = $hasSourceImage || $hasEmbeddedImage; ?>
                <a class="visual-card <?= !$hasCardImage ? 'visual-card--text' : '' ?> <?= (int)$note['id'] === $itemId ? 'is-selected' : '' ?>" href="?area=content&section=studio-notes&item=<?= (int)$note['id'] ?>">
                    <?php if ($hasSourceImage): ?><img src="<?= sm_h(sm_media((string)$source['file'])) ?>" alt=""><?php elseif ($hasEmbeddedImage): ?><img src="note_thumbnail.php?note=<?= (int)$note['id'] ?>" alt=""><?php endif; ?>
                    <span class="visual-card-state"><?= sm_h((string)$note['status']) ?></span><strong><?= sm_h((string)$note['title']) ?></strong><small><?= sm_h((string)$note['sourceLabel']) ?></small>
                </a>
            <?php endforeach; ?>
            <?php if (!$notes): ?><p class="empty-state">No Studio Notes have been prepared.</p><?php endif; ?>
        </div>
        <?php if ($selected): $source = (array)($selected['source'] ?? []); $hasSourceImage = !empty($source['file']); ?>
            <section class="editor-panel <?= !$hasSourceImage ? 'editor-panel--text' : '' ?>">
                <?php if ($hasSourceImage): ?><div class="editor-visual"><img src="<?= sm_h(sm_media((string)$source['file'], 1000)) ?>" alt=""><p><?= sm_h((string)$selected['sourceLabel']) ?></p></div><?php endif; ?>
                <form method="post" class="editor-form">
                    <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="content"><input type="hidden" name="return_section" value="studio-notes"><input type="hidden" name="return_item" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="note_id" value="<?= (int)$selected['id'] ?>">
                    <?php if (!$hasSourceImage): ?><p class="editor-context"><?= sm_h((string)$selected['sourceLabel']) ?></p><?php endif; ?>
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
        ?>
        <div class="visual-rail" aria-label="Artwork stock">
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
                <a class="visual-card <?= (int)$artwork['artwork_id'] === $itemId ? 'is-selected' : '' ?>" href="?area=store&section=prints&item=<?= (int)$artwork['artwork_id'] ?>"><img src="<?= sm_h(sm_media((string)$artwork['image_file'])) ?>" alt=""><span class="visual-card-state"><?= sm_h($stockLabel) ?></span><strong><?= sm_h((string)$artwork['artwork_title']) ?></strong><small><?= sm_h((string)($artwork['series_title'] ?: 'Independent work')) ?></small></a>
            <?php endforeach; ?>
            <?php if (!$allArtworks): ?><p class="empty-state">Publish an artwork before configuring its stock.</p><?php endif; ?>
        </div>
        <?php if ($selectedArtwork): ?>
            <section class="editor-panel editor-panel--stock">
                <div class="editor-visual"><img src="<?= sm_h(sm_media((string)$selectedArtwork['image_file'], 1000)) ?>" alt=""><h3><?= sm_h((string)$selectedArtwork['artwork_title']) ?></h3></div>
                <form method="post" class="editor-form">
                    <input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="store"><input type="hidden" name="return_section" value="prints"><input type="hidden" name="return_item" value="<?= $itemId ?>"><input type="hidden" name="return_variant" value="<?= $variantId ?>"><input type="hidden" name="artwork_id" value="<?= $itemId ?>"><input type="hidden" name="variant_id" value="<?= (int)($selectedVariant['id'] ?? 0) ?>">
                    <h3>Artwork stock</h3>
                    <div class="form-grid form-grid--stock"><label>Availability<select name="status"><?php foreach (['active'=>'Available','paused'=>'Temporarily unavailable','sold_out'=>'Sold','draft'=>'Not configured'] as $value=>$label): ?><option value="<?= $value ?>" <?= (string)($selectedVariant['status'] ?? 'draft') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label><label>Available units<input type="number" min="0" name="stock_on_hand" value="<?= (int)($selectedVariant['stock_on_hand'] ?? 0) ?>"></label><label>Price<input inputmode="decimal" name="price" value="<?= $selectedVariant ? sm_h(number_format((int)$selectedVariant['price_minor']/100, 2, '.', '')) : '' ?>"></label><label>Currency<input name="currency" maxlength="3" value="<?= sm_h((string)($selectedVariant['currency'] ?? $settings['currency'])) ?>"></label></div>
                    <input type="hidden" name="inventory_mode" value="<?= sm_h((string)($selectedVariant['inventory_mode'] ?? 'in_stock')) ?>"><input type="hidden" name="edition_size" value="<?= max(1, (int)($selectedVariant['edition_size'] ?? 1)) ?>">
                    <details class="stock-details"><summary>Optional sale details</summary><div class="form-grid"><label>Public sale title<input name="title" value="<?= sm_h((string)($selectedVariant['title'] ?? '')) ?>" placeholder="Original artwork"></label><label>SKU<input name="sku" value="<?= sm_h((string)($selectedVariant['sku'] ?? '')) ?>" placeholder="Generated automatically"></label><label>Size<input name="size_label" value="<?= sm_h((string)($selectedVariant['size_label'] ?? '')) ?>" placeholder="80 × 120 cm"></label><label>Support<input name="support" value="<?= sm_h((string)($selectedVariant['support'] ?? '')) ?>" placeholder="Canvas"></label><label>Finish<input name="finish" value="<?= sm_h((string)($selectedVariant['finish'] ?? '')) ?>"></label></div></details>
                    <div class="form-actions"><button class="primary-action" name="action" value="save_print">Save stock</button></div>
                </form>
            </section>
        <?php endif; ?>

    <?php elseif ($section === 'orders'): ?>
        <?php $orders = $manager->orders($userId); ?>
        <?php if (!$orders): ?><div class="empty-state empty-state--large"><h3>No orders yet</h3><p>Orders will appear here after a payment provider and public checkout are activated.</p></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Payment</th><th>Production</th><th>Total</th><th>Date</th></tr></thead><tbody><?php foreach ($orders as $order): ?><tr><td><?= sm_h((string)$order['public_number']) ?></td><td><?= sm_h((string)$order['customer_name']) ?><small><?= sm_h((string)$order['customer_email']) ?></small></td><td><?= sm_h((string)$order['payment_status']) ?></td><td><?= sm_h((string)$order['order_status']) ?></td><td><?= sm_h(sm_money((int)$order['total_minor'], (string)$order['currency'])) ?></td><td><?= sm_h((string)$order['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

    <?php elseif ($section === 'site'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="site"><label>Site title<input name="site_title" value="<?= sm_h((string)$settings['site_title']) ?>"></label><label>Tagline<input name="tagline" value="<?= sm_h((string)$settings['tagline']) ?>"></label><div class="form-grid"><label>Language<input name="locale" value="<?= sm_h((string)$settings['locale']) ?>"></label><label>Site status<select name="site_status"><?php foreach (['draft'=>'Draft','active'=>'Active','suspended'=>'Suspended'] as $value=>$label): ?><option value="<?= $value ?>" <?= (string)$settings['site_status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label></div><div class="form-actions"><button class="primary-action" name="action" value="save_site">Save site settings</button></div></form></section>

    <?php elseif ($section === 'domain'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="domain"><label>Artwork Mockups subdomain<div class="input-suffix"><input name="subdomain" value="<?= sm_h((string)$profile['subdomain']) ?>"><span>.artworkmockups.com</span></div></label><label>Custom domain<input name="custom_domain" value="<?= sm_h((string)$profile['custom_domain']) ?>" placeholder="artist.com"></label><p class="supporting-copy">Domain verification remains pending until DNS provisioning is connected.</p><div class="form-actions"><button class="primary-action" name="action" value="save_domain">Save domain</button></div></form></section>

    <?php elseif ($section === 'payments'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="payments"><label>Provider identifier<input name="payment_provider" value="<?= sm_h((string)$settings['payment_provider']) ?>" placeholder="Leave empty until selected"></label><label>Store currency<input name="currency" maxlength="3" value="<?= sm_h((string)$settings['currency']) ?>"></label><p class="supporting-copy">Status: <?= sm_h((string)$settings['payment_status']) ?>. No card or bank credentials are collected here.</p><div class="form-actions"><button class="primary-action" name="action" value="save_payments">Save payment preferences</button></div></form></section>

    <?php elseif ($section === 'shipping'): ?>
        <section class="single-panel"><form method="post" class="editor-form"><input type="hidden" name="csrf" value="<?= sm_h($csrf) ?>"><input type="hidden" name="return_area" value="settings"><input type="hidden" name="return_section" value="shipping"><label>Shipping regions<textarea name="shipping_regions" rows="5" placeholder="Spain, European Union, United States"><?= sm_h((string)$settings['shipping_regions']) ?></textarea></label><label>Shipping and returns policy<textarea name="shipping_policy" rows="12"><?= sm_h((string)$settings['shipping_policy']) ?></textarea></label><div class="form-actions"><button class="primary-action" name="action" value="save_shipping">Save shipping settings</button></div></form></section>

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
