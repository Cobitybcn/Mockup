<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/AppPublishedCatalog.php';

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE publications (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    artwork_sheet_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    visibility TEXT NOT NULL,
    published_at TEXT,
    updated_at TEXT NOT NULL,
    display_order INTEGER NOT NULL DEFAULT 0,
    metadata_snapshot_json TEXT NOT NULL,
    slug TEXT NOT NULL,
    header_file TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artwork_sheets (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    source_image_file TEXT NOT NULL,
    canonical_artwork_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT 'Test Work',
    subtitle TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT 'Full canonical description',
    short_description TEXT NOT NULL DEFAULT 'Canonical summary',
    caption TEXT NOT NULL DEFAULT 'Canonical caption',
    generated_json TEXT NOT NULL DEFAULT '{}',
    alt_text TEXT NOT NULL,
    keywords TEXT NOT NULL,
    tags TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artworks (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    artwork_group_id INTEGER NOT NULL DEFAULT 0,
    medium TEXT NOT NULL,
    artwork_year INTEGER,
    series TEXT NOT NULL,
    width REAL,
    height REAL,
    depth REAL,
    unit TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE publication_items (
    id INTEGER PRIMARY KEY,
    publication_id INTEGER NOT NULL,
    mockup_sheet_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    title TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE mockup_sheets (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    artwork_id INTEGER NOT NULL,
    artwork_sheet_id INTEGER NOT NULL,
    artwork_group_id INTEGER NOT NULL DEFAULT 0,
    mockup_file TEXT NOT NULL,
    description TEXT NOT NULL,
    keywords TEXT NOT NULL,
    tags TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE root_artwork_candidates (
    id INTEGER PRIMARY KEY,
    artwork_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    view_type TEXT NOT NULL
)");

$pdo->exec("INSERT INTO users (id,email) VALUES (7,'artist@example.com')");
$pdo->exec("INSERT INTO artworks (id,user_id,artwork_group_id,medium,artwork_year,series,width,height,depth,unit)
    VALUES (31,7,71,'Oil on canvas',2026,'Strata',120,100,4,'cm')");
$pdo->exec("INSERT INTO artwork_sheets (id,user_id,source_image_file,canonical_artwork_id,subtitle,alt_text,keywords,tags)
    VALUES (41,7,'main.jpg',31,'Subtitle','Alt text','','')");
$pdo->exec("INSERT INTO publications (id,user_id,artwork_sheet_id,status,visibility,published_at,updated_at,display_order,metadata_snapshot_json,slug,header_file)
    VALUES (51,7,41,'published','public','2026-07-20','2026-07-20',20,'{}','test-work','related-cover.jpg')");
$pdo->exec("INSERT INTO artworks (id,user_id,artwork_group_id,medium,artwork_year,series,width,height,depth,unit)
    VALUES (32,7,0,'Oil on canvas',2026,'Strata',80,60,3,'cm')");
$pdo->exec("INSERT INTO artwork_sheets (id,user_id,source_image_file,canonical_artwork_id,subtitle,alt_text,keywords,tags)
    VALUES (42,7,'first.jpg',32,'','','','')");
$pdo->exec("INSERT INTO publications (id,user_id,artwork_sheet_id,status,visibility,published_at,updated_at,display_order,metadata_snapshot_json,slug,header_file)
    VALUES (52,7,42,'published','public','2026-07-01','2026-07-01',10,'{}','first-work','first.jpg')");
$pdo->exec("INSERT INTO root_artwork_candidates (id,artwork_id,file_name,view_type)
    VALUES (61,31,'detail.jpg','detail')");
$pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_id,artwork_sheet_id,artwork_group_id,mockup_file,description,keywords,tags)
    VALUES (62,7,31,41,71,'related-cover.jpg','','','')");

$catalog = (new AppPublishedCatalog($pdo, 'artist@example.com'))->all();
$artwork = $catalog['test-work'] ?? null;

if (array_keys($catalog) !== ['first-work', 'test-work']) {
    fwrite(STDERR, "FAIL: published catalog does not respect manual display order.\n");
    exit(1);
}
if (!is_array($artwork)
    || ($artwork['artwork_views'][0]['file_name'] ?? '') !== 'detail.jpg'
    || ($artwork['header_file'] ?? '') !== 'related-cover.jpg') {
    fwrite(STDERR, "FAIL: published catalog cannot use a canonically related mockup as its cover.\n");
    exit(1);
}

echo "PASS: published catalog respects manual order and accepts canonical artwork views and related mockup covers.\n";
