<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/MetaGraphClient.php';
require_once __DIR__ . '/../../app/Services/InstagramGraphClient.php';
require_once __DIR__ . '/../../app/Services/MetaPublisher.php';
require_once __DIR__ . '/../../app/Services/MetaSocialDraftService.php';
require_once __DIR__ . '/../../app/Services/MetaBatchService.php';

$captured = null;
$client = new MetaGraphClient('v25.0', static function (string $method, string $url, array $fields) use (&$captured): array {
    $captured = compact('method', 'url', 'fields');
    return ['id' => 'ok'];
});
$response = $client->request('POST', '/123/photos', ['caption' => 'Test'], 'token-value', 'secret-value');
if (($response['id'] ?? '') !== 'ok'
    || ($captured['method'] ?? '') !== 'POST'
    || !str_contains((string)($captured['url'] ?? ''), '/v25.0/123/photos')
    || ($captured['fields']['access_token'] ?? '') !== 'token-value'
    || ($captured['fields']['appsecret_proof'] ?? '') !== hash_hmac('sha256', 'token-value', 'secret-value')) {
    fwrite(STDERR, "FAIL: Meta Graph client did not protect or version the request.\n");
    exit(1);
}

$instagramCaptured = null;
$instagramClient = new InstagramGraphClient('v25.0', static function (string $method, string $url, array $fields) use (&$instagramCaptured): array {
    $instagramCaptured = compact('method', 'url', 'fields');
    return ['id' => 'ig-ok'];
});
$instagramResponse = $instagramClient->request('POST', '/456/media', ['image_url' => 'https://example.com/media.jpg'], 'ig-token');
if (($instagramResponse['id'] ?? '') !== 'ig-ok'
    || ($instagramCaptured['method'] ?? '') !== 'POST'
    || !str_contains((string)($instagramCaptured['url'] ?? ''), 'graph.instagram.com/v25.0/456/media')
    || ($instagramCaptured['fields']['access_token'] ?? '') !== 'ig-token') {
    fwrite(STDERR, "FAIL: direct Instagram Graph client did not use the isolated Instagram host and token.\n");
    exit(1);
}

$draft = [
    'title' => 'A new work',
    'description' => 'Studio light and material presence.',
    'destination_url' => 'https://example.com/artwork',
    'hashtags' => json_encode(['#Art', '#Painting']),
    'alt_text' => 'Painting displayed in a quiet studio.',
];
$facebook = MetaPublisher::facebookPayload($draft, 'https://example.com/media.jpg');
$instagram = MetaPublisher::instagramContainerPayload($draft, 'https://example.com/media.jpg');
if (!str_contains((string)$facebook['caption'], 'https://example.com/artwork')
    || !str_contains((string)$facebook['caption'], '#Painting')
    || ($facebook['alt_text_custom'] ?? '') !== $draft['alt_text']
    || !str_contains((string)$instagram['caption'], '#Painting')
    || ($instagram['image_url'] ?? '') !== 'https://example.com/media.jpg') {
    fwrite(STDERR, "FAIL: channel-specific Meta payloads are incomplete.\n");
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE mockups (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,mockup_file TEXT NOT NULL)');
$pdo->exec("CREATE TABLE social_channel_drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,mockup_id INTEGER NOT NULL,channel TEXT NOT NULL,
    purpose TEXT NOT NULL,title TEXT NOT NULL,description TEXT NOT NULL,hashtags TEXT NOT NULL,alt_text TEXT NOT NULL,
    destination_url TEXT NOT NULL,status TEXT NOT NULL,payload_json TEXT NOT NULL,media_token TEXT NOT NULL,
    media_expires_at TEXT,variant_file TEXT NOT NULL,variant_width INTEGER NOT NULL,variant_height INTEGER NOT NULL,
    crop_x REAL NOT NULL,crop_y REAL NOT NULL,crop_zoom REAL NOT NULL,publish_attempt_id TEXT NOT NULL,
    external_id TEXT NOT NULL,external_url TEXT NOT NULL,error TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL
)");
$pdo->exec('CREATE TABLE meta_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL,purpose TEXT NOT NULL,status TEXT NOT NULL,created_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
$pdo->exec('CREATE TABLE meta_batch_items (id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INTEGER NOT NULL,draft_id INTEGER NOT NULL,position INTEGER NOT NULL,status TEXT NOT NULL)');
$pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (5,9,'mockup.jpg')");
$now = date('c');
$insert = $pdo->prepare('INSERT INTO social_channel_drafts
    (user_id,mockup_id,channel,purpose,title,description,hashtags,alt_text,destination_url,status,payload_json,media_token,media_expires_at,variant_file,variant_width,variant_height,crop_x,crop_y,crop_zoom,publish_attempt_id,external_id,external_url,error,created_at,updated_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$base = [9,5,'facebook','artist','Title','Copy','[]','Alt','','draft','{}',str_repeat('a',64),date('c',time()+3600),'meta.jpg',1080,1350,.5,.5,1,'','','','',$now,$now];
$insert->execute($base);
$facebookDraftId = (int)$pdo->lastInsertId();
$base[2] = 'instagram';$base[9] = 'published';$base[19] = str_repeat('b',48);$base[20] = 'ig-1';$base[21] = 'https://instagram.com/p/test/';
$insert->execute($base);
$instagramDraftId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO meta_batches (user_id,purpose,status,created_at,updated_at) VALUES (?,?,?,?,?)')->execute([9,'artist','review',$now,$now]);
$batchId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO meta_batch_items (batch_id,draft_id,position,status) VALUES (?,?,?,?)')->execute([$batchId,$facebookDraftId,0,'draft']);
$pdo->prepare('INSERT INTO meta_batch_items (batch_id,draft_id,position,status) VALUES (?,?,?,?)')->execute([$batchId,$instagramDraftId,1,'published']);

$drafts = new MetaSocialDraftService($pdo);
$attempt = str_repeat('c', 48);
if ($drafts->readiness($drafts->draft($facebookDraftId, 9)) !== []
    || !$drafts->claimForPublishing($facebookDraftId, 9, $attempt)
    || $drafts->claimForPublishing($facebookDraftId, 9, str_repeat('d', 48))) {
    fwrite(STDERR, "FAIL: ready Meta draft could not be claimed.\n");
    exit(1);
}
$drafts->markPublished($facebookDraftId, 9, $attempt, 'fb-1', 'https://facebook.com/test', ['id' => 'fb-1']);
$batches = new MetaBatchService($pdo, $drafts);
if ($batches->updateOutcome($batchId, 9) !== 'published') {
    fwrite(STDERR, "FAIL: Meta batch did not preserve per-channel publication outcomes.\n");
    exit(1);
}

$publishController = (string)file_get_contents(__DIR__ . '/../../meta_batch_publish.php');
if (!str_contains($publishController, 'new InstagramPublisher($instagramIntegration)')
    || !str_contains($publishController, "INSTAGRAM_LIVE_PUBLISH_ENABLED")
    || !str_contains($publishController, "INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED")) {
    fwrite(STDERR, "FAIL: Instagram drafts are not routed through the isolated guarded publisher.\n");
    exit(1);
}

echo "PASS: Facebook and direct Instagram payloads, draft claims and batch outcomes are safe.\n";
