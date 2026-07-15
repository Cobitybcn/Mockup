<?php
declare(strict_types=1);

final class ArtistProfile
{
    public static function findForUser(int $userId): array
    {
        return ['user_id' => $userId, 'artist_name' => 'Test Artist'];
    }
}

require_once __DIR__ . '/../../app/Services/PublicationService.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE artwork_sheets (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, source_image_file TEXT NOT NULL,
    title TEXT NOT NULL, subtitle TEXT NOT NULL, description TEXT NOT NULL,
    short_description TEXT NOT NULL, keywords TEXT NOT NULL, tags TEXT NOT NULL,
    alt_text TEXT NOT NULL, caption TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE mockup_sheets (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_sheet_id INTEGER NOT NULL,
    mockup_file TEXT NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL,
    keywords TEXT NOT NULL, tags TEXT NOT NULL, alt_text TEXT NOT NULL, caption TEXT NOT NULL
)");
$pdo->exec("INSERT INTO artwork_sheets VALUES (1,7,'root.jpg','Test Work','Subtitle','Curatorial description','Short description','art, test','abstract','Original artwork','Test Work caption')");
$pdo->exec("INSERT INTO mockup_sheets VALUES (11,7,1,'mockup.jpg','Test Pin','Pin description','interior, art','art','Accessible mockup description','Caption')");

$service = new PublicationService($pdo);
$publicationId = $service->createForSheet(1, 7);
$service->save($publicationId, 7, [
    'title' => 'Test Work', 'description' => 'Curatorial description',
    'short_description' => 'Short description', 'language' => 'en',
    'objective' => 'portfolio', 'cta_label' => 'Enquire', 'cta_url' => 'https://example.com/contact',
    'visibility' => 'public', 'publish' => true,
], [11]);
$jobId = $service->savePinterestDraft($publicationId, 7, 11, 'Contemporary Art', 'https://example.com/work');
$publication = $service->get($publicationId, 7);
$public = $service->publicBySlug((string)$publication['slug']);

$checks = [
    $publicationId > 0,
    $jobId > 0,
    $publication['status'] === 'published',
    count($publication['items']) === 1,
    $public['title'] === 'Test Work',
    count($publication['variants']) === 4,
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Publication service smoke test failed.\n");
    exit(1);
}

echo "PASS: publication service create, select mockup, publish landing and save Pinterest draft.\n";
