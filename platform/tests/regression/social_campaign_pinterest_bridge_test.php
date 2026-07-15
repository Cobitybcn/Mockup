<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/SocialCampaignPinterestBridge.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE social_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');

$payload = [
    'phase' => 'channel_planning',
    'mockup_ids' => [10, 11],
    'channels' => ['pinterest'],
    'channel_status' => ['pinterest' => 'draft'],
];
$pdo->prepare('INSERT INTO social_campaigns (user_id,status,payload_json,updated_at) VALUES (?,?,?,?)')
    ->execute([7, 'draft', json_encode($payload), date('c')]);

$bridge = new SocialCampaignPinterestBridge($pdo);
$prepared = $bridge->preparation(1, 7);
$bridge->attachBatch(1, 7, 23, 'artist', 'https://example.com/artwork');
$linked = $bridge->linkedCampaign(23, 7);
$bridge->markBatchOutcome(23, 7, 'published');
$finished = $pdo->query('SELECT status,payload_json FROM social_campaigns WHERE id=1')->fetch(PDO::FETCH_ASSOC);
$finishedPayload = json_decode((string)$finished['payload_json'], true);

$checks = [
    $prepared['mockup_ids'] === [10, 11],
    (int)($linked['id'] ?? 0) === 1,
    $finished['status'] === 'published',
    ($finishedPayload['channel_status']['pinterest'] ?? '') === 'published',
    ($finishedPayload['pinterest']['batch_id'] ?? 0) === 23,
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "FAIL: social campaign Pinterest bridge.\n");
    exit(1);
}

$tooMany = $payload;
$tooMany['mockup_ids'] = range(1, 11);
$pdo->prepare('INSERT INTO social_campaigns (user_id,status,payload_json,updated_at) VALUES (?,?,?,?)')
    ->execute([7, 'draft', json_encode($tooMany), date('c')]);
$blocked = false;
try {
    $bridge->preparation(2, 7);
} catch (RuntimeException) {
    $blocked = true;
}
if (!$blocked) {
    fwrite(STDERR, "FAIL: Pinterest campaign accepted more than 10 mockups.\n");
    exit(1);
}

echo "PASS: social campaign stays linked to Pinterest review and publication status.\n";
