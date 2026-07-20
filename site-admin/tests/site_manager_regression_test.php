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
$noteThumbnail = (string)file_get_contents($root . '/site-admin/note_thumbnail.php');
$websiteBoardStyles = (string)file_get_contents($root . '/platform/website_board.css');
$publicStyles = (string)file_get_contents($root . '/artist-site/assets/css/styles.css');
$publicHeader = (string)file_get_contents($root . '/artist-site/inc/header.php');
$websiteSync = (string)file_get_contents($root . '/platform/website_board.php');
$publicationMedia = (string)file_get_contents($root . '/platform/publication_media.php');

sm_test(str_contains($managerSource, "'content' => ['artworks', 'series', 'studio-notes', 'artist', 'inquire']"), 'Site Manager keeps Constellations inside each artwork instead of a separate form');
sm_test(str_contains($managerSource, 'name="constellation_country"'), 'Each artwork exposes one optional Constellation country');
sm_test(!str_contains($managerSource, "section === 'constellations'"), 'The redundant Constellations administration section is absent');
$publicLabels = ['Artworks', 'Series', 'Constellations', 'Studio Notes', 'Artist', 'Inquire'];
$position = -1;
foreach ($publicLabels as $label) {
    $next = strpos($publicHeader, '>' . $label . '</a>', $position + 1);
    sm_test($next !== false && $next > $position, 'Public navigation places ' . $label . ' in order');
    $position = $next;
}
sm_test(str_contains($websiteSync, 'href="../site-admin/"'), 'Website Sync exposes the separate Site Manager');
sm_test(!str_contains($managerSource, 'artist-site/assets/css'), 'Site Manager does not import the public website stylesheet');
sm_test(str_contains($publicationMedia, 'INNER JOIN mockup_sheets m'), 'Public artwork media accepts owned canonical mockups selected as covers');
sm_test(str_contains($managerSource, "'visual-card--text'"), 'Studio Notes without an image use the compact text-card layout');
sm_test(str_contains($managerSource, "'editor-panel--text'"), 'Studio Notes without an image do not reserve an empty visual column');
sm_test(!str_contains($managerSource, '<h2>Studio Notes</h2>'), 'Studio Notes does not repeat its navigation title');
sm_test(str_contains($managerSource, 'note_thumbnail.php?note='), 'Studio Notes reuse their first embedded publication image as the card thumbnail');
sm_test(str_contains($noteThumbnail, 'WHERE id=? AND user_id=?') && str_contains($noteThumbnail, '360 / max(1, $sourceWidth)'), 'Embedded note thumbnails are tenant-safe and resized for the visual rail');
sm_test(str_contains($managerSource, "'prints' => 'Stock'"), 'Store presents stock instead of print-format administration');
sm_test(str_contains($managerSource, '<summary>Optional sale details</summary>'), 'Secondary sale data stays in a folded disclosure');
sm_test(str_contains($managerSource, 'Available units') && str_contains($managerSource, 'Save stock'), 'Artwork stock keeps only the essential controls visible');
sm_test(str_contains($managerSource, 'data-public-order-list') && str_contains($managerSource, 'value="reorder_artworks"'), 'Published artworks expose one compact visual ordering workflow');
sm_test(str_contains($managerSource, 'Alt+ArrowLeft Alt+ArrowRight'), 'Artwork ordering keeps a keyboard alternative to drag and drop');
$stockDetailsPosition = strpos($managerSource, '<summary>Optional sale details</summary>');
$stockPricePosition = strpos($managerSource, '<label>Price<input inputmode="decimal"');
sm_test($stockPricePosition !== false && $stockDetailsPosition !== false && $stockPricePosition < $stockDetailsPosition, 'Quantity and price stay outside the folded sale details');
sm_test(!str_contains($managerSource, 'New print format') && !str_contains($managerSource, 'New format</a>'), 'Store does not expose the future multi-format workflow');
sm_test(str_contains($managerStyles, 'max-width: min(100%, 640px)') && str_contains($managerStyles, 'max-height: 480px'), 'Site Manager keeps embedded Studio Note images at a standard responsive size');
sm_test(str_contains($websiteBoardStyles, 'max-width: min(100%, 640px)') && str_contains($websiteBoardStyles, 'max-height: 480px'), 'Website Sync uses the same embedded-image standard');
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
