<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/SocialCampaignMetaBridge.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE social_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,status TEXT NOT NULL,
    payload_json TEXT NOT NULL,updated_at TEXT NOT NULL
)');
$payload = [
    'phase' => 'channel_planning',
    'mockup_ids' => [12, 13],
    'channels' => ['meta_media'],
    'channel_status' => ['meta_media' => 'draft'],
];
$pdo->prepare('INSERT INTO social_campaigns (user_id,status,payload_json,updated_at) VALUES (?,?,?,?)')
    ->execute([8, 'draft', json_encode($payload), date('c')]);

$bridge = new SocialCampaignMetaBridge($pdo);
$prepared = $bridge->preparation(1, 8);
$bridge->attachBatch(1, 8, 31, 'artist', ['facebook']);
$linked = $bridge->linkedCampaign(31, 8);
$bridge->markBatchOutcome(31, 8, 'published');
$facebookFinished = $pdo->query('SELECT status,payload_json FROM social_campaigns WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$facebookPayload = json_decode((string)$facebookFinished['payload_json'], true);
$instagramPreparation = $bridge->preparation(1, 8);
$duplicateBlocked = false;
try {
    $bridge->attachBatch(1, 8, 99, 'artist', ['facebook']);
} catch (InvalidArgumentException) {
    $duplicateBlocked = true;
}
$bridge->attachBatch(1, 8, 32, 'artist', ['instagram']);
$instagramLinked = $bridge->linkedCampaign(32, 8);
$duringInstagramReview = $pdo->query('SELECT status FROM social_campaigns WHERE id=1')->fetchColumn();
$bridge->markBatchOutcome(32, 8, 'published');
$finished = $pdo->query('SELECT status,payload_json FROM social_campaigns WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$finishedPayload = json_decode((string)$finished['payload_json'], true);

$checks = [
    $prepared['mockup_ids'] === [12, 13],
    (int)($linked['id'] ?? 0) === 1,
    $facebookFinished['status'] === 'published',
    ($facebookPayload['meta']['batches'][0]['destinations'] ?? []) === ['facebook'],
    $instagramPreparation['available_destinations'] === ['instagram'],
    $duplicateBlocked,
    (int)($instagramLinked['id'] ?? 0) === 1,
    $duringInstagramReview === 'in_progress',
    $finished['status'] === 'published',
    ($finishedPayload['channel_status']['meta_media'] ?? '') === 'published',
    ($finishedPayload['meta']['batch_id'] ?? 0) === 32,
    ($finishedPayload['meta']['destinations'] ?? []) === ['facebook', 'instagram'],
    count((array)($finishedPayload['meta']['batches'] ?? [])) === 2,
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "FAIL: social campaign Meta bridge.\n");
    exit(1);
}

$wrongChannel = $payload;
$wrongChannel['channels'] = ['pinterest'];
$pdo->prepare('INSERT INTO social_campaigns (user_id,status,payload_json,updated_at) VALUES (?,?,?,?)')
    ->execute([8, 'draft', json_encode($wrongChannel), date('c')]);
$blocked = false;
try {
    $bridge->preparation(2, 8);
} catch (RuntimeException) {
    $blocked = true;
}
if (!$blocked) {
    fwrite(STDERR, "FAIL: Meta bridge accepted a campaign without Meta Media.\n");
    exit(1);
}

echo "PASS: social campaign stays linked to Meta review and publication status.\n";
