<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/AppPublishedStudioNotes.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY,email TEXT NOT NULL)');
$pdo->exec("CREATE TABLE social_campaigns (
    id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,campaign_type TEXT NOT NULL,
    title TEXT NOT NULL,objective TEXT NOT NULL,status TEXT NOT NULL,payload_json TEXT NOT NULL,
    source_type TEXT NOT NULL DEFAULT '',source_id TEXT NOT NULL DEFAULT '',
    source_label TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE bilingual_editorial_content (
    user_id INTEGER NOT NULL,entity_type TEXT NOT NULL,entity_id INTEGER NOT NULL,locale TEXT NOT NULL,
    is_published INTEGER NOT NULL,published_content_json TEXT
)");
$pdo->exec('CREATE TABLE mockups (id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL,mockup_file TEXT NOT NULL)');
$pdo->exec("INSERT INTO users VALUES (7,'artist@example.com')");
$imageFile = 'studio-note-7-12-aabbccddeeff00112233.jpg';
$payload = json_encode([
    'channels' => ['website_blog'],
    'media' => [['file' => $imageFile]],
], JSON_UNESCAPED_SLASHES);
$insertNote = $pdo->prepare("INSERT INTO social_campaigns
    (id,user_id,campaign_type,title,objective,status,payload_json,created_at,updated_at)
    VALUES (?,?, 'website_blog',?,?, 'published',?,'2026-07-01','2026-07-25')");
$insertNote->execute([12, 7, 'Legacy English title', '<p>Legacy English body.</p>', $payload]);
$insertNote->execute([13, 7, 'English only', '<p>English legacy content.</p>', $payload]);
$insertLocalized = $pdo->prepare("INSERT INTO bilingual_editorial_content
    (user_id,entity_type,entity_id,locale,is_published,published_content_json)
    VALUES (7,'studio_note',12,?,1,?)");
$insertLocalized->execute(['es', json_encode([
    'title' => 'Donde el pensamiento emerge de la tierra',
    'body_html' => '<p>El territorio nunca es una superficie neutral.</p><img src="/studio_note_media.php?note=12&amp;file=' . $imageFile . '">',
    'slug' => 'donde-el-pensamiento-emerge-de-la-tierra',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
$insertLocalized->execute(['en', json_encode([
    'title' => 'Where Thought Emerges from the Earth',
    'body_html' => '<p>Territory is never a neutral surface.</p>',
    'slug' => 'where-thought-emerges-from-the-earth',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);

$catalog = new AppPublishedStudioNotes($pdo, 'artist@example.com');
$spanish = $catalog->all('es');
$english = $catalog->all('en');
$spanishSlug = 'donde-el-pensamiento-emerge-de-la-tierra-12';
$englishSlug = 'where-thought-emerges-from-the-earth-12';

if (!isset($spanish[$spanishSlug])
    || !isset($english[$englishSlug])
    || (string)$spanish[$spanishSlug]['language_slugs']['en'] !== $englishSlug
    || (array)$spanish[$spanishSlug]['body_media_files'] !== [$imageFile]
    || (array)$english[$englishSlug]['body_media_files'] !== []
    || isset($spanish['english-only-13'])
    || !isset($english['english-only-13'])) {
    fwrite(STDERR, "FAIL: Studio Notes does not preserve localized routes and English-only migration fallback.\n");
    exit(1);
}

echo "PASS: Studio Notes uses published ES/EN snapshots, paired slugs, and safe legacy fallback.\n";
