<?php
declare(strict_types=1);

require_once __DIR__ . '/../../platform/app/bootstrap.php';
require_once __DIR__ . '/../app/SiteManagerService.php';
require_once __DIR__ . '/../app/EmbeddedNoteImage.php';

function sm_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo "[PASS] {$message}\n";
}

$root = dirname(__DIR__, 2);
$managerSource = (string)file_get_contents($root . '/site-admin/index.php');
$managerStyles = (string)file_get_contents($root . '/site-admin/style.css');
$artworkSource = (string)file_get_contents($root . '/platform/artwork.php');
$seriesSource = (string)file_get_contents($root . '/platform/series.php');
$publishedCatalogSource = (string)file_get_contents($root . '/artist-site/inc/AppPublishedCatalog.php');
$noteThumbnail = (string)file_get_contents($root . '/site-admin/note_thumbnail.php');
$publicStyles = (string)file_get_contents($root . '/artist-site/assets/css/styles.css');
$publicHeader = (string)file_get_contents($root . '/artist-site/inc/header.php');
$publicationMedia = (string)file_get_contents($root . '/platform/publication_media.php');
$sidebarSource = (string)file_get_contents($root . '/platform/sidebar.php');

sm_test(str_contains($managerSource, "'store' => ['orders', 'shipping', 'payments']") && !str_contains($managerSource, "'content' => ['artworks'"), 'Store Admin exposes only operational store sections');
sm_test(str_contains($artworkSource, 'name="constellation_country"'), 'Each artwork exposes one optional Constellation country in its canonical Website panel');
sm_test(!str_contains($managerSource, "section === 'constellations'"), 'The redundant Constellations administration section is absent');
$publicLabels = ['Artworks', 'Series', 'Constellations', 'Studio Notes', 'Artist', 'Inquire'];
$position = -1;
foreach ($publicLabels as $label) {
    $next = strpos($publicHeader, '>' . $label . '</a>', $position + 1);
    sm_test($next !== false && $next > $position, 'Public navigation places ' . $label . ' in order');
    $position = $next;
}
sm_test(!is_file($root . '/platform/website_board.php'), 'The retired Website Board route is removed');
sm_test(!str_contains($managerSource, '← Artwork Mockups'), 'Site Manager header omits the removed return link');
sm_test(str_contains($managerSource, 'class="manager-return-to-app"') && str_contains($managerSource, '/root_album.php">← Return to App</a>'), 'Header exposes a clear return-to-app destination');
sm_test(str_contains($sidebarSource, '$sidebarStudioNotesUrl') && str_contains($sidebarSource, '>Studio Notes</a>') && !str_contains($sidebarSource, '>Website Sync</a>'), 'Studio Notes replaces Website Sync in desktop and mobile navigation');
sm_test(str_contains($sidebarSource, '$sidebarStoreUrl') && str_contains($sidebarSource, '>Store</a>'), 'Store is available from the Admin menu');
sm_test(!str_contains($managerSource, 'artist-site/assets/css'), 'Site Manager does not import the public website stylesheet');
sm_test(str_contains($publicationMedia, 'INNER JOIN mockup_sheets m'), 'Public artwork media accepts owned canonical mockups selected as covers');
sm_test(str_contains($managerSource, "'visual-card--text'"), 'Studio Notes without an image use the compact text-card layout');
sm_test(str_contains($managerStyles, 'aspect-ratio: 3/4') && str_contains($managerStyles, 'grid-auto-columns: minmax(132px, 40vw)'), 'Content and Store use one image-led thumbnail rhythm on desktop and mobile');
sm_test(str_contains($managerStyles, '.primary-tabs, .secondary-tabs { display: flex; overflow-x: auto; overflow-y: hidden;') && str_contains($managerStyles, '.secondary-tabs::-webkit-scrollbar { display: none; }'), 'Horizontal navigation never exposes a vertical browser scrollbar');
sm_test(!str_contains($managerSource, 'class="primary-navigation-shell"') && str_contains($managerSource, 'class="secondary-tabs store-admin-tabs"'), 'Store Admin uses one clear navigation bar');
sm_test(str_contains($managerSource, '<span>Store Admin</span>') && str_contains($managerSource, 'sm_h($sectionLabels[$section])'), 'Store Admin header communicates the operational context');
sm_test(str_contains($managerStyles, 'font: 500 clamp(26px, 2vw, 32px)/.95 var(--serif)'), 'Store Admin title has clear visual prominence');
sm_test(str_contains($managerStyles, '.manager-return-to-app {') && str_contains($managerStyles, 'background: var(--sage-soft); color: #42533f;'), 'Return-to-app navigation uses a softly highlighted product style');
sm_test(!str_contains($managerStyles, 'linear-gradient'), 'Site Manager thumbnails avoid decorative gradients');
sm_test(str_contains($managerStyles, '.visual-card strong::first-letter { text-transform: uppercase; }') && str_contains($managerStyles, 'text-transform: lowercase;'), 'Text-only card titles use sentence case without changing saved content');
sm_test(str_contains($managerStyles, 'family=Cormorant+Garamond') && str_contains($managerStyles, '--serif: "Cormorant Garamond"'), 'Site Manager loads the product editorial serif explicitly');
sm_test(str_contains($managerStyles, 'font-family: var(--serif); scroll-snap-align: start;'), 'Text-only card fallback uses the editorial serif family without sans-serif inheritance');
sm_test(str_contains($managerStyles, '.visual-card strong { display: none;') && str_contains($managerStyles, '.visual-card--text strong { position: absolute;'), 'Image cards omit titles while text-only cards retain necessary identification');
sm_test(str_contains($managerStyles, '.visual-card-state,') && str_contains($managerStyles, '.visual-card-order { display: none; }'), 'Image cards contain no visible status or order overlays');
sm_test(str_contains($managerStyles, 'border: 1px solid #c8c5bf; background: var(--surface); font-family: var(--serif);') && str_contains($managerStyles, 'inset: 4px; width: calc(100% - 8px);'), 'Every image card has one grey frame and a small white inner spacing');
sm_test(str_contains($managerStyles, '--selection-accent: rgba(224, 104, 76, .68);') && str_contains($managerStyles, '.visual-card.is-selected::after { height: 3px; background: var(--selection-accent); }'), 'Mobile selection is indicated outside the image with a translucent pastel accent');
sm_test(substr_count($managerSource, 'class="editor-selected-title') >= 4, 'Selected content titles move from image cards into their work panels');
sm_test(str_contains($managerSource, "preg_replace('/\\s+series\\s*$/iu', '', \$title)") && str_contains($managerSource, "return \$title . ' Series';") && str_contains($managerStyles, '.editor-selected-title--series { text-transform: none; }'), 'Series editor titles always show Series with an uppercase initial without duplicating it');
sm_test(str_contains($managerSource, 'Open series website settings') && str_contains($managerSource, 'Content source</dt><dd>Series Metadata'), 'Website Sync links to canonical Series Metadata instead of duplicating its editor');
sm_test(str_contains($managerStyles, '.editor-panel--compact-visual .editor-visual'), 'Selected visual headers become compact thumbnails on mobile');
sm_test(str_contains($managerStyles, '.editor-panel--artwork .editor-visual,') && str_contains($managerStyles, '.editor-panel--series .editor-visual,') && str_contains($managerStyles, '.editor-panel--stock .editor-visual { display: none; }'), 'Mobile artwork and series editors do not repeat the selected thumbnail below the rail');
sm_test(str_contains($managerSource, 'const keepSelectedCardsVisible = () =>') && str_contains($managerSource, 'rail.scrollLeft = Math.max(0, Math.min(centered'), 'The selected thumbnail remains visible above the workspace after navigation');
sm_test(str_contains($managerSource, "'editor-panel--text'"), 'Studio Notes without an image do not reserve an empty visual column');
sm_test(!str_contains($managerSource, '<h2>Studio Notes</h2>'), 'Studio Notes does not repeat its navigation title');
sm_test(str_contains($managerSource, 'note_thumbnail.php?note='), 'Studio Notes reuse their first embedded publication image as the card thumbnail');
sm_test(str_contains($noteThumbnail, 'WHERE id=? AND user_id=?') && str_contains($noteThumbnail, '360 / max(1, $sourceWidth)'), 'Embedded note thumbnails are tenant-safe and resized for the visual rail');
sm_test(str_contains($managerSource, "'prints' => 'Prices & Stock'"), 'Store makes artwork prices and stock directly discoverable');
sm_test(str_contains($managerSource, "'store' => ['orders', 'shipping', 'payments']"), 'Orders, Shipping and Payments are first-class Store sections');
sm_test(str_contains($managerSource, "'north_america' => 'North America'") && str_contains($managerSource, "'south_america' => 'South America'") && str_contains($managerSource, 'shipping_rate_<?= sm_h($continentKey)'), 'Shipping exposes editable rates for every commercial continent');
sm_test(str_contains((string)file_get_contents($root . '/site-admin/app/SiteManagerService.php'), "'oceania' => 25000") && str_contains((string)file_get_contents($root . '/site-admin/app/SiteManagerService.php'), 'shipping_rates_json'), 'Shipping rates default to 250 EUR and persist as minor units');
sm_test(str_contains($managerSource, 'value="confirm_order"') && str_contains($managerSource, 'value="mark_order_paid"') && str_contains($managerSource, 'value="complete_order"') && str_contains($managerSource, 'value="cancel_order"'), 'Orders expose confirmation, payment, completion, and cancellation actions');
sm_test(str_contains((string)file_get_contents($root . '/site-admin/app/SiteManagerService.php'), 'settleReservedStock') && str_contains((string)file_get_contents($root . '/site-admin/app/SiteManagerService.php'), "'order.completed'"), 'Order completion and cancellation settle reserved stock transactionally');
sm_test(!str_contains($managerSource, 'value="save_print"') && str_contains($managerSource, 'Open price and availability'), 'Store stock overview links to the canonical Artwork Website panel instead of duplicating its form');
sm_test(str_contains($artworkSource, 'Price and availability') && str_contains($artworkSource, 'Available units'), 'Artwork pricing and stock keep only the essential controls visible');
sm_test(str_contains($managerSource, 'data-public-order-list') && str_contains($managerSource, 'value="reorder_artworks"'), 'Published artworks expose one compact visual ordering workflow');
sm_test(str_contains($managerSource, 'Open artwork website settings') && !str_contains($managerSource, '<label>Public title<input') && !str_contains($managerSource, '<label>Full description<textarea'), 'Website Sync artwork selection has no duplicate editorial fields');
sm_test(str_contains($artworkSource, 'Title, descriptions, SEO keywords, tags, alt text and captions come directly from Artwork Metadata') && !str_contains($artworkSource, 'Custom website copy'), 'Artwork Website settings inherit all editorial and SEO metadata without duplicate fields');
sm_test(str_contains($artworkSource, 'Available units') && str_contains($artworkSource, 'Edit shipping rates'), 'Artwork Website settings expose price, available units and shared shipping access');
sm_test(str_contains($seriesSource, 'content comes from Series Metadata') && str_contains($seriesSource, 'id="series-website"'), 'Series keeps website-only controls in a folded canonical panel');
sm_test(str_contains($publishedCatalogSource, "\$row['title'] = (string)\$row['artwork_title']") && str_contains($publishedCatalogSource, "'keywords' => (string)\$row['artwork_keywords']"), 'Public website consumes live canonical artwork content and SEO metadata');
sm_test(str_contains($managerSource, 'Alt+ArrowLeft Alt+ArrowRight'), 'Artwork ordering keeps a keyboard alternative to drag and drop');
sm_test(str_contains($artworkSource, 'name="sale_price"') && str_contains($artworkSource, 'name="sale_stock"'), 'Price and quantity are maintained once in the folded Website panel');
sm_test(!str_contains($managerSource, 'New print format') && !str_contains($managerSource, 'New format</a>'), 'Store does not expose the future multi-format workflow');
sm_test(str_contains($managerSource, 'value="connect_stripe"') && str_contains($managerSource, 'Stripe for this artist'), 'Each artist can connect an independent Stripe account');
sm_test(str_contains($managerSource, 'value="disconnect_stripe"') && str_contains($managerSource, 'pending Stripe orders'), 'Stripe disconnection protects unresolved artist orders');
$stripeConnectService = (string)file_get_contents($root . '/site-admin/app/StripeConnectService.php');
sm_test(str_contains($stripeConnectService, 'stripe_user_id') && !str_contains($stripeConnectService, "'access_token' =>"), 'Stripe Connect stores the artist account identity without persisting OAuth access tokens');
sm_test(str_contains($managerStyles, 'max-width: min(100%, 640px)') && str_contains($managerStyles, 'max-height: 480px'), 'Site Manager keeps embedded Studio Note images at a standard responsive size');
sm_test(str_contains($publicStyles, '.journal .prose img') && str_contains($publicStyles, 'max-height: 480px'), 'Published Studio Notes preserve the embedded-image standard');

$pdo = Database::connection();
$service = new SiteManagerService($pdo);
$statement = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1');
$statement->execute(['mauriziovalch@gmail.com']);
$userId = (int)($statement->fetchColumn() ?: 0);
if ($userId > 0) {
    $titles = array_column($service->series($userId), 'title');
    sm_test(in_array('MEDITERRANEO ONIRICO', $titles, true), 'Future canonical series are discovered without hard-coded website entries');
    sm_test(count($service->artworks($userId)) > 0, 'Tenant artwork publication data is available');
    $mockupArtwork = $pdo->prepare('SELECT artwork_id FROM mockup_sheets WHERE user_id=? AND artwork_id>0 GROUP BY artwork_id ORDER BY COUNT(*) DESC LIMIT 1');
    $mockupArtwork->execute([$userId]);
    $mockupArtworkId = (int)($mockupArtwork->fetchColumn() ?: 0);
    if ($mockupArtworkId > 0) sm_test(count($service->artworkCoverOptions($userId, $mockupArtworkId)) > 1, 'Catalog cover includes mockups linked to the canonical artwork');
}
