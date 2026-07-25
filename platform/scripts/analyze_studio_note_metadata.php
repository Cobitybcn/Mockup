<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['note-id:']);
$noteId = max(0, (int)($options['note-id'] ?? 0));
if ($noteId <= 0) {
    fwrite(STDERR, "A positive --note-id is required.\n");
    exit(1);
}
if (strtolower(trim(app_env('APP_ENV', ''))) !== 'production') {
    fwrite(STDERR, "Safety stop: this backfill runs only against production.\n");
    exit(1);
}

$pdo = Database::connection();
$note = $pdo->prepare(
    "SELECT user_id,status FROM social_campaigns
     WHERE id=? AND campaign_type='website_blog' LIMIT 1"
);
$note->execute([$noteId]);
$row = $note->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    fwrite(STDERR, "Studio Note not found.\n");
    exit(1);
}

$userId = (int)$row['user_id'];
$editorial = new BilingualEditorialService($pdo);
$adapter = new BilingualEditorialAdapterService($pdo);
$spanishState = $editorial->get($userId, 'studio_note', $noteId, 'es');
$englishState = $editorial->get($userId, 'studio_note', $noteId, 'en');
$spanish = $adapter->completeStudioNoteMetadata(
    $userId,
    $noteId,
    (array)$spanishState['content']
);
$editorial->save($userId, 'studio_note', $noteId, 'es', $spanish);
if (!empty($spanishState['is_published'])) {
    $editorial->setPublished($userId, 'studio_note', $noteId, 'es', true);
}

$englishProposal = $adapter->proposeAdaptationFromContent(
    $userId,
    'studio_note',
    $noteId,
    $spanish,
    (array)$englishState['content']
);
$editorial->save(
    $userId,
    'studio_note',
    $noteId,
    'en',
    (array)$englishProposal['content']
);
if (!empty($englishState['is_published'])) {
    $editorial->setPublished($userId, 'studio_note', $noteId, 'en', true);
}

echo json_encode([
    'ok' => true,
    'note_id' => $noteId,
    'spanish_metadata' => true,
    'english_adapted' => true,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;
