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
    series_id INTEGER,
    series_creation_number INTEGER,
    medium TEXT NOT NULL,
    artwork_year INTEGER,
    series TEXT NOT NULL,
    width REAL,
    height REAL,
    depth REAL,
    unit TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artwork_groups (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    canonical_artwork_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL
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
    mockup_id INTEGER,
    mockup_file TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL,
    keywords TEXT NOT NULL,
    tags TEXT NOT NULL,
    alt_text TEXT NOT NULL DEFAULT '',
    caption TEXT NOT NULL DEFAULT ''
)");
$pdo->exec("CREATE TABLE mockups (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    mockup_file TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE artwork_series (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    published INTEGER NOT NULL DEFAULT 0,
    year_start INTEGER,
    year_end INTEGER,
    display_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE root_artwork_candidates (
    id INTEGER PRIMARY KEY,
    artwork_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    view_type TEXT NOT NULL
)");
$pdo->exec("CREATE TABLE bilingual_editorial_content (
    user_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    locale TEXT NOT NULL,
    content_json TEXT NOT NULL DEFAULT '{}',
    is_published INTEGER NOT NULL DEFAULT 0,
    published_content_json TEXT,
    PRIMARY KEY (user_id,entity_type,entity_id,locale)
)");
$pdo->exec("CREATE TABLE publication_slug_aliases (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NOT NULL,
    publication_id INTEGER NOT NULL,
    slug TEXT NOT NULL,
    created_at TEXT NOT NULL
)");

$pdo->exec("INSERT INTO users (id,email) VALUES (7,'artist@example.com')");
$pdo->exec("INSERT INTO artwork_series (id,user_id,published,year_start,year_end,display_order,created_at)
    VALUES (81,7,1,2025,2026,10,'2025-01-01'),(82,7,1,2026,2026,20,'2026-01-01')");
$pdo->exec("INSERT INTO artwork_groups (id,user_id,canonical_artwork_id,status,created_at)
    VALUES (71,7,31,'active','2026-01-01'),(72,7,32,'active','2026-02-01')");
$pdo->exec("INSERT INTO artworks (id,user_id,artwork_group_id,series_id,series_creation_number,medium,artwork_year,series,width,height,depth,unit)
    VALUES (31,7,71,81,9,'Oil on canvas',2026,'Strata',120,100,4,'cm')");
$pdo->exec("INSERT INTO artwork_sheets (id,user_id,source_image_file,canonical_artwork_id,subtitle,alt_text,keywords,tags)
    VALUES (41,7,'main.jpg',31,'Subtitle','Alt text','','')");
$pdo->exec("INSERT INTO publications (id,user_id,artwork_sheet_id,status,visibility,published_at,updated_at,display_order,metadata_snapshot_json,slug,header_file)
    VALUES (51,7,41,'published','public','2026-07-20','2026-07-20',20,'{}','test-work','related-cover.jpg')");
$pdo->exec("INSERT INTO artworks (id,user_id,artwork_group_id,series_id,series_creation_number,medium,artwork_year,series,width,height,depth,unit)
    VALUES (32,7,72,82,2,'Oil on canvas',2026,'Strata',80,60,3,'cm')");
$pdo->exec("INSERT INTO artwork_sheets (id,user_id,source_image_file,canonical_artwork_id,subtitle,alt_text,keywords,tags)
    VALUES (42,7,'first.jpg',32,'','','','')");
$pdo->exec("INSERT INTO publications (id,user_id,artwork_sheet_id,status,visibility,published_at,updated_at,display_order,metadata_snapshot_json,slug,header_file)
    VALUES (52,7,42,'published','public','2026-07-01','2026-07-01',10,'{}','first-work','first.jpg')");
$pdo->exec("INSERT INTO artwork_sheets (id,user_id,source_image_file,canonical_artwork_id,subtitle,alt_text,keywords,tags)
    VALUES (43,7,'duplicate.jpg',31,'','','','')");
$pdo->exec("INSERT INTO publications (id,user_id,artwork_sheet_id,status,visibility,published_at,updated_at,display_order,metadata_snapshot_json,slug,header_file)
    VALUES (53,7,43,'published','public','2026-07-21','2026-07-21',1,'{}','test-work-current','related-cover.jpg')");
$pdo->exec("INSERT INTO publication_slug_aliases (id,user_id,publication_id,slug,created_at)
    VALUES (1,7,53,'obra-12','2026-07-24')");
$pdo->exec("INSERT INTO root_artwork_candidates (id,artwork_id,file_name,view_type)
    VALUES (61,31,'detail.jpg','detail')");
$pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_id,artwork_sheet_id,artwork_group_id,mockup_file,title,description,keywords,tags)
    VALUES (62,7,31,41,71,'related-cover.jpg','Favorite context','','','')");
$pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_id,artwork_sheet_id,artwork_group_id,mockup_file,title,description,keywords,tags)
    VALUES (64,7,31,41,71,'related-context.jpg','Second context','','','')");
$pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_id,artwork_sheet_id,artwork_group_id,mockup_file,title,description,keywords,tags)
    VALUES (65,7,32,42,0,'unrelated-context.jpg','Unrelated context','','','')");
$pdo->exec("INSERT INTO mockup_sheets (id,user_id,artwork_id,artwork_sheet_id,artwork_group_id,mockup_file,title,description,keywords,tags)
    VALUES (66,7,31,41,71,'deleted-context.jpg','Deleted context','','','')");
$pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (72,7,'related-cover.jpg')");
$pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (73,7,'related-context.jpg')");
$pdo->exec("INSERT INTO mockups (id,user_id,mockup_file) VALUES (74,7,'unrelated-context.jpg')");
$pdo->exec("INSERT INTO publication_items (id,publication_id,mockup_sheet_id,position,title)
    VALUES (63,51,62,0,'')");
$pdo->exec("INSERT INTO publication_items (id,publication_id,mockup_sheet_id,position,title)
    VALUES (68,53,62,0,'')");
$pdo->exec("INSERT INTO publication_items (id,publication_id,mockup_sheet_id,position,title)
    VALUES (67,51,66,1,'')");
$pdo->exec("INSERT INTO bilingual_editorial_content (user_id,entity_type,entity_id,locale,content_json,is_published,published_content_json)
    VALUES (7,'artwork',31,'es','{\"description\":\"Borrador posterior\"}',1,'{\"description\":\"Texto español aprobado\"}')");

$catalog = (new AppPublishedCatalog($pdo, 'artist@example.com'))->all();
$artwork = $catalog['test-work-current'] ?? null;
$catalogService = new AppPublishedCatalog($pdo, 'artist@example.com');

if (array_keys($catalog) !== ['test-work-current', 'first-work']) {
    fwrite(STDERR, "FAIL: published artworks do not follow the series order defined in the app.\n");
    exit(1);
}
if (!is_array($artwork)
    || ($artwork['artwork_views'][0]['file_name'] ?? '') !== 'detail.jpg'
    || ($artwork['header_file'] ?? '') !== 'related-cover.jpg'
    || array_map(static fn (array $item): int => (int)($item['mockup_id'] ?? 0), $artwork['items']) !== [72, 73]
    || array_map(static fn (array $item): string => (string)($item['title'] ?? ''), $artwork['items']) !== ['Favorite context', 'Second context']) {
    fwrite(STDERR, "FAIL: published catalog cannot use a canonically related mockup as its cover.\n");
    exit(1);
}
if (($catalogService->one('obra-12')['slug'] ?? '') !== 'test-work-current') {
    fwrite(STDERR, "FAIL: legacy artwork slugs do not resolve to the canonical publication.\n");
    exit(1);
}
$englishMockupSlug = (string)($artwork['items'][0]['public_slug_en'] ?? '');
$spanishMockupSlug = (string)($artwork['items'][0]['public_slug_es'] ?? '');
if (!$catalogService->mockup('test-work-current', $englishMockupSlug)
    || !$catalogService->mockup('test-work-current', $spanishMockupSlug)
    || !$catalogService->mockup('obra-12', 'obra-12' . substr($spanishMockupSlug, strlen('test-work-current')))) {
    fwrite(STDERR, "FAIL: mockup routes do not resolve both language slugs and legacy artwork prefixes.\n");
    exit(1);
}
$localization = new AppPublishedLocalization($pdo, 'artist@example.com');
putenv('APP_ENV=production');
if (!$localization->hasPublishedSpanish()
    || ($localization->content('artwork', 31)['description'] ?? '') !== 'Texto español aprobado') {
    fwrite(STDERR, "FAIL: the public language switch does not use the approved Spanish snapshot.\n");
    exit(1);
}
putenv('APP_ENV=local');
if (($localization->content('artwork', 31)['description'] ?? '') !== 'Borrador posterior') {
    fwrite(STDERR, "FAIL: localhost does not expose the current Spanish master for bilingual preview.\n");
    exit(1);
}
putenv('APP_ENV');

echo "PASS: Root Album grouping/order, related covers, production snapshots and local Spanish master preview.\n";
