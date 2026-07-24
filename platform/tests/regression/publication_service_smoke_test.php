<?php
declare(strict_types=1);

final class ArtistProfile
{
    public static function findForUser(int $userId): array
    {
        return ['user_id' => $userId, 'artist_name' => 'Test Artist'];
    }
}

final class Database
{
    public static function isMysql(): bool
    {
        return false;
    }
}

final class MockupFavorites
{
    public static function idsForUser(int $userId): array
    {
        return $userId === 7 ? [71] : [];
    }
}

require_once __DIR__ . '/../../app/Services/PublicationService.php';
require_once __DIR__ . '/../../app/Support/ArtworkSeries.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE artwork_sheets (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, canonical_artwork_id INTEGER NOT NULL, source_image_file TEXT NOT NULL,
    title TEXT NOT NULL, subtitle TEXT NOT NULL, description TEXT NOT NULL,
    short_description TEXT NOT NULL, keywords TEXT NOT NULL, tags TEXT NOT NULL,
    alt_text TEXT NOT NULL, caption TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artworks (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, series_id INTEGER,
    series TEXT NOT NULL DEFAULT '', series_creation_number INTEGER,
    updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artwork_series (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, title TEXT NOT NULL,
    slug TEXT NOT NULL, description TEXT, status TEXT NOT NULL,
    header_file TEXT NOT NULL DEFAULT '', published INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE mockups (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, source_artwork_id INTEGER,
    artwork_file TEXT NOT NULL DEFAULT '', artwork_group_id INTEGER,
    mockup_file TEXT NOT NULL DEFAULT ''
)");
$pdo->exec("CREATE TABLE mockup_sheets (
    id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, artwork_sheet_id INTEGER NOT NULL,
    mockup_id INTEGER,
    mockup_file TEXT NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL,
    keywords TEXT NOT NULL, tags TEXT NOT NULL, alt_text TEXT NOT NULL, caption TEXT NOT NULL
)");
$pdo->exec("INSERT INTO artwork_series (id,user_id,title,slug,description,status,header_file,published,created_at,updated_at) VALUES
    (1,7,'Published Series','published-series','Ready','active','cover.jpg',1,'2026-07-20','2026-07-20'),
    (2,7,'Draft Series','draft-series','','active','',0,'2026-07-20','2026-07-20')");
$pdo->exec("INSERT INTO artworks VALUES
    (101,7,1,'Published Series',10,'2026-07-20'),
    (102,7,2,'Draft Series',10,'2026-07-20')");
$pdo->exec("INSERT INTO artwork_sheets VALUES
    (1,7,101,'root.jpg','Test Work','Subtitle','Curatorial description','Short description','art, test','abstract','Original artwork','Test Work caption'),
    (2,7,102,'draft-root.jpg','Draft Series Work','Subtitle','Description','Short','art','abstract','Artwork','Caption')");
$pdo->exec("INSERT INTO mockups (id,user_id,source_artwork_id,artwork_file,artwork_group_id,mockup_file) VALUES (71,7,101,'root.jpg',NULL,'favorite.jpg')");
$pdo->exec("INSERT INTO mockup_sheets VALUES
    (11,7,1,NULL,'mockup.jpg','Test Pin','Pin description','interior, art','art','Accessible mockup description','Caption'),
    (12,7,1,NULL,'favorite.jpg','Favorite Pin','Favorite description','favorite interior','favorite','Favorite accessible description','Favorite caption')");
$service = new PublicationService($pdo);
$publicationId = $service->createForSheet(1, 7);
$favoriteWebsitePublication = $service->saveWebsiteSettings(1, 7, ['visibility' => 'public'], 'save');
$favoriteWebsiteItems = $favoriteWebsitePublication['items'];
$favoriteSheetMockupId = (int)$pdo->query('SELECT COALESCE(mockup_id,0) FROM mockup_sheets WHERE id=12')->fetchColumn();
$service->save($publicationId, 7, [
    'title' => 'Test Work', 'description' => 'Curatorial description',
    'short_description' => 'Short description', 'language' => 'en',
    'objective' => 'portfolio', 'cta_label' => 'Enquire', 'cta_url' => 'https://example.com/contact',
    'visibility' => 'public', 'publish' => true,
], [11]);
$jobId = $service->savePinterestDraft($publicationId, 7, 11, 'Contemporary Art', 'https://example.com/work');
$publication = $service->get($publicationId, 7);
$public = $service->publicBySlug((string)$publication['slug']);
$pdo->exec("UPDATE artwork_sheets SET title='Canonical Updated',description='Updated canonical description',short_description='Updated summary' WHERE id=1");
$service->syncInheritedFromSheet(1, 7);
$inheritedPublication = $service->get($publicationId, 7);
$inheritedPublic = $service->publicBySlug((string)$inheritedPublication['slug']);

$blockedPublicationId = $service->createForSheet(2, 7);
$publishBlocked = false;
try {
    $service->save($blockedPublicationId, 7, ['visibility' => 'public', 'publish' => true]);
} catch (RuntimeException $e) {
    $publishBlocked = str_contains($e->getMessage(), 'Draft Series');
}
$blockedPublication = $service->get($blockedPublicationId, 7);

$unpublishSeriesBlocked = false;
try {
    ArtworkSeries::setPublished($pdo, 7, 1, false);
} catch (RuntimeException $e) {
    $unpublishSeriesBlocked = str_contains($e->getMessage(), '1 obra publicada');
}

$moveToDraftSeriesBlocked = false;
try {
    ArtworkSeries::assignArtwork($pdo, 7, 101, 2, false);
} catch (RuntimeException $e) {
    $moveToDraftSeriesBlocked = str_contains($e->getMessage(), 'Draft Series');
}

$pdo->exec("UPDATE artwork_series SET header_file='draft-cover.jpg', long_description='Opening paragraph for the series.\n\nAdditional curatorial detail.' WHERE id=2");
ArtworkSeries::setPublished($pdo, 7, 2, true);
$derivedSeries = $pdo->query('SELECT description,published FROM artwork_series WHERE id=2')->fetch(PDO::FETCH_ASSOC);

$checks = [
    $publicationId > 0,
    count($favoriteWebsiteItems) === 1,
    (int)$favoriteWebsiteItems[0]['mockup_sheet_id'] === 12,
    $favoriteSheetMockupId === 71,
    $jobId > 0,
    $publication['status'] === 'published',
    count($publication['items']) === 1,
    $public['title'] === 'Test Work',
    $inheritedPublication['title'] === 'Canonical Updated',
    $inheritedPublication['slug'] === 'canonical-updated',
    $inheritedPublic['short_description'] === 'Updated summary',
    count($publication['variants']) === 4,
    $publishBlocked,
    $blockedPublication['status'] === 'draft',
    $unpublishSeriesBlocked,
    $moveToDraftSeriesBlocked,
    $derivedSeries['description'] === 'Opening paragraph for the series.',
    (int)$derivedSeries['published'] === 1,
];

if (in_array(false, $checks, true)) {
    fwrite(STDERR, "Publication service smoke test failed.\n");
    exit(1);
}

echo "PASS: publication and series synchronization invariants.\n";
