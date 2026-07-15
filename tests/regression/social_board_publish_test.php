<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/SocialPublishJobService.php';
require_once __DIR__ . '/../../app/Services/MetaPublisher.php';
require_once __DIR__ . '/../../app/Services/InstagramPublisher.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users (id) VALUES (23)');
$jobs = new SocialPublishJobService($pdo);
$key = hash('sha256', 'one-social-publication');
$when = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
$first = $jobs->create(23, 'instagram', 'artist', $when, ['draft_ids' => [11, 12], 'client_key' => 'ig-one'], $key);
$duplicate = $jobs->create(23, 'instagram', 'artist', $when, ['draft_ids' => [99], 'client_key' => 'wrong'], $key);
if ((int)$first['id'] !== (int)$duplicate['id']) {
    fwrite(STDERR, "FAIL: social publication idempotency created a duplicate job.\n");
    exit(1);
}
$claimed = $jobs->claim((int)$first['id']);
if (!$claimed || (string)$claimed['status'] !== 'publishing' || (int)$claimed['attempts'] !== 1) {
    fwrite(STDERR, "FAIL: social publication job could not be claimed.\n");
    exit(1);
}
$jobs->markPublished((int)$first['id'], (string)$claimed['publish_attempt_id'], 'ig-123', 'https://instagram.com/p/test/');
$published = $jobs->job((int)$first['id'], 23);
if ((string)$published['status'] !== 'published' || $jobs->claim((int)$first['id'])['status'] !== 'published') {
    fwrite(STDERR, "FAIL: published social job lost its terminal state.\n");
    exit(1);
}

$draft = [
    'title' => 'New work',
    'description' => 'New work in an architectural context.',
    'destination_url' => 'https://www.saatchiart.com/mauriziovalch',
    'hashtags' => '[]',
    'alt_text' => 'Artwork in a quiet room.',
];
$facebook = MetaPublisher::facebookMultiPhotoPayload($draft, ['photo-1', 'photo-2', 'photo-3']);
$instagram = InstagramPublisher::carouselContainerPayload($draft, ['child-1', 'child-2']);
$instagramChild = InstagramPublisher::carouselItemPayload($draft, 'https://artworkmockups.com/media.jpg');
if (count((array)($facebook['attached_media'] ?? [])) !== 3
    || !str_contains((string)($facebook['message'] ?? ''), 'saatchiart.com/mauriziovalch')
    || ($instagram['media_type'] ?? '') !== 'CAROUSEL'
    || ($instagram['children'] ?? '') !== 'child-1,child-2'
    || ($instagramChild['is_carousel_item'] ?? '') !== 'true') {
    fwrite(STDERR, "FAIL: multi-image Meta payloads do not preserve the board publication model.\n");
    exit(1);
}

$boardJs = (string)file_get_contents(__DIR__ . '/../../social_media_board.js');
$worker = (string)file_get_contents(__DIR__ . '/../../social_publish_worker.php');
if (!str_contains($boardJs, "fetch('social_media_schedule.php'")
    || !str_contains($boardJs, 'data-pin-destination-url')
    || !str_contains($boardJs, 'data-group-link-url')
    || !str_contains($worker, 'publishGroup')) {
    fwrite(STDERR, "FAIL: the board is not connected to the guarded scheduled publisher.\n");
    exit(1);
}

echo "PASS: social board jobs are idempotent and preserve Pin, Instagram carousel and Facebook multi-photo semantics.\n";
