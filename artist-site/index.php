<?php
require __DIR__ . '/inc/LocalEnv.php';
load_local_env(dirname(__DIR__) . '/platform/.env');
load_local_env(__DIR__ . '/.env');
$artistAutoload = __DIR__ . '/vendor/autoload.php';
$platformAutoload = dirname(__DIR__) . '/platform/vendor/autoload.php';
if (is_file($artistAutoload)) require_once $artistAutoload;
elseif (is_file($platformAutoload)) require_once $platformAutoload;
require __DIR__ . '/data/site.php';
require __DIR__ . '/inc/functions.php';
require __DIR__ . '/inc/AppDatabase.php';
require __DIR__ . '/inc/TenantResolver.php';
require __DIR__ . '/inc/AppPublishedLocalization.php';
require __DIR__ . '/inc/AppPublishedCatalog.php';
require __DIR__ . '/inc/AppPublishedSeriesCatalog.php';
require __DIR__ . '/inc/AppPublishedArtistProfile.php';
require __DIR__ . '/inc/AppArtistReferences.php';
require __DIR__ . '/inc/AppPublishedStudioNotes.php';
require __DIR__ . '/inc/AppPublishedSiteSettings.php';
require __DIR__ . '/inc/AppStore.php';
require __DIR__ . '/inc/StripeCheckout.php';
require __DIR__ . '/inc/ArtistContactMailer.php';
require __DIR__ . '/inc/ArtistSitemapCache.php';

$requestPath = current_path();
$pathLanguage = artist_site_path_language($requestPath);
$path = artist_site_path_without_language($requestPath);
$segments = array_values(array_filter(explode('/', trim($path, '/'))));
$siteLanguage = artist_site_language();
$sitemapCache = null;
$staleSitemap = null;
if ($path === '/sitemap.xml') {
    $sitemapCache = ArtistSitemapCache::forRequest();
    $freshSitemap = $sitemapCache->fresh();
    if ($freshSitemap !== null) ArtistSitemapCache::emit($freshSitemap, 'hit');
    $staleSitemap = $sitemapCache->stale();
    try {
        // Abort an unexpectedly expensive read before Cloud Run's 60-second
        // request deadline so an existing stale sitemap can still be served.
        app_admin_pdo()->exec('SET SESSION MAX_EXECUTION_TIME=10000');
    } catch (Throwable $error) {
        error_log('Sitemap database deadline unavailable: ' . $error->getMessage());
    }
}

$isPublicDocument = !in_array(($segments[0] ?? ''), ['admin', 'admin-v2', 'api'], true)
    && !in_array($path, ['/sitemap.xml', '/robots.txt'], true);
if ($isPublicDocument && in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET', 'HEAD'], true)) {
    $canonicalRequest = artist_site_url_with_language((string)($_SERVER['REQUEST_URI'] ?? '/'), $siteLanguage);
    $currentRequest = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($pathLanguage === '' || isset($_GET['lang'])) {
        if ($canonicalRequest !== $currentRequest) {
            header('Location: ' . $canonicalRequest, true, 301);
            exit;
        }
    }
}

$resolvedArtistEmail = '';
try {
    $resolvedArtistEmail = resolved_artist_email();
} catch (Throwable $error) {
    if ($staleSitemap !== null) ArtistSitemapCache::emit($staleSitemap, 'stale');
    http_response_code(503);
    header('Retry-After: 60');
    exit('Artist site configuration is temporarily unavailable.');
}

// First-party, privacy-clean visitor metrics beacon (no cookies, no IP, no UA).
if (($segments[0] ?? '') === 'site-event' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    header('Cache-Control: no-store');
    $eventInput = json_decode((string)file_get_contents('php://input'), true);
    $eventType = is_array($eventInput) ? (string)($eventInput['type'] ?? '') : '';
    if (in_array($eventType, ['view', 'saatchi_click', 'acquire_click', 'inquire_click', 'share_click', 'language_switch', 'social_outbound', 'search'], true)) {
        app_record_site_event(
            $eventType,
            (string)($eventInput['entity'] ?? ''),
            (string)($eventInput['slug'] ?? ''),
            (string)($eventInput['locale'] ?? ''),
            (string)($eventInput['referrer'] ?? ''),
            (string)($eventInput['detail'] ?? '')
        );
    }
    http_response_code(204);
    exit;
}

if ($path === '/sitemap.xml') {
    $site['url'] = rtrim(app_absolute_url('/'), '/');
} else {
    try {
        $managedSiteSettings = AppPublishedSiteSettings::fromApp(dirname(__DIR__) . '/platform', $resolvedArtistEmail)->get();
        if (trim((string)($managedSiteSettings['site_title'] ?? '')) !== '') $site['name'] = trim((string)$managedSiteSettings['site_title']);
        if (trim((string)($managedSiteSettings['tagline'] ?? '')) !== '') $site['tagline'] = trim((string)$managedSiteSettings['tagline']);
        if (trim((string)($managedSiteSettings['contact_email'] ?? '')) !== '') $site['email'] = trim((string)$managedSiteSettings['contact_email']);
        $site['inquiry_intro'] = trim((string)($managedSiteSettings['inquiry_intro'] ?? ''));
        $site['url'] = rtrim(app_absolute_url('/'), '/');
    } catch (Throwable $error) {
        error_log('Artist Site Manager settings unavailable: ' . $error->getMessage());
    }
}
$site['tagline'] = site_copy('global.tagline');
$site['description'] = site_copy('global.description');

if ($path === '/sitemap.xml') {
    try {
        $routes = [];
        $addLocalizedRoute = static function (string $english, ?string $spanish = null) use (&$routes): void {
            $routes[$english . "\n" . ($spanish ?? $english)] = [
                'en' => $english,
                'es' => $spanish ?? $english,
            ];
        };
        foreach (['/', '/artworks/', '/sold-works/', '/series/', '/artist/', '/artist-statement/', '/studio-process/', '/exhibitions-collections/', '/studio-notes/', '/contact/', '/privacy-policy/'] as $route) {
            $addLocalizedRoute($route);
        }
        foreach (array_keys($journal) as $slug) {
            $addLocalizedRoute('/studio-notes/' . $slug . '/');
        }
        // Reuse the already opened, deadline-bound connection. The normal page
        // catalogs keep their independent request lifecycle.
        $sitemapPdo = app_admin_pdo();
        $GLOBALS['artist_site_sitemap_pdo'] = $sitemapPdo;
        foreach (array_keys(app_series_catalog()?->all() ?? []) as $slug) {
            $addLocalizedRoute('/series/' . $slug . '/');
        }
        $publishedCatalog = new AppPublishedCatalog($sitemapPdo, $resolvedArtistEmail);
        foreach ($publishedCatalog->all() as $slug => $publishedArtwork) {
            if ($publishedArtwork['visibility'] !== 'public') continue;
            $addLocalizedRoute('/artworks/' . $slug . '/');
            if (is_array($publishedArtwork['video'] ?? null)) {
                $addLocalizedRoute('/artworks/' . $slug . '/video/');
            }
            foreach ($publishedArtwork['items'] as $mockup) {
                $addLocalizedRoute(
                    '/artworks/' . $slug . '/mockups/' . $mockup['public_slug_en'] . '/',
                    '/artworks/' . $slug . '/mockups/' . $mockup['public_slug_es'] . '/'
                );
            }
        }
        $studioNotesCatalog = new AppPublishedStudioNotes($sitemapPdo, $resolvedArtistEmail);
        $englishNotes = $studioNotesCatalog->all('en');
        $spanishNotesById = [];
        foreach ($studioNotesCatalog->all('es') as $spanishSlug => $spanishNote) {
            $spanishNotesById[(int)$spanishNote['id']] = $spanishSlug;
        }
        foreach ($englishNotes as $englishSlug => $englishNote) {
            $spanishSlug = (string)($spanishNotesById[(int)$englishNote['id']] ?? '');
            if ($spanishSlug === '') continue;
            $addLocalizedRoute(
                '/studio-notes/' . $englishSlug . '/',
                '/studio-notes/' . $spanishSlug . '/'
            );
        }
        $sitemapLocales = app_publication_locales();
        $urls = [];
        foreach ($routes as $route) {
            foreach (['en', 'es'] as $locale) {
                if (!in_array($locale, $sitemapLocales, true)) continue;
                $urls[] = artist_site_url_with_language($site['url'] . $route[$locale], $locale);
            }
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $url) {
            $xml .= "  <url><loc>" . e($url) . "</loc></url>\n";
        }
        $xml .= '</urlset>';
        if (!$sitemapCache?->store($xml)) {
            error_log('The generated sitemap could not be cached.');
        }
        ArtistSitemapCache::emit($xml, 'miss');
    } catch (Throwable $error) {
        error_log('Sitemap generation unavailable: ' . $error->getMessage());
        if ($staleSitemap !== null) ArtistSitemapCache::emit($staleSitemap, 'stale');
        http_response_code(503);
        header('Retry-After: 60');
        exit('Sitemap generation is temporarily unavailable.');
    }
}

$meta = page_meta(
    'Maurizio Valch | Structural Metaphysical Painting',
    $site['description'],
    $site['url'] . $path,
    '/assets/images/the-path-before-architecture.jpg'
);
$bodyClass = trim($segments[0] ?? 'home');
if ($bodyClass === 'studio-notes') {
    $bodyClass = 'journal';
}

if (($segments[0] ?? '') === 'admin') {
    if (getenv('K_SERVICE') !== false || strtolower((string)(getenv('APP_ENV') ?: '')) === 'production') {
        $adminBase = rtrim((string)(getenv('ARTIST_ADMIN_URL') ?: getenv('ARTWORKMOCKUPS_PUBLIC_URL') ?: ''), '/');
        if ($adminBase === '') {
            http_response_code(404);
            exit;
        }
        if (!str_ends_with($adminBase, '/site-admin')) $adminBase .= '/site-admin';
        header('Location: ' . $adminBase . '/', true, 302);
        exit;
    }
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
    if (($segments[0] ?? '') === 'admin' && empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if (($segments[0] ?? '') === 'acquire' && empty($_SESSION['purchase_csrf'])) {
        $_SESSION['purchase_csrf'] = bin2hex(random_bytes(32));
    }
} elseif (in_array(($segments[0] ?? ''), ['acquire', 'payment'], true)) {
    session_set_cookie_params([
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
    if (($segments[0] ?? '') === 'acquire' && empty($_SESSION['purchase_csrf'])) {
        $_SESSION['purchase_csrf'] = bin2hex(random_bytes(32));
    }
}

function admin_password_file(): string
{
    return __DIR__ . '/data/admin-password.json';
}



function admin_is_configured(): bool
{
    return is_file(admin_password_file());
}

function admin_is_logged_in(): bool
{
    return !empty($_SESSION['admin_ok']);
}


function admin_content_snapshot(array $site, array $series, array $artworks, array $soldRecords, array $soldLocations, array $journal): array
{
    return [
        'settings' => [
            'hero_image' => $site['hero_image'] ?? '/assets/images/the-path-before-architecture.jpg',
            'artist_profile' => artist_profile_defaults($site['artist_profile'] ?? []),
        ],
        'series' => $series,
        'artworks' => $artworks,
        'sold_records' => $soldRecords,
        'sold_locations' => $soldLocations,
        'studioNotes' => $journal,
    ];
}




function admin_country_options(): array
{
    return [
        '' => 'Select country',
        'Argentina' => 'Argentina',
        'Australia' => 'Australia',
        'Austria' => 'Austria',
        'Belgium' => 'Belgium',
        'Brazil' => 'Brazil',
        'Canada' => 'Canada',
        'Chile' => 'Chile',
        'Colombia' => 'Colombia',
        'Bulgaria' => 'Bulgaria',
        'Denmark' => 'Denmark',
        'France' => 'France',
        'Germany' => 'Germany',
        'Greece' => 'Greece',
        'Italy' => 'Italy',
        'Mexico' => 'Mexico',
        'Netherlands' => 'Netherlands',
        'Norway' => 'Norway',
        'Portugal' => 'Portugal',
        'Spain' => 'Spain',
        'Sweden' => 'Sweden',
        'Switzerland' => 'Switzerland',
        'United Kingdom' => 'United Kingdom',
        'United States' => 'United States',
        'Uruguay' => 'Uruguay',
    ];
}

function admin_content_file(): string
{
    return __DIR__ . '/data/content.json';
}

function admin_save_content(array $content): void
{
    $dataPath = __DIR__ . '/data';
    file_put_contents(admin_content_file(), json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    if (isset($content['settings'])) {
        file_put_contents($dataPath . '/settings.json', json_encode($content['settings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (isset($content['sold_records'])) {
        file_put_contents($dataPath . '/sold-records.json', json_encode($content['sold_records'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (isset($content['sold_locations'])) {
        file_put_contents($dataPath . '/sold-locations.json', json_encode($content['sold_locations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $folders = [
        'series' => $dataPath . '/series',
        'artworks' => $dataPath . '/artworks',
        'studioNotes' => $dataPath . '/studio-notes'
    ];
    foreach ($folders as $key => $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $existingFiles = glob($dir . '/*.json');
        if ($existingFiles) {
            foreach ($existingFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        $items = $content[$key] ?? [];
        if ($key === 'studioNotes' && !$items) {
            $items = $content['studio-notes'] ?? $content['journal'] ?? [];
        }
        if (is_array($items)) {
            foreach ($items as $slug => $data) {
                if (!empty($slug)) {
                    file_put_contents($dir . '/' . $slug . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }
}

function admin_clean_field(string $value): string
{
    $value = trim($value);
    if (str_contains($value, 'Warning') || str_contains($value, 'Undefined array key')) {
        return '';
    }
    return $value;
}

function admin_dimension_parts(string $value): array
{
    preg_match_all('/\d+(?:[.,]\d+)?/', $value, $matches);
    $numbers = array_map(fn ($number) => str_replace(',', '.', $number), $matches[0] ?? []);
    return [
        'width' => $numbers[0] ?? '',
        'height' => $numbers[1] ?? '',
        'depth' => $numbers[2] ?? '',
    ];
}

function admin_format_dimension_number(float $value): string
{
    $rounded = round($value);
    return abs($value - $rounded) < 0.05 ? (string) $rounded : rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
}

function admin_format_dimensions(array $parts, string $unit): string
{
    $values = array_values(array_filter([
        $parts['width'] ?? '',
        $parts['height'] ?? '',
        $parts['depth'] ?? '',
    ], fn ($value) => $value !== '' && is_numeric($value)));
    if (!$values) {
        return '';
    }
    $formatted = array_map(fn ($value) => admin_format_dimension_number((float) $value), $values);
    return implode(' x ', $formatted) . ' ' . $unit;
}

function admin_upload_image(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return '';
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return '';
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']) ?: '';
    }

    if (!isset($allowed[$mime])) {
        return '';
    }

    $dir = __DIR__ . '/assets/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $name = admin_slug(pathinfo($file['name'], PATHINFO_FILENAME));
    $name = $name ?: 'artwork';
    $filename = $name . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $allowed[$mime];
    $target = $dir . '/' . $filename;

    $success = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $target) : move_uploaded_file($file['tmp_name'], $target);
    if (!$success) {
        return '';
    }

    return '/assets/uploads/' . $filename;
}

function admin_map_locations_by_artwork(array $soldLocations): array
{
    $locations = [];
    foreach ($soldLocations as $location) {
        if (!empty($location['artwork_slug'])) {
            $locations[$location['artwork_slug']] = $location;
        }
    }
    return $locations;
}

function admin_handle_post(array &$site, array &$series, array &$artworks, array &$soldRecords, array &$soldLocations, array &$journal): void
{
    if (($GLOBALS['segments'][0] ?? '') !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';

    if ($action !== 'login' && $action !== 'setup_password' && $action !== 'logout') {
        $token = $_POST['csrf_token'] ?? '';
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            http_response_code(403);
            echo 'CSRF token validation failed.';
            exit;
        }
    }

    if ($action === 'setup_password') {
        $password = trim($_POST['password'] ?? '');
        if (strlen($password) >= 12 && !admin_is_configured()) {
            file_put_contents(admin_password_file(), json_encode(['hash' => password_hash($password, PASSWORD_DEFAULT)]));
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
        }
        header('Location: ' . url_for('admin'));
        exit;
    }

    if ($action === 'login') {
        $config = json_decode((string) file_get_contents(admin_password_file()), true);
        if (!empty($config['hash']) && password_verify($_POST['password'] ?? '', $config['hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_ok'] = true;
        }
        header('Location: ' . url_for('admin'));
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . url_for('admin'));
        exit;
    }

    if (!admin_is_logged_in()) {
        header('Location: ' . url_for('admin'));
        exit;
    }

    $content = admin_content_snapshot($site, $series, $artworks, $soldRecords, $soldLocations, $journal);
    $savedSection = 'catalog';
    $savedParams = [];

    if ($action === 'save_home') {
        $savedSection = 'home';
        $heroImage = admin_upload_image($_FILES['hero_image_upload'] ?? []) ?: trim($_POST['hero_image'] ?? ($content['settings']['hero_image'] ?? ''));
        if ($heroImage !== '') {
            $content['settings']['hero_image'] = $heroImage;
        }
    }

    if ($action === 'save_artist_profile') {
        $savedSection = 'artist';
        $existing = artist_profile_defaults($content['settings']['artist_profile'] ?? []);
        $portraitImage = admin_upload_image($_FILES['portrait_upload'] ?? []) ?: trim($_POST['portrait_image'] ?? ($existing['portrait']['image'] ?? ''));
        $ogImage = admin_upload_image($_FILES['og_image_upload'] ?? []) ?: trim($_POST['seo_og_image'] ?? ($existing['seo']['og_image'] ?? ''));

        $studioImages = [];
        $existingStudio = $existing['studio_images'] ?? [];
        $studioCount = max(
            count($_POST['studio_existing'] ?? []),
            count($_POST['studio_alt'] ?? []),
            count($_FILES['studio_image_uploads']['name'] ?? [])
        );
        for ($index = 0; $index < $studioCount; $index++) {
            $studioUpload = [
                'name' => $_FILES['studio_image_uploads']['name'][$index] ?? '',
                'type' => $_FILES['studio_image_uploads']['type'][$index] ?? '',
                'tmp_name' => $_FILES['studio_image_uploads']['tmp_name'][$index] ?? '',
                'error' => $_FILES['studio_image_uploads']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $_FILES['studio_image_uploads']['size'][$index] ?? 0,
            ];
            $image = admin_upload_image($studioUpload) ?: trim($_POST['studio_existing'][$index] ?? ($existingStudio[$index]['image'] ?? ''));
            $remove = !empty($_POST['studio_remove'][$index]);
            if ($image === '' || $remove) {
                continue;
            }
            $studioImages[] = [
                'image' => $image,
                'alt' => trim($_POST['studio_alt'][$index] ?? ($existingStudio[$index]['alt'] ?? '')),
                'caption' => trim($_POST['studio_caption'][$index] ?? ($existingStudio[$index]['caption'] ?? '')),
                'sort' => (int) ($_POST['studio_sort'][$index] ?? ($existingStudio[$index]['sort'] ?? ($index + 1))),
            ];
        }
        usort($studioImages, fn ($a, $b) => (int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0));

        $genealogy = [];
        for ($index = 0; $index < max(1, count($_POST['genealogy_title'] ?? [])); $index++) {
            $title = trim($_POST['genealogy_title'][$index] ?? '');
            $description = trim($_POST['genealogy_description'][$index] ?? '');
            $url = trim($_POST['genealogy_url'][$index] ?? '');
            if ($title === '' && $description === '' && $url === '') {
                continue;
            }
            $genealogy[] = ['title' => $title, 'description' => $description, 'url' => $url];
        }

        $links = [];
        for ($index = 0; $index < 3; $index++) {
            $text = trim($_POST['link_text'][$index] ?? '');
            $url = trim($_POST['link_url'][$index] ?? '');
            if ($text === '' && $url === '') {
                continue;
            }
            $links[] = ['text' => $text, 'url' => $url];
        }

        $exhibitions = [];
        for ($index = 0; $index < max(4, count($_POST['exhibition_title'] ?? [])); $index++) {
            $title = trim($_POST['exhibition_title'][$index] ?? '');
            $description = trim($_POST['exhibition_description'][$index] ?? '');
            $url = trim($_POST['exhibition_url'][$index] ?? '');
            if ($title === '' && $description === '' && $url === '') {
                continue;
            }
            $exhibitions[] = [
                'title' => $title,
                'description' => $description,
                'url' => $url,
            ];
        }

        $content['settings']['artist_profile'] = artist_profile_defaults([
            'name' => trim($_POST['artist_name'] ?? ''),
            'tagline' => trim($_POST['artist_tagline'] ?? ''),
            'intro' => trim($_POST['artist_intro'] ?? ''),
            'portrait' => [
                'image' => $portraitImage,
                'alt' => trim($_POST['portrait_alt'] ?? ''),
                'caption' => trim($_POST['portrait_caption'] ?? ''),
            ],
            'biography' => trim($_POST['artist_biography'] ?? ''),
            'statement_excerpt' => trim($_POST['statement_excerpt'] ?? ''),
            'statement_button_text' => trim($_POST['statement_button_text'] ?? ''),
            'statement_button_url' => trim($_POST['statement_button_url'] ?? ''),
            'studio_images' => $studioImages,
            'genealogy' => $genealogy,
            'links' => $links,
            'exhibitions' => $existing['exhibitions'] ?? [],
            'seo' => [
                'title' => trim($_POST['seo_title'] ?? ''),
                'description' => trim($_POST['seo_description'] ?? ''),
                'keywords' => trim($_POST['seo_keywords'] ?? ''),
                'og_image' => $ogImage,
            ],
        ]);
    }

    if ($action === 'save_artist_statement') {
        $savedSection = 'statement';
        $existing = artist_profile_defaults($content['settings']['artist_profile'] ?? []);
        $content['settings']['artist_profile'] = artist_profile_defaults(array_replace_recursive($existing, [
            'statement_page' => [
                'title' => trim($_POST['statement_page_title'] ?? ''),
                'intro' => trim($_POST['statement_page_intro'] ?? ''),
                'body' => array_values(array_filter(array_map('trim', preg_split("/\R{2,}/", trim($_POST['statement_page_body'] ?? '')) ?: []))),
            ],
        ]));
    }

    if ($action === 'save_artist_exhibitions') {
        $savedSection = 'exhibitions';
        $existing = artist_profile_defaults($content['settings']['artist_profile'] ?? []);
        $exhibitions = [];
        for ($index = 0; $index < max(4, count($_POST['exhibition_title'] ?? [])); $index++) {
            $title = trim($_POST['exhibition_title'][$index] ?? '');
            $description = trim($_POST['exhibition_description'][$index] ?? '');
            $url = trim($_POST['exhibition_url'][$index] ?? '');
            if ($title === '' && $description === '' && $url === '') {
                continue;
            }
            $exhibitions[] = [
                'title' => $title,
                'description' => $description,
                'url' => $url,
            ];
        }
        $content['settings']['artist_profile'] = artist_profile_defaults(array_replace_recursive($existing, [
            'exhibitions' => $exhibitions,
        ]));
    }

    if ($action === 'save_series') {
        $savedSection = 'series';
        $original = $_POST['original_slug'] ?? '';
        $slug = admin_slug($_POST['slug'] ?? $_POST['title'] ?? '');
        if ($slug !== '') {
            $existing = $content['series'][$original] ?? $content['series'][$slug] ?? [];
            if ($original && $original !== $slug && isset($content['series'][$original])) {
                unset($content['series'][$original]);
                foreach ($content['artworks'] as &$artwork) {
                    if (($artwork['series'] ?? '') === $original) {
                        $artwork['series'] = $slug;
                    }
                }
                unset($artwork);
            }
            $image = admin_upload_image($_FILES['series_image_upload'] ?? []) ?: trim($_POST['image'] ?? ($existing['image'] ?? ''));
            $content['series'][$slug] = [
                'title' => trim($_POST['title'] ?? ''),
                'seo_title' => trim($_POST['seo_title'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'image' => $image,
                'keywords' => array_values(array_filter(array_map('trim', explode(',', $_POST['keywords'] ?? '')))),
            ];
            $savedParams['series'] = $slug;
        }
    }

    if ($action === 'delete_series') {
        $savedSection = 'series';
        $slug = admin_slug($_POST['slug'] ?? '');
        if ($slug !== '' && isset($content['series'][$slug])) {
            unset($content['series'][$slug]);
            $replacement = array_key_first($content['series']) ?: '';
            foreach ($content['artworks'] as &$artwork) {
                if (($artwork['series'] ?? '') === $slug) {
                    $artwork['series'] = $replacement;
                }
            }
            unset($artwork);
        }
    }

    if ($action === 'save_journal') {
        $savedSection = 'journal';
        $original = $_POST['original_slug'] ?? '';
        $slug = admin_slug($_POST['slug'] ?? $_POST['title'] ?? '');
        if ($slug !== '') {
            file_put_contents(__DIR__ . '/data/debug.log', print_r(['POST' => $_POST, 'FILES' => $_FILES], true));
            $existing = $content['studioNotes'][$original] ?? $content['studioNotes'][$slug] ?? [];
            if ($original && $original !== $slug) {
                unset($content['studioNotes'][$original]);
            }
            $image = trim($_POST['image'] ?? ($existing['image'] ?? '')); if (!empty($_POST['remove_journal_image'])) { $image = ''; } $uploadedImage = admin_upload_image($_FILES['journal_image_upload'] ?? []); if ($uploadedImage) { $image = $uploadedImage; };
            $body = preg_split("/\R{2,}/", trim($_POST['body'] ?? '')) ?: [];
            $existingBlocks = $existing['blocks'] ?? [];
            $blocks = [];
            $levels = ['h2', 'h3', 'h4'];
            for ($index = 0; $index < 4; $index++) {
                $level = $_POST['block_level'][$index] ?? ($existingBlocks[$index]['level'] ?? 'h2');
                if (!in_array($level, $levels, true)) {
                    $level = 'h2';
                }
                $blockUpload = [
                    'name' => $_FILES['block_image_uploads']['name'][$index] ?? '',
                    'type' => $_FILES['block_image_uploads']['type'][$index] ?? '',
                    'tmp_name' => $_FILES['block_image_uploads']['tmp_name'][$index] ?? '',
                    'error' => $_FILES['block_image_uploads']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $_FILES['block_image_uploads']['size'][$index] ?? 0,
                ];
                $blockImage = admin_upload_image($blockUpload) ?: trim($_POST['block_image_existing'][$index] ?? ($existingBlocks[$index]['image'] ?? ''));
                $block = [
                    'level' => $level,
                    'title' => trim($_POST['block_title'][$index] ?? ''),
                    'text' => trim($_POST['block_text'][$index] ?? ''),
                    'image' => $blockImage,
                    'caption' => trim($_POST['block_caption'][$index] ?? ''),
                ];
                if ($block['title'] !== '' || $block['text'] !== '' || $block['image'] !== '' || $block['caption'] !== '') {
                    $blocks[] = $block;
                }
            }
            $content['studioNotes'][$slug] = [
                'title' => trim($_POST['title'] ?? ''),
                'seo_title' => trim($_POST['seo_title'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'image' => $image,
                'body' => array_values(array_filter(array_map('trim', $body))),
                'blocks' => $blocks,
            ];
            $savedParams['studioNotes'] = $slug;
        }
    }

    if ($action === 'save_artwork') {
        $savedSection = 'catalog';
        $original = $_POST['original_slug'] ?? '';
        $slug = admin_slug($_POST['slug'] ?? $_POST['title'] ?? '');
        if ($slug !== '') {
            $existing = $content['artworks'][$original] ?? $content['artworks'][$slug] ?? [];
            if ($original && $original !== $slug) {
                unset($content['artworks'][$original]);
            }

            $image = admin_upload_image($_FILES['image_upload'] ?? []) ?: trim($_POST['image'] ?? ($existing['image'] ?? ''));
            $detailPaths = array_values(array_filter(array_map('trim', preg_split("/\R+/", $_POST['detail_images'] ?? '') ?: [])));
            $legacyDetailImage = trim($_POST['detail_image'] ?? ($existing['detail_image'] ?? ''));
            if ($legacyDetailImage !== '' && !in_array($legacyDetailImage, $detailPaths, true)) {
                array_unshift($detailPaths, $legacyDetailImage);
            }
            if (!empty($_FILES['detail_uploads']['name']) && is_array($_FILES['detail_uploads']['name'])) {
                foreach ($_FILES['detail_uploads']['name'] as $index => $name) {
                    $upload = [
                        'name' => $name,
                        'type' => $_FILES['detail_uploads']['type'][$index] ?? '',
                        'tmp_name' => $_FILES['detail_uploads']['tmp_name'][$index] ?? '',
                        'error' => $_FILES['detail_uploads']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['detail_uploads']['size'][$index] ?? 0,
                    ];
                    $uploaded = admin_upload_image($upload);
                    if ($uploaded) {
                        $detailPaths[] = $uploaded;
                    }
                }
            }
            $detailImage = $detailPaths[0] ?? '';
            $mockupPaths = array_values(array_filter(array_map('trim', preg_split("/\R+/", $_POST['mockups'] ?? '') ?: [])));

            if (!empty($_FILES['mockup_uploads']['name']) && is_array($_FILES['mockup_uploads']['name'])) {
                foreach ($_FILES['mockup_uploads']['name'] as $index => $name) {
                    $upload = [
                        'name' => $name,
                        'type' => $_FILES['mockup_uploads']['type'][$index] ?? '',
                        'tmp_name' => $_FILES['mockup_uploads']['tmp_name'][$index] ?? '',
                        'error' => $_FILES['mockup_uploads']['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                        'size' => $_FILES['mockup_uploads']['size'][$index] ?? 0,
                    ];
                    $uploaded = admin_upload_image($upload);
                    if ($uploaded) {
                        $mockupPaths[] = $uploaded;
                    }
                }
            }
            $dimensionParts = [
                'width' => admin_clean_field($_POST['dimension_width_cm'] ?? ''),
                'height' => admin_clean_field($_POST['dimension_height_cm'] ?? ''),
                'depth' => admin_clean_field($_POST['dimension_depth_cm'] ?? ''),
            ];
            $dimensionsCm = admin_format_dimensions($dimensionParts, 'cm') ?: admin_clean_field($_POST['dimensions_cm'] ?? ($existing['dimensions_cm'] ?? ''));
            $dimensionsIn = '';
            if ($dimensionsCm !== '') {
                $parsedParts = admin_dimension_parts($dimensionsCm);
                $inchParts = [
                    'width' => is_numeric($parsedParts['width']) ? (string) ((float) $parsedParts['width'] / 2.54) : '',
                    'height' => is_numeric($parsedParts['height']) ? (string) ((float) $parsedParts['height'] / 2.54) : '',
                    'depth' => is_numeric($parsedParts['depth']) ? (string) ((float) $parsedParts['depth'] / 2.54) : '',
                ];
                $dimensionsIn = admin_format_dimensions($inchParts, 'in');
            }
            $dimensionsIn = $dimensionsIn ?: admin_clean_field($_POST['dimensions_in'] ?? ($existing['dimensions_in'] ?? ''));

            $seriesSlug = $_POST['series'] ?? '';
            $seriesTitle = $content['series'][$seriesSlug]['title'] ?? $seriesSlug;
            $medium = admin_clean_field($_POST['medium'] ?? '');
            $year = admin_clean_field($_POST['year'] ?? '');
            $seoBaseName = 'maurizio-valch_' . admin_slug($_POST['title'] ?? 'artwork') . '_' . admin_slug($seriesTitle) . '_' . admin_slug($medium) . '_contemporary-abstract-painting_' . admin_slug($year);

            $image = seo_rename_image($image, $seoBaseName);

            $seoDetailPaths = [];
            foreach ($detailPaths as $idx => $path) {
                $seoDetailPaths[] = seo_rename_image($path, $seoBaseName, 'detail-' . ($idx + 1));
            }
            $detailPaths = $seoDetailPaths;
            $detailImage = $detailPaths[0] ?? '';

            $seoMockupPaths = [];
            foreach ($mockupPaths as $idx => $path) {
                $seoMockupPaths[] = seo_rename_image($path, $seoBaseName, 'mockup-' . ($idx + 1));
            }
            $mockupPaths = $seoMockupPaths;

            $content['artworks'][$slug] = [
                'title' => admin_clean_field($_POST['title'] ?? ''),
                'year' => admin_clean_field($_POST['year'] ?? ''),
                'series' => $_POST['series'] ?? array_key_first($content['series']),
                'status' => in_array($_POST['status'] ?? '', ['available', 'sold', 'placed', 'reserved', 'archive']) ? $_POST['status'] : 'available',
                'medium' => admin_clean_field($_POST['medium'] ?? ''),
                'dimensions_cm' => $dimensionsCm,
                'dimensions_in' => $dimensionsIn,
                'orientation' => admin_clean_field($_POST['orientation'] ?? ''),
                'image' => $image,
                'detail_image' => $detailImage,
                'detail_images' => array_map(fn ($path) => ['image' => $path, 'alt' => admin_clean_field($_POST['title'] ?? '') . ' detail'], $detailPaths),
                'mockups' => array_map(fn ($path) => ['image' => $path, 'alt' => admin_clean_field($_POST['title'] ?? '') . ' mockup'], $mockupPaths),
                'price' => admin_clean_field($_POST['price'] ?? ''),
                'currency' => admin_clean_field($_POST['currency'] ?? 'EUR'),
                'purchase_url' => admin_clean_field($_POST['purchase_url'] ?? ''),
                'sale_platform' => admin_clean_field($_POST['sale_platform'] ?? ($existing['sale_platform'] ?? '')),
                'sale_result' => admin_clean_field($_POST['sale_result'] ?? ($existing['sale_result'] ?? '')),
                'summary' => admin_clean_field($_POST['summary'] ?? ''),
                'concept' => admin_clean_field($_POST['concept'] ?? ''),
                'commercial_note' => admin_clean_field($_POST['commercial_note'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? ($existing['sort_order'] ?? ((count($content['artworks']) + 1) * 10))),
                'pinned' => !empty($_POST['pinned']) ? 1 : 0,
            ];

            $locations = admin_map_locations_by_artwork($content['sold_locations']);
            $artworkStatus = $content['artworks'][$slug]['status'];
            if ($artworkStatus === 'sold' || $artworkStatus === 'placed' || $artworkStatus === 'collected') {
                $postalCode = trim($_POST['postal_code'] ?? '');
                $country = trim($_POST['country'] ?? '');
                $lat = trim($_POST['lat'] ?? '');
                $lng = trim($_POST['lng'] ?? '');
                if ($country !== '' || (is_numeric($lat) && is_numeric($lng))) {
                    $locations[$slug] = [
                        'artwork_slug' => $slug,
                        'title' => $content['artworks'][$slug]['title'],
                        'postal_code' => $postalCode,
                        'country' => $country,
                    ];
                    if (is_numeric($lat) && is_numeric($lng)) {
                        $locations[$slug]['lat'] = (float) $lat;
                        $locations[$slug]['lng'] = (float) $lng;
                    }
                }
            } else {
                unset($locations[$slug]);
            }
            $content['sold_locations'] = array_values($locations);
            $savedParams['artwork'] = $slug;
        }
    }

    if ($action === 'move_artwork') {
        $savedSection = 'catalog';
        $slug = admin_slug($_POST['slug'] ?? '');
        $direction = ($_POST['direction'] ?? '') === 'down' ? 'down' : 'up';
        if ($slug !== '' && isset($content['artworks'][$slug])) {
            $ordered = artworks_ordered($content['artworks']);
            $slugs = array_keys($ordered);
            $index = array_search($slug, $slugs, true);
            if ($index !== false) {
                $swapIndex = $direction === 'up' ? $index - 1 : $index + 1;
                if (isset($slugs[$swapIndex])) {
                    $neighborSlug = $slugs[$swapIndex];
                    $currentOrder = (int) ($content['artworks'][$slug]['sort_order'] ?? (($index + 1) * 10));
                    $neighborOrder = (int) ($content['artworks'][$neighborSlug]['sort_order'] ?? (($swapIndex + 1) * 10));
                    $content['artworks'][$slug]['sort_order'] = $neighborOrder;
                    $content['artworks'][$neighborSlug]['sort_order'] = $currentOrder;
                }
            }
        }
        $savedParams['artwork'] = $slug;
    }

    if ($action === 'delete_artwork') {
        $savedSection = 'catalog';
        $slug = admin_slug($_POST['slug'] ?? '');
        if ($slug !== '' && isset($content['artworks'][$slug])) {
            unset($content['artworks'][$slug]);
            $locations = admin_map_locations_by_artwork($content['sold_locations']);
            unset($locations[$slug]);
            $content['sold_locations'] = array_values($locations);
        }
    }

    admin_save_content($content);
    $query = array_merge(['section' => $savedSection, 'saved' => '1'], $savedParams);
    header('Location: ' . url_for('admin') . '?' . http_build_query($query));
    exit;
}

function render_home(array $site, array $artworks): void
{
    $available = artworks_by_status($artworks, 'available');
    $publishedItems = array_filter(
        app_catalog()?->all() ?? [],
        static fn (array $item): bool => ($item['visibility'] ?? '') === 'public'
    );
    $publishedSeries = app_series_catalog()?->all() ?? [];
    $heroSlides = array_values(array_map(
        static function (array $artwork): array {
            $file = trim((string)($artwork['header_file'] ?? ''))
                ?: basename((string)($artwork['source_image_file'] ?? ''));
            return [
                'image' => app_publication_media_url($artwork, $file, 1200),
                'srcset' => app_publication_media_srcset($artwork, $file),
                'title' => $artwork['title'] ?? 'Maurizio Valch',
            ];
        },
        $publishedItems
    ));
    if (!$heroSlides) {
        $heroSlides = array_values(array_filter(array_map(
            fn ($artwork) => !empty($artwork['image']) ? [
                'image' => asset_url($artwork['image']),
                'srcset' => '',
                'title' => $artwork['title'] ?? 'Maurizio Valch',
            ] : null,
            $available
        )));
    }
    if (!$heroSlides) {
        $heroSlides[] = [
            'image' => asset_url('/assets/images/the-path-before-architecture.jpg'),
            'srcset' => '',
            'title' => 'Maurizio Valch',
        ];
    }
    ?>
    <section class="hero">
        <div class="hero__media" data-hero-slider>
            <div class="hero__slides">
                <?php foreach ($heroSlides as $index => $slide): ?>
                    <img class="hero__slide"
                        <?= $index === 0 ? 'src="' . e($slide['image']) . '"' : 'data-src="' . e($slide['image']) . '"' ?>
                        <?= !empty($slide['srcset']) ? ($index === 0 ? 'srcset="' : 'data-srcset="') . e($slide['srcset']) . '" sizes="(max-width: 940px) 100vw, 55vw"' : '' ?>
                        alt="<?= e(site_copy('global.root_image_alt', ['title' => $slide['title']])) ?>"
                        decoding="async" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>
                        data-hero-slide <?= $index === 0 ? 'data-active="true"' : '' ?>>
                <?php endforeach; ?>
            </div>
            <?php if (count($heroSlides) > 1): ?>
                <button class="hero__arrow hero__arrow--prev" type="button" data-hero-prev aria-label="<?= e(site_copy('global.previous_artwork')) ?>">‹</button>
                <button class="hero__arrow hero__arrow--next" type="button" data-hero-next aria-label="<?= e(site_copy('global.next_artwork')) ?>">›</button>
            <?php endif; ?>
        </div>
        <div class="hero__content">
            <p class="eyebrow"><?= e(site_copy('home.hero.eyebrow')) ?></p>
            <h1><?= e(site_copy('home.hero.title')) ?></h1>
            <p class="lead"><?= e(site_copy('home.hero.lead')) ?></p>
            <form class="hero-search" action="<?= e(url_for('paintings')) ?>" method="get" role="search">
                <label class="sr-only" for="hero-search-input"><?= e(site_copy('home.hero.search_label')) ?></label>
                <input id="hero-search-input" name="q" type="search" placeholder="<?= e(site_copy('home.hero.search_placeholder')) ?>">
                <button type="submit"><?= e(site_copy('home.hero.search_button')) ?></button>
            </form>
            <div class="actions">
                <a class="button" href="<?= e(url_for('paintings')) ?>"><?= e(site_copy('home.hero.open_catalog')) ?></a>
                <a class="button button--quiet" href="<?= e(url_for('sold-works')) ?>"><?= e(site_copy('home.hero.view_constellations')) ?></a>
                <a class="button button--quiet" href="<?= e(url_for('artist-statement')) ?>"><?= e(site_copy('home.hero.read_statement')) ?></a>
            </div>
        </div>
    </section>

    <section class="section search-paths">
        <div class="path-card">
            <p class="eyebrow"><?= e(site_copy('home.paths.catalog_eyebrow')) ?></p>
            <h2><?= e(site_copy('home.paths.catalog_title')) ?></h2>
            <p><?= e(site_copy('home.paths.catalog_text')) ?></p>
            <a href="<?= e(url_for('paintings')) ?>"><?= e(site_copy('home.paths.catalog_action')) ?></a>
        </div>
        <div class="path-card">
            <p class="eyebrow"><?= e(site_copy('home.paths.archive_eyebrow')) ?></p>
            <h2><?= e(site_copy('home.paths.archive_title')) ?></h2>
            <p><?= e(site_copy('home.paths.archive_text')) ?></p>
            <a href="<?= e(url_for('sold-works')) ?>"><?= e(site_copy('home.paths.archive_action')) ?></a>
        </div>
        <div class="path-card">
            <p class="eyebrow"><?= e(site_copy('home.paths.explore_eyebrow')) ?></p>
            <h2><?= e(site_copy('home.paths.explore_title')) ?></h2>
            <p><?= e(site_copy('home.paths.explore_text')) ?></p>
            <a href="<?= e(url_for('series')) ?>"><?= e(site_copy('home.paths.explore_action')) ?></a>
        </div>
    </section>

    <section class="section section--split">
        <div>
            <p class="eyebrow"><?= e(site_copy('home.collectors.eyebrow')) ?></p>
            <h2><?= e(site_copy('home.collectors.title')) ?></h2>
        </div>
        <div class="prose">
            <p><?= e(site_copy('home.collectors.first')) ?></p>
            <p><?= e(site_copy('home.collectors.second')) ?></p>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <p class="eyebrow"><?= e(site_copy('home.selected.eyebrow')) ?></p>
            <h2><?= e(site_copy('home.selected.title')) ?></h2>
            <a href="<?= e(url_for('paintings')) ?>?status=available"><?= e(site_copy('home.selected.filter')) ?></a>
        </div>
        <div class="art-grid">
            <?php if ($publishedItems): ?>
                <?php foreach ($publishedItems as $slug => $artwork): ?>
                    <?php $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']; ?>
                    <article class="art-card">
                        <a class="art-card__image" href="<?= e(url_for('artworks/' . $slug)) ?>">
                            <img src="<?= e(app_publication_media_url($artwork, $cardImageFile, 480)) ?>"
                                srcset="<?= e(app_publication_media_srcset($artwork, $cardImageFile)) ?>"
                                sizes="(max-width: 940px) calc(100vw - 36px), 33vw"
                                alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' by Maurizio Valch') ?>"
                                loading="lazy" decoding="async">
                        </a>
                        <div class="art-card__body">
                            <div class="eyebrow"><?= e($artwork['series'] ?: site_copy('home.selected.original_work')) ?></div>
                            <h3><a href="<?= e(url_for('artworks/' . $slug)) ?>"><?= e($artwork['title']) ?></a></h3>
                            <p><?= e(implode(', ', array_filter([$artwork['medium'], published_dimensions($artwork)]))) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($available as $slug => $artwork) render_artwork_card($slug, $artwork, $series); ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section band">
        <p class="eyebrow"><?= e(site_copy('home.identity.eyebrow')) ?></p>
        <h2><?= e(site_copy('home.identity.title')) ?></h2>
        <p><?= e(site_copy('home.identity.text')) ?></p>
    </section>

    <section class="section">
        <div class="section-head">
            <p class="eyebrow"><?= e(site_copy('home.series.eyebrow')) ?></p>
            <h2><?= e(site_copy('home.series.title')) ?></h2>
            <a href="<?= e(url_for('series')) ?>"><?= e(site_copy('home.series.action')) ?></a>
        </div>
        <div class="series-grid">
            <?php foreach ($publishedSeries as $slug => $item): ?>
                <a class="series-card" href="<?= e(url_for('series/' . $slug)) ?>">
                    <span><?= e($item['title']) ?></span>
                    <p><?= e($item['description']) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function render_constellation_map(array $soldLocations, array $artworks): void
{
    $locations = [];
    foreach ($soldLocations as $location) {
        $coordinates = map_location_coordinates($location);
        if ($coordinates) {
            $location['map_lat'] = $coordinates['lat'];
            $location['map_lng'] = $coordinates['lng'];
            $locations[] = $location;
        }
    }
    $mappedSlugs = array_values(array_filter(array_map(fn ($location) => $location['artwork_slug'] ?? '', $locations)));
    $mapItems = [];
    foreach ($locations as $location) {
        $artworkSlug = $location['artwork_slug'] ?? '';
        $artwork = $artworks[$artworkSlug] ?? null;
        if (!$artwork) {
            continue;
        }
        $mapItems[] = [
            'lat' => (float) $location['map_lat'],
            'lng' => (float) $location['map_lng'],
            'title' => $artwork['title'] ?? ($location['title'] ?? 'Placed work'),
            'country' => $location['country'] ?? '',
            'postal_code' => $location['postal_code'] ?? '',
            'image' => asset_url($artwork['image'] ?? ''),
            'url' => url_for('paintings/' . $artworkSlug),
        ];
    }
    $pendingArtworks = array_filter($artworks, fn ($artwork, $slug) => ($artwork['status'] ?? '') === 'sold' && !in_array($slug, $mappedSlugs, true), ARRAY_FILTER_USE_BOTH);
    ?>
    <section class="section constellation-section" aria-label="<?= e(site_t('World map of placed works', 'Mapa mundial de obras emplazadas')) ?>">
        <div class="constellation-real-map" aria-label="<?= e(site_t('World map of placed works', 'Mapa mundial de obras emplazadas')) ?>" data-constellation-leaflet data-map-items="<?= e(json_encode($mapItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <div class="constellation-real-map__canvas" data-map-canvas aria-label="<?= e(site_t('Placed works on the map', 'Obras emplazadas en el mapa')) ?>"></div>
            <aside class="constellation-real-map__card" data-constellation-card>
                <?php if (!empty($mapItems[0]['image'])): ?>
                    <img data-constellation-image src="<?= e($mapItems[0]['image']) ?>" alt="">
                <?php else: ?>
                    <img data-constellation-image src="" alt="" hidden>
                <?php endif; ?>
                <div class="constellation-real-map__text">
                    <strong data-constellation-title><?= e($mapItems[0]['title'] ?? 'Select a work') ?></strong>
                    <span data-constellation-place><?= e(trim(($mapItems[0]['postal_code'] ?? '') . (($mapItems[0]['postal_code'] ?? '') && ($mapItems[0]['country'] ?? '') ? ' / ' : '') . ($mapItems[0]['country'] ?? ''))) ?></span>
                </div>
                <a data-constellation-link href="<?= e($mapItems[0]['url'] ?? url_for('paintings')) ?>">Open ficha</a>
            </aside>
            <?php if (!$mapItems): ?>
                <div class="constellation-map__empty">
                    <strong>Placed works pending location</strong>
                    <span>These sold works need a postal zone or coordinates before they can be placed on the map.</span>
                    <?php if ($pendingArtworks): ?>
                        <div class="constellation-pending">
                            <?php foreach ($pendingArtworks as $slug => $artwork): ?>
                                <a href="<?= e(url_for('paintings/' . $slug)) ?>">
                                    <?php if (!empty($artwork['image'])): ?>
                                        <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e($artwork['title'] . ' sold work pending location') ?>">
                                    <?php endif; ?>
                                    <span><?= e($artwork['title']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($locations && $pendingArtworks): ?>
                <div class="constellation-map__pending-strip">
                    <strong>Pending placement</strong>
                    <div class="constellation-pending">
                        <?php foreach ($pendingArtworks as $slug => $artwork): ?>
                            <a href="<?= e(url_for('paintings/' . $slug)) ?>">
                                <?php if (!empty($artwork['image'])): ?>
                                    <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e($artwork['title'] . ' sold work pending location') ?>">
                                <?php endif; ?>
                                <span><?= e($artwork['title']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <p class="privacy-note privacy-note--map">Locations are shown by postal zone only. Collector names, addresses, and private details are never published.</p>
    </section>
    <?php
}

function render_catalog(array $site, array $series, array $artworks, string $title, string $intro, ?string $status = null, array $soldRecords = [], array $soldLocations = []): void
{
    $items = $status ? artworks_by_status($artworks, $status) : artworks_ordered($artworks);
    ?>
    <section class="page-hero <?= $status === 'sold' ? 'page-hero--compact' : '' ?>">
        <p class="eyebrow">Artwork catalog</p>
        <h1><?= e($title) ?></h1>
        <p><?= e($intro) ?></p>
    </section>
    <?php if ($status === 'sold'): ?>
        <?php render_constellation_map($soldLocations, $artworks); ?>
    <?php endif; ?>
    <section class="section catalog-grid-section">
        <div class="art-grid">
            <?php foreach ($items as $slug => $artwork) render_artwork_card($slug, $artwork, $series); ?>
        </div>
        <div class="catalog-tools" data-catalog-tools>
            <div class="catalog-search">
                <label for="catalog-search-input">Search the catalog</label>
                <input id="catalog-search-input" data-catalog-search type="search" placeholder="Title, status, series, concept, size">
            </div>
            <div class="filter-row" aria-label="Catalog filters">
                <?php if (!$status): ?>
                    <button type="button" data-filter-status="all">All</button>
                    <button type="button" data-filter-status="available">In Studio</button>
                    <button type="button" data-filter-status="sold">Placed</button>
                <?php endif; ?>
                <?php foreach ($series as $slug => $item): ?>
                    <button type="button" data-filter-series="<?= e($slug) ?>"><?= e($item['title']) ?></button>
                <?php endforeach; ?>
            </div>
            <p class="catalog-count" data-catalog-count></p>
        </div>
    </section>
    <?php if ($status === 'sold' && $soldRecords): ?>
        <section class="section">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Provenance map</p>
                    <h2>Constellations of Works</h2>
                </div>
            </div>
            <div class="sold-table" role="table" aria-label="Constellations of works">
                <div class="sold-table__row sold-table__head" role="row">
                    <span>Artwork</span>
                    <span>Size</span>
                    <span>Provenance</span>
                    <span>Status</span>
                    <span>Concept cluster</span>
                </div>
                <?php foreach ($soldRecords as $record): ?>
                    <a class="sold-table__row" role="row" href="<?= e($record['url']) ?>" target="_blank" rel="noopener">
                        <span><?= e($record['title']) ?>, <?= e($record['year']) ?></span>
                        <span><?= e($record['dimensions']) ?></span>
                        <span><?= e($record['platform']) ?></span>
                        <span><?= e($record['public_price']) ?></span>
                        <span><?= e($record['cluster']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php
}

function render_artwork_detail(array $site, array $series, array $artworks, string $slug): bool
{
    if (!isset($artworks[$slug])) {
        return false;
    }
    $artwork = $artworks[$slug];
    $status = $artwork['status'] === 'available' ? 'In Studio' : 'Placed';
    $seriesSlug = $artwork['series'] ?? '';
    $seriesTitle = $series[$seriesSlug]['title'] ?? 'Unassigned';
    $detailImages = $artwork['detail_images'] ?? [];
    if (!$detailImages && !empty($artwork['detail_image'])) {
        $detailImages = [['image' => $artwork['detail_image'], 'alt' => $artwork['title'] . ' detail']];
    }
    ?>
    <section class="artwork-detail">
        <div class="artwork-detail__image">
            <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e($artwork['title'] . ' original painting by Maurizio Valch') ?>">
            <?php if (!empty($detailImages)): ?>
                <div class="mockup-gallery" aria-label="<?= e($artwork['title'] . ' detail photographs') ?>">
                    <?php foreach ($detailImages as $detail): ?>
                        <?php if (!empty($detail['image'])): ?>
                            <img src="<?= e(asset_url($detail['image'])) ?>" alt="<?= e($detail['alt'] ?? $artwork['title'] . ' detail') ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($artwork['mockups'])): ?>
                <div class="mockup-gallery" aria-label="<?= e($artwork['title'] . ' mockups') ?>">
                    <?php foreach ($artwork['mockups'] as $mockup): ?>
                        <img src="<?= e(asset_url($mockup['image'])) ?>" alt="<?= e($mockup['alt'] ?? $artwork['title'] . ' contextual mockup') ?>">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="artwork-detail__content">
            <p class="eyebrow"><?= e($status) ?> / <?= e($seriesTitle) ?></p>
            <h1><?= e($artwork['title']) ?></h1>
            <p class="lead"><?= e($artwork['summary']) ?></p>
            <dl class="specs">
                <div><dt>Year</dt><dd><?= e($artwork['year']) ?></dd></div>
                <div><dt>Medium</dt><dd><?= e($artwork['medium']) ?></dd></div>
                <div>
                    <dt>Size</dt>
                    <dd>
                        <span data-size-metric><?= e($artwork['dimensions_cm']) ?></span>
                        <span data-size-imperial hidden><?= e($artwork['dimensions_in']) ?></span>
                    </dd>
                </div>
                <div><dt>Orientation</dt><dd><?= e($artwork['orientation']) ?></dd></div>
                <div><dt>Status</dt><dd><?= e($status) ?></dd></div>
                <?php if ($artwork['status'] === 'available' || $artwork['status'] === 'for sale'): ?>
                    <div><dt>Price</dt><dd><?= e(format_artwork_price($artwork['price'] ?? '', $artwork['currency'] ?? 'EUR')) ?></dd></div>
                <?php else: ?>
                    <div><dt>Placement trace</dt><dd><?= e($artwork['sale_platform'] ?? 'Private collection') ?></dd></div>
                <?php endif; ?>
            </dl>
            <div class="prose">
                <h2>Conceptual Note</h2>
                <p><?= e($artwork['concept']) ?></p>
                <h2>Studio Information</h2>
                <p><?= e($artwork['commercial_note']) ?></p>
            </div>
            <div class="actions" style="display: flex; flex-direction: column; gap: 10px;">
                <?php if ($artwork['status'] === 'available' || $artwork['status'] === 'for sale'): ?>
                    <?php 
                        $checkoutUrl = !empty($artwork['purchase_url']) ? $artwork['purchase_url'] : url_for('artwork.php?slug=' . $slug);
                    ?>
                    <a class="button" href="<?= e($checkoutUrl) ?>" <?= !empty($artwork['purchase_url']) ? 'target="_blank" rel="noopener"' : '' ?> style="justify-content: center; width: 100%;">Buy Artwork</a>
                    <a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($slug) ?>" style="justify-content: center; width: 100%;">Inquire about this work</a>
                <?php else: ?>
                    <a class="button" href="<?= e(url_for('paintings')) ?>" style="justify-content: center; width: 100%;">View Works in Studio</a>
                <?php endif; ?>
                <?php if ($seriesSlug && isset($series[$seriesSlug])): ?>
                    <a class="button button--quiet" href="<?= e(url_for('series/' . $seriesSlug)) ?>" style="justify-content: center; width: 100%;">View Series</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
    echo json_ld([
        '@context' => 'https://schema.org',
        '@type' => 'VisualArtwork',
        'name' => $artwork['title'],
        'creator' => ['@type' => 'Person', 'name' => $site['name']],
        'artMedium' => $artwork['medium'],
        'artform' => 'Painting',
        'width' => $artwork['dimensions_cm'],
        'dateCreated' => $artwork['year'],
        'image' => $site['url'] . $artwork['image'],
        'description' => $artwork['summary'],
        'inLanguage' => artist_site_language(),
    ]);
    return true;
}

function app_admin_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = artist_site_database_connection(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'platform');
    }
    return $pdo;
}

function resolved_artist_email(): string
{
    static $email = null;
    if ($email === null) {
        try {
            $resolver = new TenantResolver(app_admin_pdo());
            $email = $resolver->resolveEmail();
        } catch (Throwable $e) {
            error_log('Tenant resolution failed: ' . $e->getMessage());
            throw $e;
        }
    }
    return $email;
}

/**
 * @return list<string>
 */
function app_publication_locales(): array
{
    static $locales = null;
    if ($locales === null) {
        try {
            $locales = AppPublishedLocalization::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email())
                ->publicationLocales();
        } catch (Throwable $error) {
            error_log('Artist publication locales unavailable: ' . $error->getMessage());
            $locales = ['es', 'en'];
        }
    }
    return $locales;
}

function app_catalog(): ?AppPublishedCatalog
{
    static $catalog = false;
    if ($catalog === false) {
        try {
            $catalog = AppPublishedCatalog::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Published artwork catalog unavailable: ' . $error->getMessage());
            $catalog = null;
        }
    }
    return $catalog;
}

function app_store(): ?AppStore
{
    static $store = false;
    if ($store === false) {
        try {
            $store = AppStore::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Artist store unavailable: ' . $error->getMessage());
            $store = null;
        }
    }
    return $store;
}

function app_stripe_checkout(): ?StripeCheckout
{
    static $payments = false;
    if ($payments === false) {
        try {
            $pdo = app_admin_pdo();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1');
            $stmt->execute([strtolower(resolved_artist_email())]);
            $userId = (int)($stmt->fetchColumn() ?: 0);
            $payments = $userId > 0 ? StripeCheckout::forArtist($pdo, $userId) : null;
        } catch (Throwable $error) {
            error_log('Stripe Checkout unavailable: ' . $error->getMessage());
            $payments = null;
        }
    }
    return $payments;
}

function app_absolute_url(string $path): string
{
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) throw new RuntimeException('The public website host is invalid.');
        $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        $https = strtolower((string)($_SERVER['HTTPS'] ?? '')) === 'on'
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            || $forwardedProto === 'https';
        return ($https ? 'https://' : 'http://') . $host . url_for($path);
    }
    $configured = trim((string)(getenv('ARTIST_SITE_PUBLIC_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/') . '/' . ltrim($path, '/');
    throw new RuntimeException('The artist website public URL is not configured.');
}

function app_publication_media_url(array $artwork, string $file, int $width = 0): string
{
    $url = artworkmockups_public_url() . '/publication_media.php?slug=' . rawurlencode((string)$artwork['slug']) . '&file=' . rawurlencode(basename($file));
    return $width > 0 ? $url . '&w=' . $width : $url;
}

function app_publication_media_srcset(array $artwork, string $file): string
{
    return implode(', ', array_map(
        static fn (int $width): string => app_publication_media_url($artwork, $file, $width) . ' ' . $width . 'w',
        [480, 768, 1200]
    ));
}

function app_publication_video_url(array $artwork, bool $poster = false): string
{
    $url = artworkmockups_public_url() . '/publication_video_media.php?slug=' . rawurlencode((string)$artwork['slug']);
    return $poster ? $url . '&poster=1' : $url;
}

function published_video_duration(float $seconds): string
{
    $total = max(0, (int)round($seconds));
    return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
}

function published_excerpt(array $values, int $limit = 220): string
{
    foreach ($values as $value) {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$value)) ?? '');
        if ($text === '') continue;
        return mb_strlen($text) > $limit
            ? rtrim(mb_substr($text, 0, max(1, $limit - 1))) . '…'
            : $text;
    }
    return '';
}

/** @return array<string,array<string,mixed>> */
function artwork_related_studio_notes(array $artwork): array
{
    $notes = app_studio_notes_catalog()?->all(artist_site_language()) ?? [];
    if (!$notes) return [];
    $artworkId = (int)($artwork['canonical_artwork_id'] ?? 0);
    $seriesId = (int)($artwork['series_id'] ?? 0);
    $artworkTitle = mb_strtolower(trim((string)($artwork['title'] ?? '')));
    $seriesTitle = mb_strtolower(trim((string)($artwork['series'] ?? '')));
    $mockupFiles = array_values(array_filter(array_map(
        static fn(array $item): string => basename((string)($item['mockup_file'] ?? '')),
        (array)($artwork['items'] ?? [])
    )));

    $ranked = [];
    $position = 0;
    foreach ($notes as $slug => $note) {
        $position++;
        $payloadSource = is_array($note['source'] ?? null) ? $note['source'] : [];
        $type = mb_strtolower(trim((string)($payloadSource['type'] ?? $note['source_type'] ?? '')));
        $id = (int)($payloadSource['id'] ?? $note['source_id'] ?? 0);
        $label = mb_strtolower(trim((string)($payloadSource['label'] ?? $note['source_label'] ?? '')));
        $noteFiles = array_values(array_filter(array_map('basename', (array)($note['mockup_files'] ?? []))));
        $matchesArtwork = in_array($type, ['artwork', 'obra'], true)
            && (($artworkId > 0 && $id === $artworkId) || ($artworkTitle !== '' && $label === $artworkTitle));
        $matchesSeries = in_array($type, ['series', 'serie'], true)
            && (($seriesId > 0 && $id === $seriesId) || ($seriesTitle !== '' && $label === $seriesTitle));
        $matchesMedia = (bool)array_intersect($mockupFiles, $noteFiles);
        $searchable = mb_strtolower(implode(' ', array_filter([
            (string)($note['title'] ?? ''),
            (string)($note['excerpt'] ?? ''),
            (string)($note['seo_description'] ?? ''),
            (string)($note['search_terms'] ?? ''),
            strip_tags((string)($note['objective'] ?? '')),
        ])));
        $matchesText = ($seriesTitle !== '' && mb_stripos($searchable, $seriesTitle) !== false)
            || ($artworkTitle !== '' && mb_stripos($searchable, $artworkTitle) !== false);
        if (!$matchesArtwork && !$matchesSeries && !$matchesMedia && !$matchesText) continue;
        $score = ($matchesArtwork ? 120 : 0)
            + ($matchesSeries ? 100 : 0)
            + ($matchesMedia ? 80 : 0)
            + ($matchesText ? 20 : 0);
        $ranked[] = ['slug' => $slug, 'note' => $note, 'score' => $score, 'position' => $position];
    }
    usort($ranked, static fn(array $left, array $right): int =>
        ($right['score'] <=> $left['score']) ?: ($left['position'] <=> $right['position'])
    );
    $related = [];
    foreach (array_slice($ranked, 0, 3) as $match) $related[$match['slug']] = $match['note'];
    return $related;
}

function artworkmockups_public_url(): string
{
    $configured = trim((string)(getenv('ARTWORKMOCKUPS_PUBLIC_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/');
    $appsBase = rtrim(str_replace('\\', '/', dirname(base_path())), '/');
    return $appsBase . '/platform';
}

function app_series_catalog(): ?AppPublishedSeriesCatalog
{
    static $catalog = false;
    if ($catalog === false) {
        try {
            $sitemapPdo = $GLOBALS['artist_site_sitemap_pdo'] ?? null;
            $catalog = $sitemapPdo instanceof PDO
                ? new AppPublishedSeriesCatalog($sitemapPdo, resolved_artist_email())
                : AppPublishedSeriesCatalog::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Published series catalog unavailable: ' . $error->getMessage());
            $catalog = null;
        }
    }
    return $catalog;
}

function app_series_media_url(array $series, string $file, int $width = 0): string
{
    if ($file === '') return '';
    $url = artworkmockups_public_url() . '/series_media.php?slug=' . rawurlencode((string)$series['slug']) . '&file=' . rawurlencode(basename($file));
    return $width > 0 ? $url . '&w=' . $width : $url;
}

function app_series_media_srcset(array $series, string $file): string
{
    return implode(', ', array_map(
        static fn (int $width): string => app_series_media_url($series, $file, $width) . ' ' . $width . 'w',
        [480, 768, 1200]
    ));
}

function app_artist_profile(): ?AppPublishedArtistProfile
{
    static $profile = false;
    if ($profile === false) {
        try {
            $profile = AppPublishedArtistProfile::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Published artist profile unavailable: ' . $error->getMessage());
            $profile = null;
        }
    }
    return $profile;
}

function app_artist_photo_url(string $file): string
{
    if ($file === '') return '';
    return artworkmockups_public_url() . '/profile_media.php?file=' . rawurlencode(basename($file));
}

function app_studio_notes_catalog(): ?AppPublishedStudioNotes
{
    static $catalog = false;
    if ($catalog === false) {
        try {
            $catalog = AppPublishedStudioNotes::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Published studio notes catalog unavailable: ' . $error->getMessage());
            $catalog = null;
        }
    }
    return $catalog;
}

function app_studio_note_media_url(array $post, string $file, int $width = 0): string
{
    if ($file === '' || (int)($post['id'] ?? 0) <= 0) return '';
    $url = artworkmockups_public_url() . '/studio_note_media.php?note=' . (int)$post['id'] . '&file=' . rawurlencode(basename($file));
    return $width > 0 ? $url . '&w=' . $width : $url;
}

function app_studio_note_media_srcset(array $post, string $file): string
{
    return implode(', ', array_map(
        static fn (int $width): string => app_studio_note_media_url($post, $file, $width) . ' ' . $width . 'w',
        [480, 768, 1200]
    ));
}

function app_studio_note_embedded_image_url(array $post, int $width = 0): string
{
    if ((int)($post['id'] ?? 0) <= 0) return '';
    $url = artworkmockups_public_url() . '/studio_note_embedded_image.php?note=' . (int)$post['id'];
    return $width > 0 ? $url . '&w=' . $width : $url;
}

function app_studio_note_embedded_image_srcset(array $post): string
{
    return implode(', ', array_map(
        static fn(int $width): string => app_studio_note_embedded_image_url($post, $width) . ' ' . $width . 'w',
        [480, 768, 1200]
    ));
}

function app_saatchi_listing_url(array $artwork): string
{
    $url = trim((string)($artwork['saatchi_url'] ?? ''));
    if ($url === '') return '';
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    if (!str_starts_with($url, 'https://')
        || ($host !== 'saatchiart.com' && !str_ends_with($host, '.saatchiart.com'))) {
        return '';
    }
    $siteHost = (string)(parse_url((string)($GLOBALS['site']['url'] ?? ''), PHP_URL_HOST) ?: 'artist-site');
    return $url . (str_contains($url, '?') ? '&' : '?')
        . 'utm_source=' . rawurlencode($siteHost) . '&utm_medium=referral&utm_campaign=artwork_page';
}

function app_record_site_event(string $type, string $entityType = '', string $slug = '', string $locale = '', string $referrer = '', string $detail = ''): void
{
    try {
        $pdo = artist_site_database_connection(dirname(__DIR__) . '/platform');
        $ownerStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? LIMIT 1');
        $ownerStmt->execute([strtolower(resolved_artist_email())]);
        $ownerId = (int)($ownerStmt->fetchColumn() ?: 0);
        if ($ownerId <= 0) return;
        $referrerHost = strtolower((string)(parse_url($referrer, PHP_URL_HOST) ?: ''));
        $siteHost = strtolower((string)(parse_url((string)($GLOBALS['site']['url'] ?? ''), PHP_URL_HOST) ?: ''));
        if ($referrerHost === $siteHost) $referrerHost = '';
        $pdo->prepare('INSERT INTO artist_site_events (user_id,event_type,entity_type,slug,locale,referrer_host,detail,created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $ownerId,
                substr($type, 0, 40),
                substr(trim($entityType), 0, 30),
                substr(trim($slug), 0, 255),
                in_array($locale, ['es', 'en'], true) ? $locale : '',
                substr($referrerHost, 0, 255),
                substr(trim($detail), 0, 255),
                date(DATE_ATOM),
            ]);
    } catch (Throwable $error) {
        error_log('Site event logging failed: ' . $error->getMessage());
    }
}

function first_html_image_src(string $html): string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        $source = trim((string)$matches[1]);
        return preg_match('~^(?:data:|javascript:)~i', $source) ? '' : $source;
    }
    return '';
}

function published_dimensions(array $artwork): string
{
    $width = trim((string)($artwork['width'] ?? ''));
    $height = trim((string)($artwork['height'] ?? ''));
    if ($width === '' || $height === '') return '';
    $format = static fn(float $value): string => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    $cm = $format((float)$width) . ' × ' . $format((float)$height) . ' cm';
    $in = $format((float)$width / 2.54) . ' × ' . $format((float)$height / 2.54) . ' in';
    // Pulgadas primero: el mercado al que apunta el sitio es el estadounidense.
    return $in . ' / ' . $cm;
}

function render_published_constellation_map(array $soldLocations, array $items): void
{
    $locations = [];
    foreach ($soldLocations as $location) {
        $coordinates = map_location_coordinates($location);
        if ($coordinates) {
            $location['map_lat'] = $coordinates['lat'];
            $location['map_lng'] = $coordinates['lng'];
            $locations[] = $location;
        }
    }
    $mappedSlugs = array_values(array_filter(array_map(fn ($location) => $location['artwork_slug'] ?? '', $locations)));
    $mapItems = [];
    foreach ($locations as $location) {
        $artworkSlug = $location['artwork_slug'] ?? '';
        $artwork = $items[$artworkSlug] ?? null;
        if (!$artwork) {
            continue;
        }
        $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file'];
        $mapItems[] = [
            'lat' => (float) $location['map_lat'],
            'lng' => (float) $location['map_lng'],
            'title' => $artwork['title'] ?? ($location['title'] ?? 'Placed work'),
            'country' => $location['country'] ?? '',
            'postal_code' => $location['postal_code'] ?? '',
            'image' => app_publication_media_url($artwork, $cardImageFile, 480),
            'url' => url_for('artworks/' . $artworkSlug),
        ];
    }
    ?>
    <section class="section constellation-section" aria-label="World map of placed works">
        <div class="constellation-real-map" aria-label="Night world map of placed works" data-constellation-leaflet data-map-items="<?= e(json_encode($mapItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <div class="constellation-real-map__canvas" data-map-canvas aria-label="Placed works on the map"></div>
            <aside class="constellation-real-map__card" data-constellation-card>
                <?php if (!empty($mapItems[0]['image'])): ?>
                    <img data-constellation-image src="<?= e($mapItems[0]['image']) ?>" alt="">
                <?php else: ?>
                    <img data-constellation-image src="" alt="" hidden>
                <?php endif; ?>
                <div class="constellation-real-map__card-content">
                    <span class="constellation-real-map__eyebrow" data-constellation-place><?= e($mapItems[0]['country'] ?? '') ?></span>
                    <h3 class="constellation-real-map__title" data-constellation-title><?= e($mapItems[0]['title'] ?? '') ?></h3>
                    <a class="constellation-real-map__link" data-constellation-link href="<?= e($mapItems[0]['url'] ?? '#') ?>"><?= e(site_t('Study placement context', 'Ver contexto de emplazamiento')) ?></a>
                </div>
            </aside>
        </div>
    </section>
    <?php
}

function render_published_catalog(array $items, string $title = 'Artworks', string $intro = 'Selected works published by the artist, with their contextual studies and visual records.', string $eyebrow = 'Artwork Catalog', array $soldLocations = [], array $soldRecords = [], bool $isConstellations = false): void
{
    ?>
    <section class="page-hero <?= $isConstellations ? 'page-hero--compact' : '' ?>">
        <p class="eyebrow"><?= e($eyebrow) ?></p>
        <h1><?= e($title) ?></h1>
        <p><?= e($intro) ?></p>
    </section>
    <?php if ($isConstellations && !empty($soldLocations)): ?>
        <?php render_published_constellation_map($soldLocations, $items); ?>
    <?php endif; ?>
    <section class="section catalog-grid-section">
        <div class="art-grid">
            <?php foreach ($items as $slug => $artwork): ?>
                <?php $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']; ?>
                <article class="art-card">
                    <a class="art-card__image" href="<?= e(url_for('artworks/' . $slug)) ?>">
                        <img src="<?= e(app_publication_media_url($artwork, $cardImageFile, 480)) ?>"
                            srcset="<?= e(app_publication_media_srcset($artwork, $cardImageFile)) ?>"
                            sizes="(max-width: 940px) calc(100vw - 36px), 33vw"
                            alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' by ' . ($artistName ?? 'Artist')) ?>"
                            loading="lazy" decoding="async">
                    </a>
                    <div class="art-card__body">
                        <div class="eyebrow"><?= e($artwork['series'] ?: 'Original work') ?></div>
                        <h3><a href="<?= e(url_for('artworks/' . $slug)) ?>"><?= e($artwork['title']) ?></a></h3>
                        <p><?= e(implode(', ', array_filter([$artwork['medium'], published_dimensions($artwork)]))) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$items): ?><p><?= e(site_t('No artworks have been published here yet.', 'Todavía no se han publicado obras aquí.')) ?></p><?php endif; ?>
    </section>
    <?php if ($isConstellations && !empty($soldRecords)): ?>
        <section class="section">
            <div class="section-head">
                <div>
                    <p class="eyebrow"><?= e(site_t('Provenance map', 'Mapa de procedencia')) ?></p>
                    <h2><?= e(site_copy('constellations.title')) ?></h2>
                </div>
            </div>
            <div class="sold-table" role="table" aria-label="<?= e(site_copy('constellations.title')) ?>">
                <div class="sold-table__row sold-table__head" role="row">
                    <span><?= e(site_t('Artwork', 'Obra')) ?></span>
                    <span><?= e(site_t('Size', 'Medidas')) ?></span>
                    <span><?= e(site_t('Provenance', 'Procedencia')) ?></span>
                    <span><?= e(site_t('Status', 'Estado')) ?></span>
                    <span><?= e(site_t('Series', 'Serie')) ?></span>
                </div>
                <?php foreach ($soldRecords as $record): ?>
                    <a class="sold-table__row" role="row" href="<?= e($record['url']) ?>" target="_blank" rel="noopener">
                        <span><?= e($record['title']) ?>, <?= e($record['year']) ?></span>
                        <span><?= e($record['dimensions']) ?></span>
                        <span><?= e($record['platform']) ?></span>
                        <span><?= e($record['public_price']) ?></span>
                        <span><?= e($record['cluster']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?php
}

function render_published_artwork(array $site, array $artwork): void
{
    $summary = trim((string)($artwork['short_description'] ?: $artwork['description']));
    $metadata = $artwork['artwork_metadata'] ?? [];
    $analysis = $artwork['artwork_analysis'] ?? [];
    $facts = $analysis['confirmed_facts'] ?? [];
    $interpretation = $analysis['interpretation'] ?? [];
    $conceptualNote = trim((string)$artwork['description']);
    $imageCaption = trim((string)($metadata['caption'] ?? ''));
    // Studio Information was sourced from the Spanish-only AI analysis blob and
    // rendered on the EN page, surfacing Spanish text under an English heading
    // (and nothing on ES). Suppress it until a properly localized field exists;
    // the Conceptual Note already carries the localized interpretive reading.
    $studioInformation = '';
    $medium = trim((string)($artwork['medium'] ?: ($facts['medium'] ?? '')));
    $year = trim((string)($artwork['artwork_year'] ?: ($facts['year'] ?? '')));
    $mainImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file'];
    $galleryMockups = array_values(array_filter(
        (array)$artwork['items'],
        static fn (array $mockup): bool => basename((string)($mockup['mockup_file'] ?? '')) !== basename($mainImageFile)
    ));
    $artworkSeriesTitle = trim((string)($artwork['series'] ?? ''));
    $storeOffer = app_store()?->offerForArtwork((int)($artwork['canonical_artwork_id'] ?? 0));
    $publishedSeries = null;
    if ($artworkSeriesTitle !== '' && ($seriesCatalog = app_series_catalog())) {
        foreach ($seriesCatalog->all() as $candidateSeries) {
            if (strcasecmp(trim((string)($candidateSeries['title'] ?? '')), $artworkSeriesTitle) === 0) {
                $publishedSeries = $candidateSeries;
                break;
            }
        }
    }
    ?>
    <section class="artwork-detail">
        <div class="artwork-detail__image">
            <figure class="artwork-detail__primary-image">
                <img src="<?= e(app_publication_media_url($artwork, $mainImageFile, 1200)) ?>"
                    srcset="<?= e(app_publication_media_srcset($artwork, $mainImageFile)) ?>"
                    sizes="(max-width: 940px) calc(100vw - 36px), 62vw"
                    alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' original painting by Maurizio Valch') ?>"
                    fetchpriority="high" decoding="async">
                <?php if ($imageCaption !== ''): ?><figcaption><?= e($imageCaption) ?></figcaption><?php endif; ?>
            </figure>
            <?php if ($artwork['artwork_views'] && !$artwork['items']): ?>
                <div class="mockup-gallery artwork-view-gallery" aria-label="<?= e($artwork['title'] . site_t(' additional artwork views', ' vistas adicionales de la obra')) ?>">
                    <?php foreach ($artwork['artwork_views'] as $view): ?>
                        <?php
                        $viewLabel = match ((string)$view['view_type']) {
                            'three-quarter-left' => site_t('Three-quarter left view', 'Vista 3/4 izquierda'),
                            'three-quarter-right' => site_t('Three-quarter right view', 'Vista 3/4 derecha'),
                            default => site_t('Additional view', 'Vista adicional'),
                        };
                        $viewCaption = implode(' · ', array_filter([
                            (string)$site['name'],
                            (string)$artwork['title'],
                            published_dimensions($artwork),
                            $viewLabel,
                        ]));
                        ?>
                        <figure class="artwork-detail__supporting-image">
                            <a href="<?= e(app_publication_media_url($artwork, $view['file_name'])) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(app_publication_media_url($artwork, $view['file_name'], 768)) ?>"
                                    srcset="<?= e(app_publication_media_srcset($artwork, $view['file_name'])) ?>"
                                    sizes="(max-width: 940px) calc(100vw - 36px), 62vw"
                                    alt="<?= e($artwork['title'] . ' · ' . $viewLabel) ?>"
                                    loading="lazy" decoding="async">
                            </a>
                            <figcaption><?= e($viewCaption) ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($galleryMockups): ?>
                <div class="mockup-gallery" aria-label="<?= e($artwork['title'] . ' contextual mockups') ?>">
                    <?php foreach ($galleryMockups as $mockupIndex => $mockup): ?>
                        <?php
                        $mockupCaption = trim((string)($mockup['caption'] ?? ''));
                        $mockupTitle = trim((string)($mockup['title'] ?? ''));
                        if ($mockupCaption === '') {
                            $mockupCaption = $mockupTitle !== '' && strcasecmp($mockupTitle, (string)$artwork['title']) !== 0
                                ? $mockupTitle
                                : (string)$artwork['title'] . ' · ' . site_t('Context study ', 'Estudio de contexto ') . ((int)$mockupIndex + 1);
                        }
                        ?>
                        <figure class="artwork-detail__supporting-image">
                            <a class="mockup-gallery__link" href="<?= e(url_for('artworks/' . $artwork['slug'] . '/mockups/' . $mockup['public_slug'])) ?>">
                                <img src="<?= e(app_publication_media_url($artwork, $mockup['mockup_file'], 768)) ?>"
                                    srcset="<?= e(app_publication_media_srcset($artwork, $mockup['mockup_file'])) ?>"
                                    sizes="(max-width: 940px) calc(100vw - 36px), 62vw"
                                    alt="<?= e($mockup['alt_text'] ?: $mockup['title'] ?: $artwork['title'] . ' contextual mockup') ?>"
                                    loading="lazy" decoding="async">
                            </a>
                            <figcaption><?= e($mockupCaption) ?></figcaption>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="artwork-detail__content">
            <p class="eyebrow"><?= e(site_t('Published work', 'Obra publicada')) ?><?= $artwork['series'] ? ' / ' . e($artwork['series']) : '' ?></p>
            <h1><?= e($artwork['title']) ?></h1>
            <?php if ($artwork['subtitle']): ?><p class="lead"><?= e($artwork['subtitle']) ?></p><?php endif; ?>
            <dl class="specs">
                <?php if ($year): ?><div><dt><?= e(site_t('Year', 'Año')) ?></dt><dd><?= e($year) ?></dd></div><?php endif; ?>
                <?php if ($medium): ?><div><dt><?= e(site_t('Medium', 'Técnica')) ?></dt><dd><?= e($medium) ?></dd></div><?php endif; ?>
                <?php if (published_dimensions($artwork)): ?><div><dt><?= e(site_t('Size', 'Medidas')) ?></dt><dd><?= e(published_dimensions($artwork)) ?></dd></div><?php endif; ?>
                <?php if ($artworkSeriesTitle !== ''): ?>
                    <div>
                        <dt><?= e(site_t('Series', 'Serie')) ?></dt>
                        <dd>
                            <?php if ($publishedSeries): ?>
                                <?php
                                $seriesPreviewId = 'series-preview-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$publishedSeries['slug']);
                                $seriesYear = series_year_label($publishedSeries);
                                $seriesDescription = trim((string)($publishedSeries['description'] ?? ''));
                                ?>
                                <span class="artwork-series-reference">
                                    <a class="artwork-series-link" href="<?= e(url_for('series/' . $publishedSeries['slug'])) ?>" aria-describedby="<?= e($seriesPreviewId) ?>">
                                        <?= e($artworkSeriesTitle) ?>
                                    </a>
                                    <span class="artwork-series-preview" id="<?= e($seriesPreviewId) ?>" role="tooltip">
                                        <?php if (!empty($publishedSeries['header_file'])): ?>
                                            <span class="artwork-series-preview__image">
                                                <img src="<?= e(app_series_media_url($publishedSeries, (string)$publishedSeries['header_file'], 480)) ?>"
                                                    srcset="<?= e(app_series_media_srcset($publishedSeries, (string)$publishedSeries['header_file'])) ?>"
                                                    sizes="92px" alt="" style="<?= e(series_header_style($publishedSeries)) ?>" loading="lazy" decoding="async">
                                            </span>
                                        <?php endif; ?>
                                        <span class="artwork-series-preview__content">
                                            <span class="artwork-series-preview__eyebrow"><?= e(site_t('Painting series', 'Serie pictórica')) ?></span>
                                            <strong><?= e((string)$publishedSeries['title']) ?></strong>
                                            <span class="artwork-series-preview__meta">
                                                <?= $seriesYear !== '' ? e($seriesYear) . ' · ' : '' ?><?= (int)($publishedSeries['artwork_count'] ?? 0) ?> <?= (int)($publishedSeries['artwork_count'] ?? 0) === 1 ? e(site_t('work', 'obra')) : e(site_t('works', 'obras')) ?>
                                            </span>
                                            <?php if ($seriesDescription !== ''): ?><span class="artwork-series-preview__description"><?= e($seriesDescription) ?></span><?php endif; ?>
                                            <span class="artwork-series-preview__action"><?= e(site_t('View series', 'Ver serie')) ?></span>
                                        </span>
                                    </span>
                                </span>
                            <?php else: ?>
                                <?= e($artworkSeriesTitle) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($facts['orientation'])): ?><div><dt><?= e(site_t('Orientation', 'Orientación')) ?></dt><dd><?= e(ucfirst((string)$facts['orientation'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($facts['certificate_of_authenticity'])): ?><div><dt><?= e(site_t('Certificate', 'Certificado')) ?></dt><dd><?= e($facts['certificate_of_authenticity']) ?></dd></div><?php endif; ?>
                <div><dt><?= e(site_t('Context studies', 'Estudios de contexto')) ?></dt><dd><?= count($artwork['items']) ?></dd></div>
                <?php if (is_array($artwork['video'] ?? null)): ?>
                    <div class="artwork-video-spec">
                        <dt><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/></svg><?= e(site_t('Video', 'Video')) ?></dt>
                        <dd><a href="<?= e(url_for('artworks/' . $artwork['slug'] . '/video')) ?>"><?= e(published_video_duration((float)$artwork['video']['duration_seconds'])) ?> <span aria-hidden="true">→</span></a></dd>
                    </div>
                <?php endif; ?>
            </dl>
            <div class="prose">
                <?php if ($conceptualNote): ?><h2><?= e(site_t('Conceptual Note', 'Nota conceptual')) ?></h2><p><?= nl2br(e($conceptualNote)) ?></p><?php endif; ?>
                <?php if ($studioInformation): ?><h2><?= e(site_t('Studio Information', 'Información de estudio')) ?></h2><p><?= nl2br(e($studioInformation)) ?></p><?php endif; ?>
                <?php if (!empty($facts['shipping_notes'])): ?><h2><?= e(site_t('Shipping', 'Envío')) ?></h2><p><?= nl2br(e($facts['shipping_notes'])) ?></p><?php endif; ?>
            </div>
            <?php $saatchiListingUrl = app_saatchi_listing_url($artwork); ?>
            <?php if ($storeOffer && !empty($storeOffer['is_purchasable'])): ?>
                <aside class="store-offer" aria-label="<?= e(site_t('Acquisition information', 'Información de compra')) ?>">
                    <p class="eyebrow"><?= e(site_t('Available for acquisition', 'Disponible para adquisición')) ?></p>
                    <strong class="store-offer__price"><?= e(AppStore::money((int)$storeOffer['price_minor'], (string)$storeOffer['currency'])) ?></strong>
                    <p><?= e(site_t(
                        'Shipping is calculated from the destination country using the rate set for its continent.',
                        'El envío se calcula según el país de destino y la tarifa correspondiente a su continente.'
                    )) ?></p>
                    <?php if ($saatchiListingUrl !== ''): ?>
                        <div class="actions"><a class="button" href="<?= e($saatchiListingUrl) ?>" target="_blank" rel="noopener" data-metric-event="saatchi_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Buy on Saatchi Art', 'Comprar en Saatchi Art')) ?></a><a class="button button--quiet" href="<?= e(url_for('acquire/' . $artwork['slug'])) ?>" data-metric-event="acquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Acquire from the studio', 'Adquirir desde el estudio')) ?></a><a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>" data-metric-event="inquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a></div>
                        <p class="store-offer__assurance"><?= e(site_t("Purchase with Saatchi Art's buyer guarantee.", 'Compra con la garantía de Saatchi Art.')) ?></p>
                    <?php else: ?>
                        <div class="actions"><a class="button" href="<?= e(url_for('acquire/' . $artwork['slug'])) ?>" data-metric-event="acquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Acquire this work', 'Adquirir esta obra')) ?></a><a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>" data-metric-event="inquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a></div>
                    <?php endif; ?>
                </aside>
            <?php elseif ($storeOffer && ((string)$storeOffer['status'] === 'sold_out' || (int)$storeOffer['stock_available'] <= 0)): ?>
                <aside class="store-offer store-offer--unavailable"><p class="eyebrow"><?= e(site_t('No longer available', 'Ya no está disponible')) ?></p><p><?= e(site_t('This work is currently reserved or sold.', 'Esta obra se encuentra reservada o vendida.')) ?></p><div class="actions"><a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a></div></aside>
            <?php elseif ($saatchiListingUrl !== ''): ?>
                <aside class="store-offer" aria-label="<?= e(site_t('Acquisition information', 'Información de compra')) ?>">
                    <p class="eyebrow"><?= e(site_t('Available on Saatchi Art', 'Disponible en Saatchi Art')) ?></p>
                    <div class="actions"><a class="button" href="<?= e($saatchiListingUrl) ?>" target="_blank" rel="noopener" data-metric-event="saatchi_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Buy on Saatchi Art', 'Comprar en Saatchi Art')) ?></a><a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>" data-metric-event="inquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a></div>
                    <p class="store-offer__assurance"><?= e(site_t("Purchase with Saatchi Art's buyer guarantee.", 'Compra con la garantía de Saatchi Art.')) ?></p>
                </aside>
            <?php else: ?>
                <div class="actions"><a class="button" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>" data-metric-event="inquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>"><?= e(site_t('Inquire about this work', 'Consultar por esta obra')) ?></a></div>
            <?php endif; ?>
        </div>
    </section>
    <?php
    echo json_ld(published_artwork_schema($site, $artwork, $mainImageFile, $summary, $storeOffer));
}

/**
 * El bloque de datos estructurados de una obra publicada.
 *
 * Es el unico canal de vocabulario que los buscadores leen de verdad: el
 * <meta name="keywords"> lo ignoran desde 2009, asi que las palabras que
 * describen la obra tienen que viajar aca. Todo sale de datos que ya estan en la
 * base; no se inventa ni se redacta nada.
 *
 * @param array<string,mixed> $site
 * @param array<string,mixed> $artwork
 * @param array<string,mixed>|null $storeOffer
 * @return array<string,mixed>
 */
function published_artwork_schema(array $site, array $artwork, string $mainImageFile, string $summary, ?array $storeOffer = null): array
{
    $url = artist_site_url_with_language($site['url'] . '/artworks/' . $artwork['slug'] . '/', artist_site_language());

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'VisualArtwork',
        'name' => $artwork['title'],
        'creator' => ['@type' => 'Person', 'name' => $site['name']],
        'artform' => 'Painting',
        'artMedium' => $artwork['medium'],
        'dateCreated' => $artwork['artwork_year'],
        'image' => site_absolute_asset_url(app_publication_media_url($artwork, $mainImageFile), (string)$site['url']),
        'description' => $summary,
        'inLanguage' => artist_site_language(),
        'url' => $url,
    ];

    // Vocabulario de descubrimiento: los terminos de busqueda de la obra y sus
    // tags de catalogo, deduplicados sin distinguir mayusculas.
    //
    // Se leen las dos formas de clave a proposito: el catalogo devuelve la fila
    // cruda con 'artwork_keywords' y su version mapeada con 'keywords', y esta
    // funcion la llaman desde las dos. Leer una sola dejaba el campo vacio en la
    // pagina real mientras el test pasaba.
    $keywords = [];
    // Aca NO se filtra nada. La regla de excluir lenguaje de venta es de Saatchi,
    // donde hay doce slots y el formulario ya indexa categoria, medio y estilo:
    // alli gastar uno en "for sale" es tirarlo. En el sitio no hay tope, y
    // "painting for sale" o el nombre del artista con su categoria son las
    // busquedas de mayor intencion que existen. Filtrarlas costo seis terminos
    // buenos de dieciseis, y fue un error.
    //
    // El vocabulario de descubrimiento va PRIMERO: es el unico que trae terminos
    // emocionales y los nombres de los artistas afines a esta obra, que es lo que
    // ni los search_terms ni los tags del catalogo tienen.
    foreach ([
        (string)($artwork['artwork_discovery_keywords'] ?? ''),
        (string)($artwork['discovery_keywords'] ?? ''),
        (string)($artwork['artwork_keywords'] ?? ''),
        (string)($artwork['keywords'] ?? ''),
        (string)($artwork['artwork_tags'] ?? ''),
        (string)($artwork['tags'] ?? ''),
    ] as $fuente) {
        foreach (explode(',', $fuente) as $termino) {
            $termino = trim($termino);
            if ($termino === '' || isset($keywords[mb_strtolower($termino)])) {
                continue;
            }
            $keywords[mb_strtolower($termino)] = $termino;
        }
    }
    if ($keywords !== []) {
        $schema['keywords'] = implode(', ', array_values($keywords));
    }

    // La medida de una obra fisica es un dato de busqueda real, y hasta ahora no
    // salia. schema.org las quiere como QuantitativeValue con su unidad: CMT es
    // el codigo UN/CEFACT del centimetro.
    foreach (['width' => 'width', 'height' => 'height', 'depth' => 'depth'] as $campo => $propiedad) {
        $valor = trim((string)($artwork[$campo] ?? ''));
        if ($valor !== '' && (float)$valor > 0) {
            $schema[$propiedad] = ['@type' => 'QuantitativeValue', 'value' => (float)$valor, 'unitCode' => 'CMT'];
        }
    }

    // La serie: sin esto no hay forma de que un buscador entienda que varias
    // obras son un mismo cuerpo de trabajo.
    $serie = published_artwork_series($artwork);
    if ($serie !== null) {
        $schema['isPartOf'] = $serie;
    }

    if ($storeOffer && !empty($storeOffer['is_purchasable']) && (int)($storeOffer['price_minor'] ?? 0) > 0) {
        $schema['offers'] = [
            '@type' => 'Offer',
            'price' => number_format((int)$storeOffer['price_minor'] / 100, 2, '.', ''),
            'priceCurrency' => strtoupper((string)$storeOffer['currency']),
            'availability' => 'https://schema.org/InStock',
            'url' => artist_site_url_with_language($site['url'] . '/acquire/' . $artwork['slug'], artist_site_language()),
        ];
    } elseif ($storeOffer && ((string)($storeOffer['status'] ?? '') === 'sold_out' || (int)($storeOffer['stock_available'] ?? 0) <= 0)) {
        $schema['offers'] = [
            '@type' => 'Offer',
            'availability' => 'https://schema.org/SoldOut',
            'url' => $url,
        ];
    }

    // Una propiedad vacia no es dato: declarar artMedium:"" o dateCreated:"" es
    // ruido para el buscador. Si el valor no esta, la propiedad no viaja.
    return array_filter($schema, static fn (mixed $valor): bool => $valor !== '' && $valor !== null && $valor !== []);
}

/**
 * El titulo que se publica en la etiqueta <title>, dentro de lo que un buscador
 * muestra sin cortar.
 *
 * El molde es "obra | categoria | artista" y suele pasarse de largo: en este
 * catalogo 15 de 21 superan los 60 caracteres. Lo que se corta es el final, o
 * sea el nombre del artista — justo la busqueda de mayor intencion, la de quien
 * ya lo conoce.
 *
 * La regla: la obra y el artista NUNCA se tocan. La obra es identidad y no se
 * abrevia (Libro I); el nombre es lo que se estaba perdiendo. Se recorta solo la
 * parte del medio, empezando por las palabras mas genericas, que son las que no
 * posicionan en nada.
 *
 * No reescribe lo que el artista tiene guardado: ajusta lo que se muestra.
 */
function published_seo_title(string $stored, string $artworkTitle, string $artistName, int $max = 60): string
{
    $stored = trim(preg_replace('/\s+/u', ' ', $stored) ?? $stored);
    if ($stored === '' || mb_strlen($stored) <= $max) {
        return $stored;
    }

    $partes = array_values(array_filter(array_map('trim', explode('|', $stored)), 'strlen'));
    if (count($partes) < 2) {
        return $stored;
    }

    $obra = $partes[0];
    $artista = $partes[count($partes) - 1];
    $medio = implode(' | ', array_slice($partes, 1, -1));

    $arma = static fn (string $centro): string => $centro === ''
        ? $obra . ' | ' . $artista
        : $obra . ' | ' . $centro . ' | ' . $artista;

    // Primero caen las palabras que compiten con medio mundo y no distinguen
    // esta obra de ninguna otra.
    foreach (['Contemporary', 'Contemporánea', 'Contemporáneo', 'Original', 'Modern', 'Moderna', 'Moderno', 'Fine Art', 'Fine'] as $generico) {
        if (mb_strlen($arma($medio)) <= $max) {
            break;
        }
        $medio = trim(preg_replace('/\b' . preg_quote($generico, '/') . '\b\s*/iu', '', $medio) ?? $medio);
        $medio = trim(preg_replace('/\s+/u', ' ', $medio) ?? $medio);
    }

    // Si todavia no entra, cae la palabra mas larga de la categoria. No es un
    // truco de longitud: el nucleo que la gente busca es corto y comun
    // —"Abstract Painting", "Pintura Abstracta"— y los modificadores curatoriales
    // —"Territorial"— son largos y no los tipea nadie. Sacrificar el modificador
    // conserva justamente la parte que trae busquedas.
    while (mb_strlen($arma($medio)) > $max) {
        $palabras = preg_split('/\s+/u', $medio) ?: [];
        if (count($palabras) < 2) {
            break;
        }
        $masLarga = 0;
        foreach ($palabras as $indice => $palabra) {
            if (mb_strlen($palabra) > mb_strlen($palabras[$masLarga])) {
                $masLarga = $indice;
            }
        }
        unset($palabras[$masLarga]);
        $medio = implode(' ', $palabras);
    }

    if (mb_strlen($arma($medio)) <= $max) {
        return $arma($medio);
    }
    // Sin categoria que entre, la obra y el artista viajan solos: es mejor
    // perder la categoria que perder el nombre.
    return mb_strlen($arma('')) <= $max ? $arma('') : $obra;
}

/**
 * La serie de la obra como CreativeWork enlazable, resuelta contra el catalogo
 * publicado para tomar su slug. Devuelve null cuando la obra no tiene serie o
 * la serie no esta publicada: enlazar a una pagina que no existe seria peor que
 * no enlazar.
 *
 * @param array<string,mixed> $artwork
 * @return array<string,mixed>|null
 */
function published_artwork_series(array $artwork): ?array
{
    $seriesId = (int)($artwork['series_id'] ?? 0);
    $seriesName = trim((string)($artwork['series'] ?? ''));
    if ($seriesId <= 0 && $seriesName === '') {
        return null;
    }

    foreach (app_series_catalog()?->all() ?? [] as $candidate) {
        $coincide = ($seriesId > 0 && (int)($candidate['id'] ?? 0) === $seriesId)
            || ($seriesName !== '' && strcasecmp(trim((string)($candidate['title'] ?? '')), $seriesName) === 0);
        if (!$coincide) {
            continue;
        }
        $slug = trim((string)($candidate['slug'] ?? ''));
        $serie = ['@type' => 'CreativeWork', 'name' => (string)($candidate['title'] ?? $seriesName)];
        if ($slug !== '') {
            $serie['url'] = artist_site_url_with_language(
                (string)($GLOBALS['site']['url'] ?? '') . '/series/' . $slug . '/',
                artist_site_language()
            );
        }
        return $serie;
    }

    return $seriesName !== '' ? ['@type' => 'CreativeWork', 'name' => $seriesName] : null;
}

function render_published_artwork_video(array $site, array $artwork): void
{
    $video = is_array($artwork['video'] ?? null) ? $artwork['video'] : null;
    if (!$video) return;
    $notes = artwork_related_studio_notes($artwork);
    $series = null;
    foreach (app_series_catalog()?->all() ?? [] as $candidate) {
        if (((int)($artwork['series_id'] ?? 0) > 0 && (int)$candidate['id'] === (int)$artwork['series_id'])
            || strcasecmp(trim((string)($candidate['title'] ?? '')), trim((string)($artwork['series'] ?? ''))) === 0) {
            $series = $candidate;
            break;
        }
    }
    $analysis = is_array($artwork['artwork_analysis'] ?? null) ? $artwork['artwork_analysis'] : [];
    $facts = is_array($analysis['confirmed_facts'] ?? null) ? $analysis['confirmed_facts'] : [];
    $medium = trim((string)($artwork['medium'] ?: ($facts['medium'] ?? '')));
    $year = trim((string)($artwork['artwork_year'] ?: ($facts['year'] ?? '')));
    $conceptualNote = trim((string)($artwork['description'] ?? ''));
    if ($conceptualNote === '') {
        $conceptualNote = published_excerpt([
            $artwork['short_description'] ?? '',
            $artwork['seo_description'] ?? '',
            $artwork['artwork_metadata']['seo_description'] ?? '',
        ], 420);
    }
    $seriesContext = $series
        ? published_excerpt([
            $series['seo_description'] ?? '',
            $series['description'] ?? '',
            $series['long_description'] ?? '',
        ], 320)
        : '';
    $mockups = array_values(array_filter(
        (array)($artwork['items'] ?? []),
        static fn(array $mockup): bool => !empty($mockup['public_slug'])
    ));
    $favoriteMockups = array_values(array_filter(
        $mockups,
        static fn(array $mockup): bool => !empty($mockup['is_publication_favorite'])
    ));
    if ($favoriteMockups) $mockups = $favoriteMockups;
    $rotationDate = gmdate('Y-m-d');
    usort($mockups, static function (array $left, array $right) use ($artwork, $rotationDate): int {
        $seed = (string)($artwork['canonical_artwork_id'] ?? $artwork['slug'] ?? '');
        $leftKey = hash('sha256', $seed . '|' . $rotationDate . '|' . (string)($left['mockup_id'] ?? $left['mockup_file'] ?? ''));
        $rightKey = hash('sha256', $seed . '|' . $rotationDate . '|' . (string)($right['mockup_id'] ?? $right['mockup_file'] ?? ''));
        return $leftKey <=> $rightKey;
    });
    $mockups = array_slice($mockups, 0, 3);
    ?>
    <section class="artwork-detail artwork-video-detail">
        <div class="artwork-video-detail__viewer">
            <video controls playsinline preload="metadata"<?= !empty($video['has_poster']) ? ' poster="' . e(app_publication_video_url($artwork, true)) . '"' : '' ?>>
                <source src="<?= e(app_publication_video_url($artwork)) ?>">
            </video>
        </div>

        <div class="artwork-detail__content artwork-video-detail__content">
            <p class="eyebrow"><?= e(site_t('Published work', 'Obra publicada')) ?><?= $artwork['series'] ? ' / ' . e((string)$artwork['series']) : '' ?></p>
            <h1><?= e((string)$artwork['title']) ?></h1>
            <?php if (!empty($artwork['subtitle'])): ?><p class="lead"><?= e((string)$artwork['subtitle']) ?></p><?php endif; ?>
            <dl class="specs">
                <?php if ($year !== ''): ?><div><dt><?= e(site_t('Year', 'Año')) ?></dt><dd><?= e($year) ?></dd></div><?php endif; ?>
                <?php if ($medium !== ''): ?><div><dt><?= e(site_t('Medium', 'Técnica')) ?></dt><dd><?= e($medium) ?></dd></div><?php endif; ?>
                <?php if (published_dimensions($artwork) !== ''): ?><div><dt><?= e(site_t('Size', 'Medidas')) ?></dt><dd><?= e(published_dimensions($artwork)) ?></dd></div><?php endif; ?>
                <?php if (!empty($artwork['series'])): ?>
                    <div>
                        <dt><?= e(site_t('Series', 'Serie')) ?></dt>
                        <dd>
                            <?php if ($series): ?>
                                <?php
                                $seriesPreviewId = 'video-series-preview-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string)$series['slug']);
                                $seriesYear = series_year_label($series);
                                $seriesDescription = trim((string)($series['description'] ?? ''));
                                ?>
                                <span class="artwork-series-reference">
                                    <a class="artwork-series-link" href="<?= e(url_for('series/' . $series['slug'])) ?>" aria-describedby="<?= e($seriesPreviewId) ?>"><?= e((string)$artwork['series']) ?></a>
                                    <span class="artwork-series-preview" id="<?= e($seriesPreviewId) ?>" role="tooltip">
                                        <?php if (!empty($series['header_file'])): ?>
                                            <span class="artwork-series-preview__image">
                                                <img src="<?= e(app_series_media_url($series, (string)$series['header_file'], 480)) ?>"
                                                    srcset="<?= e(app_series_media_srcset($series, (string)$series['header_file'])) ?>"
                                                    sizes="92px" alt="" style="<?= e(series_header_style($series)) ?>" loading="lazy" decoding="async">
                                            </span>
                                        <?php endif; ?>
                                        <span class="artwork-series-preview__content">
                                            <span class="artwork-series-preview__eyebrow"><?= e(site_t('Painting series', 'Serie pictórica')) ?></span>
                                            <strong><?= e((string)$series['title']) ?></strong>
                                            <span class="artwork-series-preview__meta">
                                                <?= $seriesYear !== '' ? e($seriesYear) . ' · ' : '' ?><?= (int)($series['artwork_count'] ?? 0) ?> <?= (int)($series['artwork_count'] ?? 0) === 1 ? e(site_t('work', 'obra')) : e(site_t('works', 'obras')) ?>
                                            </span>
                                            <?php if ($seriesDescription !== ''): ?><span class="artwork-series-preview__description"><?= e($seriesDescription) ?></span><?php endif; ?>
                                            <span class="artwork-series-preview__action"><?= e(site_t('View series', 'Ver serie')) ?></span>
                                        </span>
                                    </span>
                                </span>
                            <?php else: ?>
                                <?= e((string)$artwork['series']) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($facts['orientation'])): ?><div><dt><?= e(site_t('Orientation', 'Orientación')) ?></dt><dd><?= e(ucfirst((string)$facts['orientation'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($facts['certificate_of_authenticity'])): ?><div><dt><?= e(site_t('Certificate', 'Certificado')) ?></dt><dd><?= e((string)$facts['certificate_of_authenticity']) ?></dd></div><?php endif; ?>
                <div><dt><?= e(site_t('Context studies', 'Estudios de contexto')) ?></dt><dd><?= count((array)($artwork['items'] ?? [])) ?></dd></div>
            </dl>
            <?php if ($conceptualNote !== ''): ?>
                <div class="prose">
                    <h2><?= e(site_t('Conceptual Note', 'Nota conceptual')) ?></h2>
                    <p><?= nl2br(e($conceptualNote)) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($seriesContext !== '' && $series): ?>
                <div class="prose artwork-video-series-context">
                    <h2><?= e(site_t('Within ', 'Dentro de ') . (string)$series['title']) ?></h2>
                    <p><?= e($seriesContext) ?></p>
                    <p class="artwork-video-series-context__link"><a href="<?= e(url_for('series/' . $series['slug'])) ?>"><?= e(site_t('View series', 'Ver serie')) ?> <span aria-hidden="true">→</span></a></p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="artwork-video-related">
        <section class="artwork-video-related__row">
            <h2><?= e(site_t('Related Studio Notes', 'Studio Notes relacionadas')) ?></h2>
            <div class="artwork-video-related__entries artwork-video-related__entries--notes">
                    <?php if ($notes): ?>
                        <?php foreach ($notes as $slug => $note): ?>
                            <?php $snippet = published_excerpt([$note['seo_description'] ?? '', $note['excerpt'] ?? '', $note['objective'] ?? '']); ?>
                            <article>
                                <h3><a href="<?= e(url_for('studio-notes/' . $slug)) ?>"><?= e((string)$note['title']) ?> <span aria-hidden="true">→</span></a></h3>
                                <?php if ($snippet !== ''): ?><p><?= e($snippet) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <article>
                            <h3><a href="<?= e(url_for('studio-notes')) ?>"><?= e(site_t('Concepts in Architectural Abstract Painting', 'Ideas sobre pintura abstracta, territorio y espacio')) ?> <span aria-hidden="true">→</span></a></h3>
                            <p><?= e(site_t(
                                'Reflections, essays, and notes on abstract painting, space, and territory.',
                                'Reflexiones, ensayos y apuntes sobre pintura abstracta, espacio y territorio.'
                            )) ?></p>
                        </article>
                    <?php endif; ?>
            </div>
        </section>

        <?php if ($series || !empty($artwork['series'])): ?>
            <section class="artwork-video-related__row">
                <h2><?= e(site_t('Series', 'Serie')) ?></h2>
                <div class="artwork-video-related__entries">
                    <?php $seriesText = $series ? published_excerpt([$series['seo_description'] ?? '', $series['description'] ?? '', $series['long_description'] ?? '']) : ''; ?>
                    <article>
                        <h3>
                            <?php if ($series): ?><a href="<?= e(url_for('series/' . $series['slug'])) ?>"><?= e((string)$series['title']) ?> <span aria-hidden="true">→</span></a>
                            <?php else: ?><?= e((string)$artwork['series']) ?><?php endif; ?>
                        </h3>
                        <?php if ($seriesText !== ''): ?><p><?= e($seriesText) ?></p><?php endif; ?>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($mockups): ?>
            <section class="artwork-video-related__row">
                <h2><?= e(site_t('Associated Mockups', 'Mockups asociados')) ?></h2>
                <div class="artwork-video-related__entries artwork-video-related__entries--mockups">
                        <?php foreach ($mockups as $mockup): ?>
                            <?php
                            $mockupTitle = published_excerpt([
                                $mockup['seo_title'] ?? '',
                                $mockup['title'] ?? '',
                                $mockup['caption'] ?? '',
                                (string)$artwork['title'] . ' — ' . site_t('Context Study', 'Estudio de contexto'),
                            ], 90);
                            $mockupText = published_excerpt([
                                $mockup['seo_description'] ?? '',
                                $mockup['description'] ?? '',
                                $mockup['caption'] ?? '',
                                $mockup['mockup_caption'] ?? '',
                                site_t('A contextual presentation of ', 'Una presentación contextual de ')
                                    . (string)$artwork['title']
                                    . site_t(' by Maurizio Valch.', ' por Maurizio Valch.'),
                            ]);
                            ?>
                            <article>
                                <h3><a href="<?= e(url_for('artworks/' . $artwork['slug'] . '/mockups/' . $mockup['public_slug'])) ?>"><?= e($mockupTitle) ?> <span aria-hidden="true">→</span></a></h3>
                                <?php if ($mockupText !== ''): ?><p><?= e($mockupText) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </section>
    <?php
    echo json_ld([
        '@context' => 'https://schema.org',
        '@type' => 'VideoObject',
        'name' => (string)$artwork['title'] . ' — ' . site_t('Artwork video', 'Video de la obra'),
        'description' => trim((string)($artwork['short_description'] ?: $artwork['description'])),
        'thumbnailUrl' => !empty($video['has_poster']) ? site_absolute_asset_url(app_publication_video_url($artwork, true), (string)$site['url']) : site_absolute_asset_url(app_publication_media_url($artwork, trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']), (string)$site['url']),
        'uploadDate' => (string)$video['published_at'],
        'duration' => 'PT' . max(1, (int)round((float)$video['duration_seconds'])) . 'S',
        'contentUrl' => site_absolute_asset_url(app_publication_video_url($artwork), (string)$site['url']),
        'inLanguage' => artist_site_language(),
    ]);
}

function render_acquisition(array $site, array $artwork): void
{
    $store = app_store();
    $payments = app_stripe_checkout();
    $offer = $store?->offerForArtwork((int)($artwork['canonical_artwork_id'] ?? 0));
    $error = '';
    $values = [
        'name' => '', 'email' => '', 'phone' => '', 'country_code' => '',
        'address_line_1' => '', 'address_line_2' => '', 'city' => '', 'region' => '',
        'postal_code' => '', 'message' => '',
    ];
    $receipt = null;
    if (isset($_GET['payment']) && (string)$_GET['payment'] === 'cancelled'
        && (string)($_SESSION['stripe_artwork_slug'] ?? '') === (string)$artwork['slug']) {
        $reservationReleased = false;
        try {
            if (!$payments) throw new RuntimeException('Stripe Checkout is unavailable.');
            $payments->cancelSession((string)($_SESSION['stripe_session_id'] ?? ''));
            $reservationReleased = true;
        } catch (Throwable $cancelError) {
            error_log('Stripe Checkout cancellation sync failed: ' . $cancelError->getMessage());
        }
        unset($_SESSION['stripe_session_id'], $_SESSION['stripe_order_id'], $_SESSION['stripe_artwork_slug'], $_SESSION['purchase_receipt']);
        $error = $reservationReleased
            ? 'Payment was cancelled. The artwork reservation has been released.'
            : 'Payment was cancelled. Stripe will release the temporary reservation automatically when the session expires.';
        $offer = $store?->offerForArtwork((int)($artwork['canonical_artwork_id'] ?? 0));
    }
    if (isset($_GET['submitted']) && is_array($_SESSION['purchase_receipt'] ?? null)) {
        $candidate = (array)$_SESSION['purchase_receipt'];
        unset($_SESSION['purchase_receipt']);
        if ((string)($candidate['artwork_slug'] ?? '') === (string)$artwork['slug']) $receipt = $candidate;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'acquire_submit') {
        foreach (array_keys($values) as $key) $values[$key] = trim((string)($_POST[$key] ?? ''));
        try {
            $token = (string)($_POST['csrf'] ?? '');
            if ($token === '' || empty($_SESSION['purchase_csrf']) || !hash_equals((string)$_SESSION['purchase_csrf'], $token)) {
                throw new RuntimeException('The form expired. Reload the page and try again.');
            }
            if ((string)($_POST['website'] ?? '') !== '') throw new RuntimeException('Your request could not be submitted.');
            if (empty($_POST['privacy_accepted'])) throw new RuntimeException('Accept the privacy notice to continue.');
            if (!$store) throw new RuntimeException('The acquisition service is temporarily unavailable.');
            $order = $store->createOrder($artwork, $_POST);
            $_SESSION['purchase_receipt'] = $order;
            $_SESSION['purchase_csrf'] = bin2hex(random_bytes(32));
            if ($offer && StripeCheckout::enabledForOffer($offer)) {
                if (!$payments) throw new RuntimeException('Secure payment is temporarily unavailable.');
                $session = $payments->createSession(
                    $order,
                    app_absolute_url('payment/success/') . '?session_id={CHECKOUT_SESSION_ID}',
                    app_absolute_url('acquire/' . rawurlencode((string)$artwork['slug']) . '/') . '?payment=cancelled'
                );
                $_SESSION['stripe_session_id'] = $session['id'];
                $_SESSION['stripe_order_id'] = (int)$order['id'];
                $_SESSION['stripe_artwork_slug'] = (string)$artwork['slug'];
                header('Location: ' . $session['url'], true, 303);
                exit;
            }
            $store->notifyOrder($order, $site);
            header('Location: ' . url_for('acquire/' . $artwork['slug']) . '?submitted=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            unset($_SESSION['purchase_receipt']);
            $error = $exception->getMessage();
            $offer = $store?->offerForArtwork((int)($artwork['canonical_artwork_id'] ?? 0));
        }
    }

    $mainImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file'];
    $stripeEnabled = $offer ? StripeCheckout::enabledForOffer($offer) : false;
    ?>
    <section class="page-hero page-hero--compact acquisition-heading">
        <p class="eyebrow">Private acquisition</p>
        <h1>Acquire <?= e((string)$artwork['title']) ?></h1>
        <p><?= $stripeEnabled ? 'Enter the delivery details, then continue to Stripe for secure payment.' : 'Submit the delivery details to reserve this work. The studio will confirm the request and payment separately.' ?></p>
    </section>
    <?php if ($receipt): ?>
        <section class="section acquisition-confirmation" role="status">
            <p class="eyebrow">Request received</p>
            <h2>Thank you, <?= e((string)$receipt['customer_name']) ?>.</h2>
            <p>The artwork has been reserved while the studio reviews your acquisition request.</p>
            <dl class="acquisition-totals">
                <div><dt>Reference</dt><dd><?= e((string)$receipt['public_number']) ?></dd></div>
                <div><dt>Artwork</dt><dd><?= e((string)$receipt['artwork_title']) ?></dd></div>
                <div><dt>Shipping</dt><dd><?= e(AppStore::money((int)$receipt['shipping_minor'], (string)$receipt['currency'])) ?></dd></div>
                <div class="acquisition-totals__total"><dt>Total</dt><dd><?= e(AppStore::money((int)$receipt['total_minor'], (string)$receipt['currency'])) ?></dd></div>
            </dl>
            <div class="actions"><a class="button" href="<?= e(url_for('artworks/' . $artwork['slug'])) ?>">Return to the artwork</a></div>
        </section>
    <?php elseif (!$offer || empty($offer['is_purchasable'])): ?>
        <section class="section acquisition-unavailable"><h2>This work is not currently available.</h2><p><?= e($error !== '' ? $error : 'It may already be reserved, sold, or awaiting a stock update.') ?></p><div class="actions"><a class="button" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>">Contact the studio</a><a class="button button--quiet" href="<?= e(url_for('artworks/' . $artwork['slug'])) ?>">Return to the artwork</a></div></section>
    <?php else: ?>
        <section class="acquisition-workspace">
            <aside class="acquisition-artwork">
                <img src="<?= e(app_publication_media_url($artwork, $mainImageFile, 768)) ?>"
                    srcset="<?= e(app_publication_media_srcset($artwork, $mainImageFile)) ?>"
                    sizes="(max-width: 940px) calc(100vw - 36px), 40vw"
                    alt="<?= e(($artwork['artwork_alt'] ?? '') ?: (string)$artwork['title']) ?>" fetchpriority="high" decoding="async">
                <div><p class="eyebrow">Original artwork</p><h2><?= e((string)$artwork['title']) ?></h2><?php if (published_dimensions($artwork)): ?><p><?= e(published_dimensions($artwork)) ?></p><?php endif; ?><strong><?= e(AppStore::money((int)$offer['price_minor'], (string)$offer['currency'])) ?></strong></div>
            </aside>
            <form method="post" class="acquisition-form" data-acquisition-form data-subtotal="<?= (int)$offer['price_minor'] ?>" data-currency="<?= e((string)$offer['currency']) ?>">
                <input type="hidden" name="action" value="acquire_submit"><input type="hidden" name="csrf" value="<?= e((string)($_SESSION['purchase_csrf'] ?? '')) ?>">
                <div class="form-honeypot" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
                <div class="acquisition-form__intro"><p class="eyebrow">Collector details</p><h2>Delivery and reservation</h2></div>
                <?php if ($error !== ''): ?><div class="acquisition-message acquisition-message--error" role="alert"><?= e($error) ?></div><?php endif; ?>
                <div class="acquisition-fields">
                    <label>Name<input name="name" value="<?= e($values['name']) ?>" autocomplete="name" required></label>
                    <label>Email<input type="email" name="email" value="<?= e($values['email']) ?>" autocomplete="email" required></label>
                    <label>Phone <small>Optional</small><input name="phone" value="<?= e($values['phone']) ?>" autocomplete="tel"></label>
                    <label>Destination country<select name="country_code" autocomplete="country" required data-destination-country><option value="">Select country</option><?php foreach (AppStore::countries() as $continentKey => $countries): ?><optgroup label="<?= e(AppStore::continents()[$continentKey]) ?>"><?php foreach ($countries as $countryCode => $countryName): ?><option value="<?= e($countryCode) ?>" data-continent="<?= e($continentKey) ?>" data-continent-label="<?= e(AppStore::continents()[$continentKey]) ?>" data-rate="<?= (int)$offer['shipping_rates'][$continentKey] ?>" <?= $values['country_code'] === $countryCode ? 'selected' : '' ?>><?= e($countryName) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
                    <label class="acquisition-field--wide">Address<input name="address_line_1" value="<?= e($values['address_line_1']) ?>" autocomplete="address-line1" required></label>
                    <label class="acquisition-field--wide">Address details <small>Optional</small><input name="address_line_2" value="<?= e($values['address_line_2']) ?>" autocomplete="address-line2"></label>
                    <label>City<input name="city" value="<?= e($values['city']) ?>" autocomplete="address-level2" required></label>
                    <label>State / Region <small>Optional</small><input name="region" value="<?= e($values['region']) ?>" autocomplete="address-level1"></label>
                    <label>Postal code <small>Optional</small><input name="postal_code" value="<?= e($values['postal_code']) ?>" autocomplete="postal-code"></label>
                    <label class="acquisition-field--wide">Message to the studio <small>Optional</small><textarea name="message" rows="4"><?= e($values['message']) ?></textarea></label>
                </div>
                <dl class="acquisition-totals" aria-live="polite">
                    <div><dt>Artwork</dt><dd><?= e(AppStore::money((int)$offer['price_minor'], (string)$offer['currency'])) ?></dd></div>
                    <div><dt>Shipping <span data-shipping-destination>Select destination</span></dt><dd data-shipping-total>—</dd></div>
                    <div class="acquisition-totals__total"><dt>Total</dt><dd data-order-total>—</dd></div>
                </dl>
                <?php if (trim((string)$offer['shipping_policy']) !== ''): ?><details><summary>Shipping and returns policy</summary><p><?= nl2br(e((string)$offer['shipping_policy'])) ?></p></details><?php endif; ?>
                <label class="acquisition-consent"><input type="checkbox" name="privacy_accepted" value="1" required><span>I agree that my contact and delivery details may be used to process this acquisition request. <a href="<?= e(url_for('privacy-policy')) ?>" target="_blank">Privacy policy</a>.</span></label>
                <button class="button acquisition-submit" type="submit"><?= $stripeEnabled ? 'Continue to secure payment' : 'Submit acquisition request' ?></button>
                <p class="acquisition-form__note"><?= $stripeEnabled ? 'The work is reserved for 30 minutes while payment is completed securely on Stripe.' : 'Submitting this request reserves the work temporarily. It does not process a payment.' ?></p>
            </form>
        </section>
        <script>
        (() => {
            const form = document.querySelector('[data-acquisition-form]');
            if (!form) return;
            const country = form.querySelector('[data-destination-country]');
            const shipping = form.querySelector('[data-shipping-total]');
            const total = form.querySelector('[data-order-total]');
            const destination = form.querySelector('[data-shipping-destination]');
            const subtotal = Number(form.dataset.subtotal || 0);
            const currency = form.dataset.currency || 'EUR';
            const money = value => new Intl.NumberFormat('en', { style: 'currency', currency }).format(value / 100);
            const update = () => {
                const option = country.options[country.selectedIndex];
                if (!option || !option.dataset.rate) { shipping.textContent = '—'; total.textContent = '—'; destination.textContent = 'Select destination'; return; }
                const rate = Number(option.dataset.rate);
                shipping.textContent = money(rate);
                total.textContent = money(subtotal + rate);
                destination.textContent = '· ' + option.dataset.continentLabel;
            };
            country.addEventListener('change', update);
            update();
        })();
        </script>
    <?php endif; ?>
    <?php
}

function render_stripe_payment_result(): void
{
    $sessionId = trim((string)($_GET['session_id'] ?? ''));
    $expectedSessionId = (string)($_SESSION['stripe_session_id'] ?? '');
    $orderId = (int)($_SESSION['stripe_order_id'] ?? 0);
    $receipt = is_array($_SESSION['purchase_receipt'] ?? null) ? (array)$_SESSION['purchase_receipt'] : [];
    $error = '';
    $order = null;

    try {
        if ($sessionId === '' || $expectedSessionId === '' || !hash_equals($expectedSessionId, $sessionId) || $orderId <= 0) {
            throw new RuntimeException('This payment confirmation link is invalid or has expired.');
        }
        $payments = app_stripe_checkout();
        if (!$payments) throw new RuntimeException('Payment confirmation is temporarily unavailable.');
        $payments->syncSession($sessionId);
        $order = $payments->receipt($orderId, $sessionId);
        if (!$order) throw new RuntimeException('The Stripe payment could not be matched to an order.');
    } catch (Throwable $paymentError) {
        error_log('Stripe Checkout return sync failed: ' . $paymentError->getMessage());
        $error = $paymentError->getMessage();
    }

    unset($_SESSION['stripe_session_id'], $_SESSION['stripe_order_id'], $_SESSION['stripe_artwork_slug'], $_SESSION['purchase_receipt']);
    $paid = $order && (string)$order['payment_status'] === 'paid';
    ?>
    <section class="page-hero page-hero--compact acquisition-heading">
        <p class="eyebrow"><?= $paid ? 'Payment confirmed' : 'Payment processing' ?></p>
        <h1><?= $paid ? 'Thank you for your acquisition.' : 'We are checking your payment.' ?></h1>
        <?php if ($paid): ?><p>Your payment was received and the studio will contact you regarding shipment.</p><?php elseif ($error !== ''): ?><p><?= e($error) ?></p><?php else: ?><p>The order remains reserved while Stripe confirms the payment.</p><?php endif; ?>
    </section>
    <?php if ($order): ?>
        <section class="section acquisition-confirmation" role="status">
            <dl class="acquisition-totals">
                <div><dt>Reference</dt><dd><?= e((string)$order['public_number']) ?></dd></div>
                <?php if ((string)($receipt['artwork_title'] ?? '') !== ''): ?><div><dt>Artwork</dt><dd><?= e((string)$receipt['artwork_title']) ?></dd></div><?php endif; ?>
                <div><dt>Shipping</dt><dd><?= e(AppStore::money((int)$order['shipping_minor'], (string)$order['currency'])) ?></dd></div>
                <div class="acquisition-totals__total"><dt>Total paid</dt><dd><?= e(AppStore::money((int)$order['total_minor'], (string)$order['currency'])) ?></dd></div>
            </dl>
            <div class="actions"><a class="button" href="<?= e(url_for('/')) ?>">Return to the artist website</a></div>
        </section>
    <?php else: ?>
        <section class="section acquisition-unavailable"><p>If you completed the payment, keep your Stripe receipt and contact the studio so the reference can be verified.</p><div class="actions"><a class="button" href="<?= e(url_for('contact')) ?>">Contact the studio</a></div></section>
    <?php endif; ?>
    <?php
}

function render_published_mockup(array $site, array $artwork, array $mockup): void
{
    $mockupTitle = trim((string)($mockup['title'] ?? ''));
    $title = $mockupTitle !== '' && strcasecmp($mockupTitle, (string)$artwork['title']) !== 0
        ? $mockupTitle
        : $artwork['title'] . ' — ' . site_t('Context Study', 'Estudio de contexto');
    $description = trim((string)($mockup['description'] ?: $mockup['caption'] ?: site_t('A contextual presentation of ', 'Una presentación contextual de ') . $artwork['title'] . site_t(' by Maurizio Valch.', ' por Maurizio Valch.')));
    $imageCaption = trim((string)($mockup['caption'] ?? ''));
    $storeOffer = app_store()?->offerForArtwork((int)($artwork['canonical_artwork_id'] ?? 0));
    ?>
    <section class="artwork-detail mockup-landing">
        <div class="artwork-detail__image">
            <figure class="artwork-detail__primary-image">
                <img src="<?= e(app_publication_media_url($artwork, $mockup['mockup_file'], 1200)) ?>"
                    srcset="<?= e(app_publication_media_srcset($artwork, $mockup['mockup_file'])) ?>"
                    sizes="(max-width: 940px) calc(100vw - 36px), 62vw"
                    alt="<?= e($mockup['alt_text'] ?: $title) ?>" fetchpriority="high" decoding="async">
                <?php if ($imageCaption !== ''): ?><figcaption><?= e($imageCaption) ?></figcaption><?php endif; ?>
            </figure>
        </div>
        <div class="artwork-detail__content">
            <p class="eyebrow"><?= e(site_t('Context study', 'Estudio de contexto')) ?> / <?= e($artwork['title']) ?></p>
            <h1><?= e($title) ?></h1>
            <div class="prose"><p><?= nl2br(e($description)) ?></p></div>
            <dl class="specs">
                <div><dt><?= e(site_t('Original artwork', 'Obra original')) ?></dt><dd><?= e($artwork['title']) ?></dd></div>
                <?php if ($artwork['medium']): ?><div><dt><?= e(site_t('Medium', 'Técnica')) ?></dt><dd><?= e($artwork['medium']) ?></dd></div><?php endif; ?>
                <?php if (published_dimensions($artwork)): ?><div><dt><?= e(site_t('Actual dimensions', 'Medidas reales')) ?></dt><dd><?= e(published_dimensions($artwork)) ?></dd></div><?php endif; ?>
                <?php if ($artwork['artwork_year']): ?><div><dt><?= e(site_t('Year', 'Año')) ?></dt><dd><?= e($artwork['artwork_year']) ?></dd></div><?php endif; ?>
                <div><dt><?= e(site_t('Presentation', 'Presentación')) ?></dt><dd><?= e(site_t('Digital contextual visualization', 'Visualización contextual digital')) ?></dd></div>
            </dl>
            <?php if (trim((string)$mockup['keywords'])): ?>
                <div class="prose"><h2><?= e(site_t('Context references', 'Referencias de contexto')) ?></h2><p><?= e(str_replace(',', ' · ', (string)$mockup['keywords'])) ?></p></div>
            <?php endif; ?>
            <div class="prose">
                <h2><?= e(site_t('About this visualization', 'Sobre esta visualización')) ?></h2>
                <p><?= e(site_t('This visualization places the artwork within an architectural context to help collectors and viewers imagine its presence in a real space. It is an interpretive reference, not an exact reproduction: the original work remains the sole authority for scale, color, surface and material presence.', 'Esta visualización sitúa la obra dentro de un contexto arquitectónico para ayudar a coleccionistas y público a imaginar su presencia en un espacio real. Es una referencia interpretativa, no una reproducción exacta: la obra original es la única autoridad sobre la escala, el color, la superficie y la presencia material.')) ?></p>
            </div>
            <?php $saatchiListingUrl = app_saatchi_listing_url($artwork); ?>
            <?php if ($storeOffer && !empty($storeOffer['is_purchasable'])): ?>
                <aside class="store-offer" aria-label="<?= e(site_t('Acquisition information', 'Información de compra')) ?>">
                    <p class="eyebrow"><?= e(site_t('Original artwork available for acquisition', 'Obra original disponible para compra')) ?></p>
                    <strong class="store-offer__price"><?= e(AppStore::money((int)$storeOffer['price_minor'], (string)$storeOffer['currency'])) ?></strong>
                    <p><?= e(site_t('The price and purchase option apply to the original artwork shown in this contextual visualization. Shipping is calculated from the destination country.', 'El precio y la opción de compra corresponden a la obra original mostrada en esta visualización contextual. El envío se calcula según el país de destino.')) ?></p>
                    <div class="actions">
                        <?php if ($saatchiListingUrl !== ''): ?>
                            <a class="button" href="<?= e($saatchiListingUrl) ?>" target="_blank" rel="noopener" data-metric-event="saatchi_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>" data-metric-detail="mockup_page"><?= e(site_t('Buy on Saatchi Art', 'Comprar en Saatchi Art')) ?></a>
                            <a class="button button--quiet" href="<?= e(url_for('acquire/' . $artwork['slug'])) ?>" data-metric-event="acquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>" data-metric-detail="mockup_page"><?= e(site_t('Acquire from the studio', 'Adquirir desde el estudio')) ?></a>
                        <?php else: ?>
                            <a class="button" href="<?= e(url_for('acquire/' . $artwork['slug'])) ?>" data-metric-event="acquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>" data-metric-detail="mockup_page"><?= e(site_t('Acquire this work', 'Comprar esta obra')) ?></a>
                        <?php endif; ?>
                        <a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>" data-metric-event="inquire_click" data-metric-entity="artwork" data-metric-slug="<?= e($artwork['slug']) ?>" data-metric-detail="mockup_page"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a>
                    </div>
                    <?php if ($saatchiListingUrl !== ''): ?><p class="store-offer__assurance"><?= e(site_t("Purchase with Saatchi Art's buyer guarantee.", 'Compra con la garantía de Saatchi Art.')) ?></p><?php endif; ?>
                </aside>
            <?php elseif ($storeOffer && ((string)$storeOffer['status'] === 'sold_out' || (int)$storeOffer['stock_available'] <= 0)): ?>
                <aside class="store-offer store-offer--unavailable">
                    <p class="eyebrow"><?= e(site_t('Original artwork no longer available', 'Obra original no disponible')) ?></p>
                    <p><?= e(site_t('This work is currently reserved or sold.', 'Esta obra está reservada o vendida actualmente.')) ?></p>
                    <div class="actions"><a class="button button--quiet" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>"><?= e(site_t('Ask the studio', 'Consultar al estudio')) ?></a></div>
                </aside>
            <?php else: ?>
                <div class="actions">
                    <a class="button" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>"><?= e(site_t('Inquire about the original work', 'Consultar por la obra original')) ?></a>
                </div>
            <?php endif; ?>
            <div class="actions mockup-landing__return">
                <a class="button button--quiet" href="<?= e(url_for('artworks/' . $artwork['slug'])) ?>"><?= e(site_t('Return to the artwork', 'Volver a la obra')) ?></a>
            </div>
        </div>
    </section>
    <?php
    echo json_ld(['@context'=>'https://schema.org','@type'=>'ImageObject','name'=>$title,'description'=>$description,'contentUrl'=>app_publication_media_url($artwork,$mockup['mockup_file']),'creator'=>['@type'=>'Person','name'=>$site['name']],'inLanguage'=>artist_site_language(),'isPartOf'=>['@type'=>'VisualArtwork','name'=>$artwork['title'],'url'=>artist_site_url_with_language($site['url'].'/artworks/'.$artwork['slug'].'/',artist_site_language())]]);
}

function render_series_index(array $series, array $artworks): void
{
    $currentSeries = ['structural-metaphysical-painting', 'strata-series-maurizio-valch'];
    $earlierWorks = array_filter($series, fn ($item, $slug) => !in_array($slug, $currentSeries, true), ARRAY_FILTER_USE_BOTH);
    ?>
    <section class="page-hero">
        <p class="eyebrow">Concept clusters</p>
        <h1>Painting Series</h1>
        <p>Series pages connect Maurizio Valch's current language: structural metaphysical painting, strata, fault lines, ground frequency, monoliths, horizons, and silence.</p>
    </section>
    <section class="section">
        <div class="series-grid series-grid--primary">
            <?php foreach ($currentSeries as $slug): ?>
                <?php if (!isset($series[$slug])) continue; ?>
                <?php
                $item = $series[$slug];
                $seriesImage = series_representative_image($slug, $item, $artworks);
                ?>
                <a class="series-card" href="<?= e(url_for('series/' . $slug)) ?>">
                    <?php if ($seriesImage): ?>
                        <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item['title'] . ' series thumbnail') ?>">
                    <?php endif; ?>
                    <span><?= e($item['title']) ?></span>
                    <p><?= e($item['description']) ?></p>
                </a>
            <?php endforeach; ?>
            <article class="series-card series-card--earlier">
                <span>Earlier Works</span>
                <?php foreach ($earlierWorks as $slug => $item): ?>
                    <?php $seriesImage = series_representative_image($slug, $item, $artworks); ?>
                    <a class="earlier-work" href="<?= e(url_for('series/' . $slug)) ?>">
                        <?php if ($seriesImage): ?>
                            <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item['title'] . ' series thumbnail') ?>">
                        <?php endif; ?>
                        <div>
                            <strong><?= e($item['title']) ?></strong>
                            <p><?= e($item['description']) ?></p>
                        </div>
                    </a>
                <?php endforeach; ?>
            </article>
        </div>
    </section>
    <?php
}

function render_series_detail(array $site, array $series, array $artworks, string $slug): bool
{
    if (!isset($series[$slug])) {
        return false;
    }
    $item = $series[$slug];
    $items = artworks_by_series($artworks, $slug);
    $seriesImage = series_representative_image($slug, $item, $artworks);
    ?>
    <section class="page-hero">
        <p class="eyebrow">Series</p>
        <h1><?= e($item['title']) ?></h1>
        <p><?= e($item['description']) ?></p>
    </section>
    <?php if ($seriesImage): ?>
        <section class="section series-hero-image">
            <a href="<?= e(asset_url($seriesImage)) ?>" target="_blank" rel="noopener" title="Ver imagen completa">
                <img src="<?= e(asset_url($seriesImage)) ?>" alt="<?= e($item['title'] . ' representative image') ?>">
            </a>
        </section>
    <?php endif; ?>
    <section class="section section--split">
        <div>
            <h2>Search Language</h2>
        </div>
        <div class="keyword-list">
            <?php foreach ($item['keywords'] as $keyword): ?>
                <span><?= e($keyword) ?></span>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="section">
        <div class="section-head">
            <h2>Works in this series</h2>
            <a href="<?= e(url_for('paintings')) ?>">Full catalog</a>
        </div>
        <div class="art-grid">
            <?php foreach ($items as $artworkSlug => $artwork) render_artwork_card($artworkSlug, $artwork, $series); ?>
        </div>
    </section>
    <?php
    return true;
}

function series_header_style(array $series): string
{
    $focalX = max(0, min(100, (int)($series['header_focal_x'] ?? 50)));
    $focalY = max(0, min(100, (int)($series['header_focal_y'] ?? 50)));
    $zoom = max(115, min(400, (int)($series['header_zoom'] ?? 115)));
    return 'object-position: ' . $focalX . '% ' . $focalY . '%; transform: scale(' . ($zoom / 100) . ');';
}

function series_year_label(array $series): string
{
    $start = $series['year_start'] ?? null;
    $end = $series['year_end'] ?? null;
    if ($start === null && $end === null) return '';
    if ($start !== null && $end !== null) return $start == $end ? (string)$start : "{$start}–{$end}";
    if ($start !== null) return "{$start}–Present";
    return (string)$end;
}

function render_published_series_index(array $items): void
{
    $current = [];
    $earlier = [];
    foreach ($items as $slug => $item) {
        if (!empty($item['year_end']) && (int)$item['year_end'] < (int)date('Y')) {
            $earlier[$slug] = $item;
        } else {
            $current[$slug] = $item;
        }
    }
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_t('Concept clusters', 'Núcleos conceptuales')) ?></p>
        <h1><?= e(site_t('Painting Series', 'Series pictóricas')) ?></h1>
        <p><?= e(site_t('Series managed from Artwork Mockups and published as part of the artist catalogue.', 'Series gestionadas desde Artwork Mockups y publicadas como parte del catálogo del artista.')) ?></p>
    </section>
    <section class="section">
        <?php if (!$items): ?>
            <p><?= e(site_t('No series have been published yet.', 'Todavía no se han publicado series.')) ?></p>
        <?php else: ?>
            <div class="series-grid series-grid--primary">
                <?php foreach ($current as $slug => $item): ?>
                    <?php $yearLabel = series_year_label($item); ?>
                    <a class="series-card" href="<?= e(url_for('series2/' . $slug)) ?>">
                        <?php if ($item['header_file']): ?>
                            <div class="series-card__image-container">
                                <img src="<?= e(app_series_media_url($item, (string)$item['header_file'], 480)) ?>"
                                    srcset="<?= e(app_series_media_srcset($item, (string)$item['header_file'])) ?>"
                                    sizes="(max-width: 940px) calc(100vw - 72px), 25vw"
                                    alt="<?= e($item['title'] . ' series thumbnail') ?>" style="<?= e(series_header_style($item)) ?>" loading="lazy" decoding="async">
                            </div>
                        <?php endif; ?>
                        <span><?= e($item['title']) ?><?= $yearLabel !== '' ? ' (' . e($yearLabel) . ')' : '' ?></span>
                        <p><?= e($item['description']) ?></p>
                    </a>
                <?php endforeach; ?>

                <?php if ($earlier): ?>
                    <article class="series-card series-card--earlier">
                        <span><?= e(site_t('Earlier Works', 'Obras anteriores')) ?></span>
                        <?php foreach ($earlier as $slug => $item): ?>
                            <?php $yearLabel = series_year_label($item); ?>
                            <a class="earlier-work" href="<?= e(url_for('series2/' . $slug)) ?>">
                                <?php if ($item['header_file']): ?>
                                    <div class="earlier-work__image-container">
                                        <img src="<?= e(app_series_media_url($item, (string)$item['header_file'], 480)) ?>"
                                            srcset="<?= e(app_series_media_srcset($item, (string)$item['header_file'])) ?>"
                                            sizes="(max-width: 940px) calc(100vw - 72px), 33vw"
                                            alt="<?= e($item['title'] . ' series thumbnail') ?>" style="<?= e(series_header_style($item)) ?>" loading="lazy" decoding="async">
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <strong><?= e($item['title']) ?><?= $yearLabel !== '' ? ' ' . e($yearLabel) : '' ?></strong>
                                    <p><?= e($item['description']) ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </article>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_published_series_detail(array $item): void
{
    $yearLabel = series_year_label($item);
    $seriesTitle = trim((string)($item['title'] ?? ''));
    $publishedArtworks = app_catalog()?->all() ?? [];
    $dependentArtworks = array_filter(
        $publishedArtworks,
        static fn(array $artwork): bool => strcasecmp(trim((string)($artwork['series'] ?? '')), $seriesTitle) === 0
    );
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_t('Series', 'Serie')) ?><?= $yearLabel !== '' ? ' · ' . e($yearLabel) : '' ?></p>
        <h1><?= e($item['title']) ?></h1>
        <?php if (trim((string)($item['subtitle'] ?? '')) !== ''): ?><p><?= e($item['subtitle']) ?></p><?php endif; ?>
    </section>
    <?php if ($item['header_file']): ?>
        <section class="section series-preview-hero">
            <img src="<?= e(app_series_media_url($item, (string)$item['header_file'], 1200)) ?>"
                srcset="<?= e(app_series_media_srcset($item, (string)$item['header_file'])) ?>"
                sizes="(max-width: 940px) calc(100vw - 36px), 760px"
                alt="<?= e($item['title'] . site_t(' representative image', ' — imagen representativa')) ?>" style="<?= e(series_header_style($item)) ?>" fetchpriority="high" decoding="async">
        </section>
    <?php endif; ?>
    <section class="section section--split">
        <div>
            <h2><?= e(site_t('About this series', 'Sobre esta serie')) ?></h2>
            <p><?= nl2br(e((string)($item['description'] ?? ''))) ?></p>
        </div>
        <?php if (trim((string)($item['long_description'] ?? '')) !== ''): ?>
            <div><?= nl2br(e((string)$item['long_description'])) ?></div>
        <?php endif; ?>
    </section>
    <?php if (trim((string)($item['tags'] ?? '')) !== ''): ?>
        <section class="section">
            <div class="section-head"><h2><?= e(site_t('Tags', 'Etiquetas')) ?></h2></div>
            <div class="keyword-list">
                <?php foreach (array_filter(array_map('trim', explode(',', (string)$item['tags']))) as $tag): ?>
                    <span><?= e($tag) ?></span>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <section class="section catalog-grid-section series-dependent-artworks">
        <div class="section-head">
            <div>
                <p class="eyebrow"><?= e(site_t('Series works', 'Obras de la serie')) ?></p>
                <h2><?= e(site_t('Works in this series', 'Obras de esta serie')) ?></h2>
            </div>
        </div>
        <?php if ($dependentArtworks): ?>
            <div class="art-grid">
                <?php foreach ($dependentArtworks as $slug => $artwork): ?>
                    <?php $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']; ?>
                    <article class="art-card">
                        <a class="art-card__image" href="<?= e(url_for('artworks/' . $slug)) ?>">
                            <img src="<?= e(app_publication_media_url($artwork, $cardImageFile, 480)) ?>"
                                srcset="<?= e(app_publication_media_srcset($artwork, $cardImageFile)) ?>"
                                sizes="(max-width: 940px) calc(100vw - 36px), 33vw"
                                alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' artwork') ?>" loading="lazy" decoding="async">
                        </a>
                        <div class="art-card__body">
                            <div class="eyebrow"><?= e($seriesTitle) ?></div>
                            <h3><a href="<?= e(url_for('artworks/' . $slug)) ?>"><?= e($artwork['title']) ?></a></h3>
                            <p><?= e(implode(', ', array_filter([$artwork['medium'], published_dimensions($artwork)]))) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p><?= e(site_t('No works have been published in this series yet.', 'Todavía no se han publicado obras en esta serie.')) ?></p>
        <?php endif; ?>
    </section>
    <p><a href="<?= e(url_for('series')) ?>"><?= e(site_t('Back to Series', 'Volver a Series')) ?></a></p>
    <?php
}

function localized_artist_profile(array $profile): array
{
    foreach ([
        'short_bio',
        'statement',
        'visual_language',
        'materials',
        'recurring_themes',
        'palette_notes',
    ] as $field) {
        $profile[$field] = site_copy('artist.' . $field);
    }
    return $profile;
}

function render_published_artist_page(array $profile): void
{
    $photoFile = trim((string)($profile['photo_file'] ?? ''));
    $portraitImage = $photoFile !== '' ? app_artist_photo_url($photoFile) : '';
    $portraitCanRender = $portraitImage !== '';

    $processItems = [];
    if (trim((string)($profile['visual_language'] ?? '')) !== '') {
        $processItems[] = ['title' => site_t('Core Visual Language', 'Lenguaje visual'), 'description' => $profile['visual_language']];
    }
    if (trim((string)($profile['materials'] ?? '')) !== '') {
        $processItems[] = ['title' => site_t('Materials & Process', 'Materiales y proceso'), 'description' => $profile['materials']];
    }
    if (trim((string)($profile['recurring_themes'] ?? '')) !== '') {
        $processItems[] = ['title' => site_t('Recurring Themes', 'Temas recurrentes'), 'description' => $profile['recurring_themes']];
    }
    if (trim((string)($profile['palette_notes'] ?? '')) !== '') {
        $processItems[] = ['title' => site_t('Atmosphere & Palette', 'Atmósfera y paleta'), 'description' => $profile['palette_notes']];
    }

    // La columna nace en una migracion posterior al despliegue actual: si todavia
    // no esta, la clave no viene y la seccion sencillamente no se dibuja.
    // Los fundamentos se muestran en el idioma de la pagina; si ese bloque no
    // existe, se usa el primero que haya escrito el artista.
    $references = AppArtistReferences::parse((string)($profile['reference_artists'] ?? ''), artist_site_language());
    ?>
    <section class="page-hero artist-page-hero">
        <p class="eyebrow"><?= e(site_t('Artist profile', 'Perfil del artista')) ?></p>
        <h1><?= e($profile['artist_name'] ?: 'Maurizio Valch') ?></h1>
    </section>
    <section class="section artist-profile-block<?= $portraitCanRender ? '' : ' artist-profile-block--text-only' ?>">
        <?php if ($portraitCanRender): ?>
            <figure class="artist-profile-block__portrait">
                <img src="<?= e($portraitImage) ?>" alt="<?= e(site_t('Portrait of ', 'Retrato de ') . ($profile['artist_name'] ?: 'Maurizio Valch')) ?>">
            </figure>
        <?php endif; ?>
        <div class="prose">
            <p><?= nl2br(e($profile['short_bio'])) ?></p>
        </div>
    </section>
    <?php if (trim((string)($profile['statement'] ?? '')) !== ''): ?>
        <section class="section artist-statement-excerpt">
            <div>
                <p class="eyebrow"><?= e(site_t('Artist statement', 'Declaración artística')) ?></p>
                <h2><?= e(site_t('Statement Excerpt', 'Fragmento de la declaración')) ?></h2>
            </div>
            <div class="prose">
                <p><?= nl2br(e($profile['statement'])) ?></p>
                <a class="button button--quiet" href="<?= e(url_for('artist-statement')) ?>"><?= e(site_t('Full Statement', 'Declaración completa')) ?></a>
            </div>
        </section>
    <?php endif; ?>
    
    <?php if ($processItems): ?>
        <section class="section">
            <div class="section-head section-head--simple">
                <h2><?= e(site_t('Artistic Process & Language', 'Proceso y lenguaje artístico')) ?></h2>
            </div>
            <div class="artist-genealogy">
                <?php foreach ($processItems as $item): ?>
                    <article>
                        <span><?= e($item['title']) ?></span>
                        <p><?= e($item['description']) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($references): ?>
        <?php
        // Las afinidades se publican aca, una vez, y en ningun otro lado: es
        // donde alguien que busca a uno de estos pintores encuentra, en palabras
        // del artista, que toma de el. En la ficha de cada obra viaja solo el
        // nombre, y solo el de las que esa obra sostiene.
        ?>
        <section class="section" id="influences">
            <div class="section-head section-head--simple">
                <h2><?= e(site_t('Affinities', 'Afinidades')) ?></h2>
            </div>
            <div class="artist-genealogy">
                <?php foreach ($references as $reference): ?>
                    <article>
                        <span><?= e($reference['name']) ?></span>
                        <?php if ($reference['rationale'] !== ''): ?>
                            <p><?= e($reference['rationale']) ?></p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section artist-link-panel">
        <a class="button" href="<?= e(url_for('artworks/')) ?>"><?= e(site_t('View Artworks', 'Ver obras')) ?></a>
        <a class="button button--quiet" href="<?= e(url_for('contact')) ?>"><?= e(site_t('Inquire / Contact', 'Consultar / Contactar')) ?></a>
    </section>
    <?php
}

function render_published_journal(array $notes): void
{
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_t('Studio Notes', 'Notas de estudio')) ?></p>
        <h1><?= e(site_t('Concepts in Architectural Abstract Painting', 'Ideas sobre pintura abstracta, territorio y espacio')) ?></h1>
        <p><?= e(site_t(
            'Reflections, essays, and notes on abstract painting, space, and territory.',
            'Reflexiones, ensayos y apuntes sobre pintura abstracta, espacio y territorio.'
        )) ?></p>
    </section>
    <section class="section article-list">
        <?php foreach ($notes as $slug => $post): ?>
            <?php 
            $thumbUrl = '';
            $thumbSrcset = '';
            $thumbFile = (string)($post['media_files'][0] ?? $post['mockup_files'][0] ?? '');
            if ($thumbFile !== '') {
                $thumbUrl = app_studio_note_media_url($post, $thumbFile, 480);
                $thumbSrcset = app_studio_note_media_srcset($post, $thumbFile);
            } elseif (!empty($post['has_embedded_image'])) {
                $thumbUrl = app_studio_note_embedded_image_url($post, 480);
                $thumbSrcset = app_studio_note_embedded_image_srcset($post);
            }
            $thumbMetadata = (array)($post['image_metadata'][$thumbFile] ?? []);
            
            $snippet = trim((string)($post['excerpt'] ?? '')) ?: trim(strip_tags((string)$post['objective']));
            if (mb_strlen($snippet) > 200) {
                $snippet = mb_substr($snippet, 0, 197) . '...';
            }
            if ($snippet === '') {
                $snippet = site_t('Reflections on the studio process.', 'Reflexiones sobre el proceso de estudio.');
            }
            ?>
            <article>
                <?php if ($thumbUrl !== ''): ?>
                    <a class="article-thumb" href="<?= e(url_for('studio-notes/' . $slug)) ?>">
                        <img src="<?= e($thumbUrl) ?>" <?= $thumbSrcset !== '' ? 'srcset="' . e($thumbSrcset) . '" sizes="(max-width: 560px) calc(100vw - 72px), (max-width: 1180px) 50vw, 25vw"' : '' ?>
                            alt="<?= e((string)($thumbMetadata['alt_text'] ?? '') ?: ((string)($post['alt_text'] ?? '') ?: $post['title'])) ?>" loading="lazy" decoding="async">
                    </a>
                <?php endif; ?>
                <p class="eyebrow"><?= e(site_t('Essay', 'Ensayo')) ?></p>
                <h2><a href="<?= e(url_for('studio-notes/' . $slug)) ?>"><?= e($post['title']) ?></a></h2>
                <p><?= e($snippet) ?></p>
            </article>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_published_journal_post(array $notes, string $slug): bool
{
    if (!isset($notes[$slug])) {
        return false;
    }
    $post = $notes[$slug];
    $languageSlugs = (array)($post['language_slugs'] ?? []);
    $languageSwitchUrls = [];
    foreach (['en', 'es'] as $language) {
        $languageSlug = trim((string)($languageSlugs[$language] ?? ''));
        $languageSwitchUrls[$language] = $languageSlug !== ''
            ? $GLOBALS['site']['url'] . '/studio-notes/' . $languageSlug . '/'
            : $GLOBALS['site']['url'] . '/studio-notes/';
    }
    artist_site_set_language_urls($languageSwitchUrls);
    
    $noteMediaFiles = array_values(array_filter(array_map('basename', (array)($post['media_files'] ?? []))));
    $bodyMediaFiles = array_values(array_filter(array_map('basename', (array)($post['body_media_files'] ?? []))));
    $externalMediaFiles = array_values(array_diff($noteMediaFiles, $bodyMediaFiles));
    $imageMetadata = (array)($post['image_metadata'] ?? []);
    $coverUrl = '';
    $coverSrcset = '';
    $coverFile = (string)($externalMediaFiles[0] ?? '');
    if ($coverFile !== '') {
        $coverUrl = app_studio_note_media_url($post, $coverFile, 768);
        $coverSrcset = app_studio_note_media_srcset($post, $coverFile);
    } elseif (!empty($post['has_embedded_image'])) {
        $coverUrl = app_studio_note_embedded_image_url($post, 768);
        $coverSrcset = app_studio_note_embedded_image_srcset($post);
    }
    $renderNoteBody = static fn(): string => safe_studio_note_rich_text(
        (string)$post['objective'],
        $noteMediaFiles,
        static fn(string $file): string => app_studio_note_media_url($post, $file, 1200),
        $coverFile !== '' ? [$coverFile] : [],
        $imageMetadata
    );
    $coverMetadata = (array)($imageMetadata[$coverFile] ?? []);
    ?>
    <section class="page-hero artist-page-hero journal-post-hero__intro">
        <p class="eyebrow"><?= e(site_t('Studio Notes', 'Notas de estudio')) ?></p>
        <h1><?= e($post['title']) ?></h1>
        <p><?= e((string)($post['excerpt'] ?? '') ?: site_t('Studio Essay', 'Ensayo de estudio')) ?></p>
    </section>
    
    <?php if ($coverUrl !== ''): ?>
        <section class="section artist-profile-block journal-post-feature">
            <figure class="artist-profile-block__portrait journal-post-feature__portrait">
                <img src="<?= e($coverUrl) ?>" srcset="<?= e($coverSrcset) ?>"
                    sizes="(max-width: 940px) calc(100vw - 36px), 148px"
                    alt="<?= e((string)($coverMetadata['alt_text'] ?? '') ?: ((string)($post['alt_text'] ?? '') ?: $post['title'])) ?>" fetchpriority="high" decoding="async">
                <?php if (trim((string)($coverMetadata['caption'] ?? $post['caption'] ?? '')) !== ''): ?>
                    <figcaption><?= e((string)($coverMetadata['caption'] ?? $post['caption'])) ?></figcaption>
                <?php endif; ?>
            </figure>
            <div class="prose">
                <?= $renderNoteBody() ?>
            </div>
        </section>
    <?php else: ?>
        <section class="section prose prose--wide" style="margin-top: 40px; margin-bottom: 80px;">
            <?= $renderNoteBody() ?>
        </section>
    <?php endif; ?>
    
    <?php if (count($externalMediaFiles) > 1): ?>
        <section class="section">
            <div class="section-head section-head--simple">
                <h2><?= e(site_t('Context & Studies', 'Contexto y estudios')) ?></h2>
            </div>
            <div class="artist-studio-grid">
                <?php foreach (array_slice($externalMediaFiles, 1) as $mockupFile): ?>
                    <?php $mediaMetadata = (array)($imageMetadata[$mockupFile] ?? []); ?>
                    <figure>
                        <img src="<?= e(app_studio_note_media_url($post, (string)$mockupFile, 768)) ?>"
                            srcset="<?= e(app_studio_note_media_srcset($post, (string)$mockupFile)) ?>"
                            sizes="(max-width: 940px) calc(100vw - 36px), 33vw"
                            alt="<?= e((string)($mediaMetadata['alt_text'] ?? '') ?: site_t('Context study', 'Estudio de contexto')) ?>" loading="lazy" decoding="async">
                        <?php if (trim((string)($mediaMetadata['caption'] ?? '')) !== ''): ?>
                            <figcaption><?= e((string)$mediaMetadata['caption']) ?></figcaption>
                        <?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section artist-link-panel">
        <a class="button button--quiet" href="<?= e(url_for('studio-notes')) ?>"><?= e(site_t('Back to Studio Notes', 'Volver a Notas de estudio')) ?></a>
    </section>
    <script type="application/ld+json"><?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => (string)$post['title'],
        'description' => (string)($post['seo_description'] ?? $post['excerpt'] ?? ''),
        'inLanguage' => artist_site_language(),
        'datePublished' => (string)($post['published_at'] ?? $post['created_at'] ?? ''),
        'dateModified' => (string)($post['updated_at'] ?? ''),
        'author' => ['@type' => 'Person', 'name' => 'Maurizio Valch'],
        'mainEntityOfPage' => artist_site_url_with_language(
            $GLOBALS['site']['url'] . '/studio-notes/' . $slug . '/',
            artist_site_language()
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php
    return true;
}

function render_artist_page(array $site): void
{
    $profile = artist_profile_defaults($site['artist_profile'] ?? []);
    $portrait = $profile['portrait'] ?? [];
    $portraitImage = trim((string) ($portrait['image'] ?? ''));
    $portraitPath = parse_url($portraitImage, PHP_URL_PATH) ?: $portraitImage;
    $portraitCanRender = $portraitImage !== '' && (preg_match('~^https?://~i', $portraitImage) || is_file(__DIR__ . '/' . ltrim($portraitPath, '/')));
    $links = $profile['links'] ?? [];
    ?>
    <section class="page-hero artist-page-hero">
        <p class="eyebrow"><?= e($profile['tagline']) ?></p>
        <h1><?= e($profile['name']) ?></h1>
        <p><?= nl2br(e($profile['intro'])) ?></p>
    </section>
    <section class="section artist-profile-block<?= $portraitCanRender ? '' : ' artist-profile-block--text-only' ?>">
        <?php if ($portraitCanRender): ?>
            <figure class="artist-profile-block__portrait">
                <img src="<?= e(asset_url($portraitImage)) ?>" alt="<?= e($portrait['alt'] ?: $profile['name'] . ' portrait') ?>">
                <?php if (!empty($portrait['caption'])): ?>
                    <figcaption><?= e($portrait['caption']) ?></figcaption>
                <?php endif; ?>
            </figure>
            <?php endif; ?>
        <div class="prose">
            <p><?= nl2br(e($profile['biography'])) ?></p>
        </div>
    </section>
    <section class="section artist-statement-excerpt">
        <div>
            <p class="eyebrow">Artist statement</p>
            <h2>Statement Excerpt</h2>
        </div>
        <div class="prose">
            <p><?= nl2br(e($profile['statement_excerpt'])) ?></p>
            <?php if (!empty($profile['statement_button_text']) && !empty($profile['statement_button_url'])): ?>
                <a class="button button--quiet" href="<?= e(url_for($profile['statement_button_url'])) ?>"><?= e($profile['statement_button_text']) ?></a>
            <?php endif; ?>
        </div>
    </section>
    <section class="section">
        <div class="section-head section-head--simple">
            <h2>Genealogy of the Work</h2>
        </div>
        <div class="artist-genealogy">
            <?php foreach ($profile['genealogy'] as $item): ?>
                <?php $genealogyUrl = trim((string) ($item['url'] ?? '')); ?>
                <?php 
                    $titleWithYear = e($item['title'] ?? '');
                    if (!empty($item['year'])) {
                        $titleWithYear .= ' (' . e($item['year']) . ')';
                    }
                ?>
                <?php if ($genealogyUrl !== ''): ?>
                    <a href="<?= e(url_for($genealogyUrl)) ?>">
                        <span><?= $titleWithYear ?></span>
                        <p><?= e($item['description'] ?? '') ?></p>
                    </a>
                <?php else: ?>
                    <article>
                        <span><?= $titleWithYear ?></span>
                        <p><?= e($item['description'] ?? '') ?></p>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>
    <?php if (!empty($profile['studio_images'])): ?>
        <section class="section">
            <div class="section-head section-head--simple">
                <h2>Studio Images</h2>
            </div>
            <div class="artist-studio-grid">
                <?php foreach ($profile['studio_images'] as $image): ?>
                    <?php if (empty($image['image'])) continue; ?>
                    <figure>
                        <img src="<?= e(asset_url($image['image'])) ?>" alt="<?= e($image['alt'] ?? 'Maurizio Valch studio image') ?>">
                        <?php if (!empty($image['caption'])): ?><figcaption><?= e($image['caption']) ?></figcaption><?php endif; ?>
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <section class="section artist-link-panel">
        <?php foreach ($links as $link): ?>
            <?php if (empty($link['text']) || empty($link['url'])) continue; ?>
            <a class="button <?= str_contains($link['url'], 'artist-statement') ? '' : 'button--quiet' ?>" href="<?= e(url_for($link['url'])) ?>"><?= e($link['text']) ?></a>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_statement(array $site): void
{
    $body = SiteCopy::catalog(artist_site_language())['statement']['body'] ?? [];
    ?>
    <section class="page-hero artist-page-hero artist-statement-hero">
        <p class="eyebrow"><?= e(site_copy('statement.eyebrow')) ?></p>
        <h1><?= e(site_copy('statement.title')) ?></h1>
        <p><?= e(site_copy('statement.intro')) ?></p>
    </section>
    <section class="section artist-statement-body">
        <div>
            <p class="eyebrow"><?= e(site_copy('statement.eyebrow')) ?></p>
            <h2><?= e(site_copy('statement.title')) ?></h2>
        </div>
        <div class="prose">
            <?php foreach ((array)$body as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function render_process(): void
{
    $steps = SiteCopy::catalog(artist_site_language())['process']['steps'] ?? [];
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_copy('process.eyebrow')) ?></p>
        <h1><?= e(site_copy('process.title')) ?></h1>
        <p><?= e(site_copy('process.intro')) ?></p>
    </section>
    <section class="section process-grid">
        <?php foreach ((array)$steps as $index => $step): ?>
            <div><span><?= e(str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)) ?></span><h2><?= e($step['title'] ?? '') ?></h2><p><?= e($step['text'] ?? '') ?></p></div>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_exhibitions(array $site): void
{
    $profile = artist_profile_defaults($site['artist_profile'] ?? []);
    $items = $profile['exhibitions'] ?? [];
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_copy('exhibitions.eyebrow')) ?></p>
        <h1><?= e(site_copy('exhibitions.title')) ?></h1>
        <p><?= e(site_copy('exhibitions.intro')) ?></p>
    </section>
    <section class="section timeline">
        <?php foreach ($items as $item): ?>
            <?php
            $title = trim((string) ($item['title'] ?? ''));
            $description = trim((string) ($item['description'] ?? ''));
            $url = trim((string) ($item['url'] ?? ''));
            if ($title === '' && $description === '') {
                continue;
            }
            $tag = $url !== '' ? 'a' : 'div';
            ?>
            <<?= $tag ?><?= $url !== '' ? ' href="' . e(url_for($url)) . '"' : '' ?>>
                <strong><?= e($title) ?></strong>
                <span><?= e($description) ?></span>
            </<?= $tag ?>>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_journal(array $journal, array $artworks, string $basePath = 'studio-notes', string $sectionLabel = 'Studio Notes'): void
{
    $artworkImages = array_values(array_filter(array_map(fn ($artwork) => $artwork['image'] ?? '', artworks_ordered($artworks))));
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e($sectionLabel) ?></p>
        <h1>Concepts in Architectural Abstract Painting</h1>
        <p>Editorial pages built for informational search intent and deeper reading.</p>
    </section>
    <section class="section article-list">
        <?php $index = 0; foreach ($journal as $slug => $post): ?>
            <?php $thumb = !empty($post['image']) ? $post['image'] : ($artworkImages[$index % max(1, count($artworkImages))] ?? ''); ?>
            <article>
                <?php if ($thumb): ?>
                    <a class="article-thumb" href="<?= e(url_for($basePath . '/' . $slug)) ?>">
                        <img src="<?= e(asset_url($thumb)) ?>" alt="<?= e($post['title'] . ' ' . $sectionLabel . ' thumbnail') ?>">
                    </a>
                <?php endif; ?>
                <p class="eyebrow">Essay</p>
                <h2><a href="<?= e(url_for($basePath . '/' . $slug)) ?>"><?= e($post['title']) ?></a></h2>
                <p><?= e($post['description']) ?></p>
            </article>
        <?php $index++; ?>
        <?php endforeach; ?>
    </section>
    <?php
}

function render_journal_post(array $journal, string $slug, string $sectionLabel = 'Studio Notes'): bool
{
    if (!isset($journal[$slug])) {
        return false;
    }
    $post = $journal[$slug];
    $heroImage = trim((string) ($post['image'] ?? ''));
    ?>
    <section class="page-hero artist-page-hero journal-post-hero__intro">
        <p class="eyebrow"><?= e($sectionLabel) ?></p>
        <h1><?= e($post['title']) ?></h1>
        <p><?= e($post['description']) ?></p>
    </section>
    <?php 
    $blocks = array_values(array_filter($post['blocks'] ?? [], fn ($block) => !empty($block['title']) || !empty($block['text']) || !empty($block['image']) || !empty($block['caption'])));
    $firstBlockRendered = false;
    ?>
    <?php if ($heroImage !== ''): ?>
        <section class="section artist-profile-block journal-post-feature">
            <figure class="artist-profile-block__portrait journal-post-feature__portrait">
                <img src="<?= e(asset_url($heroImage)) ?>" alt="<?= e($post['title'] . ' ' . $sectionLabel . ' image') ?>" loading="eager">
            </figure>
            <div class="prose">
                <?php if ($blocks && !empty($blocks[0]['text'])): ?>
                    <?php $firstParagraphs = preg_split("/\R{2,}/", trim($blocks[0]['text'] ?? '')) ?: []; ?>
                    <?php $firstBlockRendered = true; ?>
                    <?php foreach (array_slice($firstParagraphs, 0, 2) as $paragraph): ?>
                        <?php if (trim($paragraph) !== ''): ?><p><?= e(trim($paragraph)) ?></p><?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?= e($post['description']) ?></p>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
    <article class="section prose prose--wide">
        <?php if ($blocks): ?>
            <?php foreach ($blocks as $blockIndex => $block): ?>
                <?php if ($firstBlockRendered && $blockIndex === 0): ?>
                    <?php // Renderizar los párrafos restantes del primer bloque ?>
                    <?php $allParagraphs = preg_split("/\R{2,}/", trim($block['text'] ?? '')) ?: []; ?>
                    <?php foreach (array_slice($allParagraphs, 2) as $paragraph): ?>
                        <?php if (trim($paragraph) !== ''): ?><p><?= e(trim($paragraph)) ?></p><?php endif; ?>
                    <?php endforeach; ?>
                <?php elseif (!$firstBlockRendered || $blockIndex > 0): ?>
                    <?php
                    $level = $block['level'] ?? 'h2';
                    if (!in_array($level, ['h2', 'h3', 'h4'], true)) {
                        $level = 'h2';
                    }
                    ?>
                    <?php if (!empty($block['title'])): ?>
                        <<?= $level ?>><?= e($block['title']) ?></<?= $level ?>>
                    <?php endif; ?>
                    <?php if (!empty($block['image'])): ?>
                        <figure class="journal-block-image">
                            <img src="<?= e(asset_url($block['image'])) ?>" alt="<?= e($block['caption'] ?: $block['title'] ?: $post['title']) ?>" loading="lazy">
                            <?php if (!empty($block['caption'])): ?><figcaption><?= e($block['caption']) ?></figcaption><?php endif; ?>
                        </figure>
                    <?php endif; ?>
                    <?php foreach ((preg_split("/\R{2,}/", trim($block['text'] ?? '')) ?: []) as $paragraph): ?>
                        <?php if (trim($paragraph) !== ''): ?><p><?= e(trim($paragraph)) ?></p><?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach (($post['body'] ?? []) as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
    </article>
    <?php
    return true;
}

function render_contact(array $site, array $artworks): void
{
    $requested = $_GET['artwork'] ?? '';
    $subject = isset($artworks[$requested])
        ? site_copy('contact.artwork_subject', ['title' => $artworks[$requested]['title']])
        : site_copy('contact.default_subject');

    $success = false;
    $error = '';
    $submittedName = '';
    $submittedEmail = '';
    $submittedSubject = $subject;
    $submittedMessage = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'contact_submit') {
        $submittedName = trim($_POST['name'] ?? '');
        $submittedEmail = trim($_POST['email'] ?? '');
        $submittedSubject = trim($_POST['subject'] ?? '') ?: $subject;
        $submittedMessage = trim($_POST['message'] ?? '');
        $honeypot = $_POST['website'] ?? '';

        if ($honeypot !== '') {
            $success = true;
        } else if (empty($submittedName) || empty($submittedEmail) || empty($submittedMessage)) {
            $error = site_copy('contact.required_error');
        } else if (!filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $error = site_copy('contact.email_error');
        } else {
            try {
                $contactPdo = artist_site_database_connection(dirname(__DIR__) . '/platform');
                if (!artist_site_rate_limit($contactPdo, 'artist_contact', $submittedEmail, 5, 3600)) {
                    $error = site_copy('contact.rate_error');
                }
            } catch (Throwable $rateLimitError) {
                error_log('Artist contact rate limiter unavailable: ' . $rateLimitError->getMessage());
                $error = site_copy('contact.temporary_error');
            }
        }

        if ($error === '' && $honeypot === '') {
            $profile = app_artist_profile()?->get();
            $artistName = $profile['artist_name'] ?? 'Artist';
            $to = filter_var((string)($site['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$site['email'] : resolved_artist_email();
            $submittedSubject = str_replace(["\r", "\n"], ' ', mb_substr($submittedSubject, 0, 120));
            try {
                (new ArtistContactMailer())->send([
                    'name' => $submittedName,
                    'email' => $submittedEmail,
                    'subject' => $submittedSubject,
                    'message' => $submittedMessage,
                ], $to, $artistName);
                $success = true;
                $submittedName = '';
                $submittedEmail = '';
                $submittedSubject = $subject;
                $submittedMessage = '';
            } catch (Throwable $mailError) {
                error_log('Artist contact delivery failed: ' . $mailError->getMessage());
                $error = site_copy('contact.delivery_error') . ' ' . $to;
            }
        }
    }
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_copy('contact.eyebrow')) ?></p>
        <h1><?= e(site_copy('contact.title')) ?></h1>
        <p><?= e(site_copy('contact.intro')) ?></p>
    </section>
    <section class="section contact-panel">
        <div style="min-height: auto; padding: 24px;">
            <h2 style="font-size: clamp(20px, 1.8vw, 26px); margin-bottom: 20px; font-family: var(--serif-display); font-weight: normal; color: var(--ink); margin-top: 0;"><?= e(site_copy('contact.send_title')) ?></h2>
            
            <?php if ($success): ?>
                <div style="background: #f1fcf4; border: 1px solid #a3e2b4; color: #1b5e20; padding: 10px; margin-bottom: 15px; font-size: 14px; min-height: auto;">
                    <?= e(site_copy('contact.sent')) ?>
                </div>
            <?php elseif (!empty($error)): ?>
                <div style="background: #fdf2f2; border: 1px solid #f8b4b4; color: #c81e1e; padding: 10px; margin-bottom: 15px; font-size: 14px; min-height: auto;">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST" style="display: flex; flex-direction: column; gap: 12px;">
                <input type="hidden" name="action" value="contact_submit">
                
                <!-- Honeypot anti-spam (hidden) -->
                <div style="display: none; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="website"><?= e(site_t('Leave blank', 'Dejar en blanco')) ?></label>
                    <input type="text" name="website" id="website" autocomplete="off">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-name" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;"><?= e(site_copy('contact.name')) ?></label>
                    <input type="text" name="name" id="contact-name" value="<?= e($submittedName) ?>" required placeholder="<?= e(site_copy('contact.name_placeholder')) ?>" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-email" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;"><?= e(site_copy('contact.email')) ?></label>
                    <input type="email" name="email" id="contact-email" value="<?= e($submittedEmail) ?>" required placeholder="your@email.com" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-subject" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;"><?= e(site_copy('contact.subject')) ?></label>
                    <input type="text" name="subject" id="contact-subject" value="<?= e($submittedSubject) ?>" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-message" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;"><?= e(site_copy('contact.message')) ?></label>
                    <textarea name="message" id="contact-message" required placeholder="<?= e(site_copy('contact.message_placeholder')) ?>" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%; resize: vertical; min-height: 80px;"><?= e($submittedMessage) ?></textarea>
                </div>

                <button type="submit" class="button" onmouseover="this.style.background='var(--red)'; this.style.borderColor='var(--red)';" onmouseout="this.style.background='var(--ink)'; this.style.borderColor='var(--ink)';" style="cursor: pointer; justify-content: center; min-height: 36px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; width: 100%; border-radius: 0; transition: background 0.18s, border-color 0.18s;"><?= e(site_copy('contact.send')) ?></button>
            </form>
        </div>
        <div>
            <h2><?= e(site_copy('contact.collector_title')) ?></h2>
            <p><?= e(site_copy('contact.collector_text')) ?></p>
        </div>
        <div>
            <h2><?= e(site_copy('contact.profiles')) ?></h2>
            <div class="social-links" aria-label="<?= e(site_t('Social and marketplace profiles', 'Perfiles sociales y de mercado')) ?>">
                <?php foreach ($site['social'] as $label => $url): ?>
                    <a href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer"><?= e($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php
}

function render_admin_access_gate(): bool
{
    if (!admin_is_configured()): ?>
        <section class="page-hero">
            <p class="eyebrow">Admin setup</p>
            <h1>Create Admin Password</h1>
            <p>This password protects catalog, Studio Notes, series, and map editing.</p>
        </section>
        <section class="section admin-panel">
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="setup_password">
                <label>Password <input type="password" name="password" required minlength="8"></label>
                <button class="button" type="submit">Create Admin</button>
            </form>
        </section>
        <?php return false;
    endif;

    if (!admin_is_logged_in()): ?>
        <section class="page-hero">
            <p class="eyebrow">Admin</p>
            <h1>Studio Administration</h1>
            <p>Log in to manage Studio Notes, catalog, series, availability, images, mockups, and map placement zones.</p>
        </section>
        <section class="section admin-panel">
            <form method="post" class="admin-form">
                <input type="hidden" name="action" value="login">
                <label>Password <input type="password" name="password" required></label>
                <button class="button" type="submit">Log In</button>
            </form>
        </section>
        <?php return false;
    endif;
    return true;
}

function render_admin_page(array $site, array $series, array $artworks, array $soldRecords, array $soldLocations, array $journal): void
{
    if (!render_admin_access_gate()) {
        return;
    }
    $editSeriesSlug = $_GET['series'] ?? '';
    $editJournalSlug = $_GET['studioNotes'] ?? $_GET['journal'] ?? '';
    $editArtworkSlug = $_GET['artwork'] ?? '';
    $adminSection = $_GET['section'] ?? ($editJournalSlug ? 'journal' : ($editSeriesSlug ? 'series' : 'catalog'));
    if (!in_array($adminSection, ['catalog', 'journal', 'series', 'artist', 'statement', 'exhibitions'], true)) {
        $adminSection = 'catalog';
    }
    $artistProfile = artist_profile_defaults($site['artist_profile'] ?? []);
    $orderedArtworks = artworks_ordered($artworks);
    $editSeries = $series[$editSeriesSlug] ?? ['title' => '', 'seo_title' => '', 'description' => '', 'image' => '', 'keywords' => []];
    $editJournal = $journal[$editJournalSlug] ?? ['title' => '', 'seo_title' => '', 'description' => '', 'image' => '', 'body' => [], 'blocks' => []];
    $editJournalBlocks = $editJournal['blocks'] ?? [];
    if (!$editJournalBlocks && !empty($editJournal['body'])) {
        foreach (array_slice($editJournal['body'], 0, 4) as $index => $paragraph) {
            $editJournalBlocks[] = [
                'level' => $index === 0 ? 'h2' : 'h3',
                'title' => '',
                'text' => $paragraph,
                'image' => '',
                'caption' => '',
            ];
        }
    }
    $editArtwork = $artworks[$editArtworkSlug] ?? [
        'title' => '',
        'year' => date('Y'),
        'series' => array_key_first($series),
        'status' => 'available',
        'medium' => 'Acrylic on canvas',
        'dimensions_cm' => '',
        'dimensions_in' => '',
        'orientation' => '',
        'image' => '',
        'detail_image' => '',
        'mockups' => [],
        'price' => 'Inquire',
        'sale_platform' => '',
        'sale_result' => '',
        'summary' => '',
        'concept' => '',
        'commercial_note' => '',
    ];
    $dimensionParts = admin_dimension_parts($editArtwork['dimensions_cm'] ?? '');
    $locations = admin_map_locations_by_artwork($soldLocations);
    $editLocation = $locations[$editArtworkSlug] ?? ['postal_code' => '', 'country' => '', 'lat' => '', 'lng' => ''];
    $mockupText = implode("\n", array_map(fn ($mockup) => $mockup['image'] ?? '', $editArtwork['mockups'] ?? []));
    $editDetailImages = $editArtwork['detail_images'] ?? [];
    if (!$editDetailImages && !empty($editArtwork['detail_image'])) {
        $editDetailImages = [['image' => $editArtwork['detail_image'], 'alt' => ($editArtwork['title'] ?? 'Artwork') . ' detail']];
    }
    $detailText = implode("\n", array_map(fn ($detail) => $detail['image'] ?? '', $editDetailImages));
    ?>
    <section class="page-hero" hidden>
        <p class="eyebrow">Admin</p>
        <h1>Studio Administration</h1>
        <p>Manage Studio Notes entries, artwork catalog, series, images, availability, and postal map placement for sold works.</p>
        <?php if (!empty($_GET['saved'])): ?><p class="admin-saved">Actualización correcta</p><?php endif; ?>
    </section>

    <section class="section admin-shell">
        <div class="admin-titlebar" hidden>
            <div>
                <p class="eyebrow">Admin</p>
                <h1>Studio Administration</h1>
            </div>
            <?php if (!empty($_GET['saved'])): ?><p class="admin-saved">Actualizacion correcta</p><?php endif; ?>
        </div>
        <div class="admin-toolbar">
            <a href="<?= e(url_for('admin')) ?>?section=catalog" aria-current="<?= $adminSection === 'catalog' ? 'page' : 'false' ?>">Catalog</a>
            <a href="<?= e(url_for('admin')) ?>?section=journal" aria-current="<?= $adminSection === 'journal' ? 'page' : 'false' ?>">Studio Notes</a>
            <a href="<?= e(url_for('admin')) ?>?section=series" aria-current="<?= $adminSection === 'series' ? 'page' : 'false' ?>">Series</a>
            <a href="<?= e(url_for('admin')) ?>?section=artist" aria-current="<?= $adminSection === 'artist' ? 'page' : 'false' ?>">Artist Page</a>
            <a href="<?= e(url_for('admin')) ?>?section=statement" aria-current="<?= $adminSection === 'statement' ? 'page' : 'false' ?>">Artist Statement</a>
            <a href="<?= e(url_for('admin')) ?>?section=exhibitions" aria-current="<?= $adminSection === 'exhibitions' ? 'page' : 'false' ?>">Exhibitions</a>
            <form method="post">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Log out</button>
            </form>
        </div>
        <?php if (!empty($_GET['saved'])): ?><p class="admin-saved">Actualizacion correcta</p><?php endif; ?>

        <div class="admin-grid">
            <?php if ($adminSection === 'home'): ?>
            <section class="admin-panel" id="home">
                <div class="admin-panel__head">
                    <h2>Home Hero</h2>
                    <a href="<?= e(url_for('/')) ?>" target="_blank" rel="noopener">View home</a>
                </div>
                <form method="post" enctype="multipart/form-data" class="admin-form admin-form--compact">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_home">
                    <div class="admin-span admin-root-image">
                        <div class="admin-root-image__preview" data-root-image-preview>
                            <img src="<?= e(asset_url($site['hero_image'] ?? '/assets/images/the-path-before-architecture.jpg')) ?>" alt="Home hero image preview">
                        </div>
                        <label class="admin-root-image__upload">
                            <span>Hero image</span>
                            <select name="hero_image">
                                <option value="/assets/images/the-path-before-architecture.jpg">Default hero image</option>
                                <?php foreach ($artworks as $artwork): ?>
                                    <?php if (!empty($artwork['image'])): ?>
                                        <option value="<?= e($artwork['image']) ?>" <?= ($site['hero_image'] ?? '') === $artwork['image'] ? 'selected' : '' ?>><?= e($artwork['title'] ?? $artwork['image']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <input type="file" name="hero_image_upload" accept="image/jpeg,image/png,image/webp" data-root-image-input>
                        </label>
                    </div>
                    <button class="button admin-span" type="submit">Actualizar</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'artist'): ?>
            <section class="admin-panel admin-panel--wide" id="artist">
                <div class="admin-panel__head">
                    <h2>Artist Page</h2>
                    <a href="<?= e(url_for('artist')) ?>" target="_blank" rel="noopener">View artist page</a>
                </div>
                <form method="post" enctype="multipart/form-data" class="admin-form admin-form--grid">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_artist_profile">
                    <fieldset class="admin-span admin-fieldset">
                        <legend>Hero / heading</legend>
                        <label>Artist name <input name="artist_name" value="<?= e($artistProfile['name']) ?>"></label>
                        <label>Subtitle / tagline <input name="artist_tagline" value="<?= e($artistProfile['tagline']) ?>"></label>
                        <label class="admin-span">Short intro text <textarea name="artist_intro" rows="4"><?= e($artistProfile['intro']) ?></textarea></label>
                    </fieldset>

                    <fieldset class="admin-span admin-fieldset admin-root-image">
                        <legend>Main portrait</legend>
                        <div class="admin-root-image__preview">
                            <?php if (!empty($artistProfile['portrait']['image'])): ?>
                                <img src="<?= e(asset_url($artistProfile['portrait']['image'])) ?>" alt="<?= e($artistProfile['portrait']['alt'] ?? 'Artist portrait') ?>">
                            <?php else: ?>
                                <span>No portrait</span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-root-image__upload">
                            <input type="hidden" name="portrait_image" value="<?= e($artistProfile['portrait']['image'] ?? '') ?>">
                            <label>Upload personal portrait photo <input type="file" name="portrait_upload" accept="image/jpeg,image/png,image/webp"></label>
                            <label>Alt text <input name="portrait_alt" value="<?= e($artistProfile['portrait']['alt'] ?? '') ?>"></label>
                            <label>Caption optional <input name="portrait_caption" value="<?= e($artistProfile['portrait']['caption'] ?? '') ?>"></label>
                        </div>
                    </fieldset>

                    <label class="admin-span">Short biography
                        <textarea name="artist_biography" rows="7"><?= e($artistProfile['biography']) ?></textarea>
                    </label>

                    <fieldset class="admin-span admin-fieldset">
                        <legend>Artist statement excerpt</legend>
                        <label class="admin-span">Editable text block <textarea name="statement_excerpt" rows="6"><?= e($artistProfile['statement_excerpt']) ?></textarea></label>
                        <label>Button text <input name="statement_button_text" value="<?= e($artistProfile['statement_button_text']) ?>"></label>
                        <label>Button URL <input name="statement_button_url" value="<?= e($artistProfile['statement_button_url']) ?>"></label>
                    </fieldset>

                    <fieldset class="admin-span admin-fieldset">
                        <legend>Work genealogy</legend>
                        <?php $genealogyRows = max(4, count($artistProfile['genealogy'])); ?>
                        <?php for ($index = 0; $index < $genealogyRows; $index++): ?>
                            <?php $item = $artistProfile['genealogy'][$index] ?? ['title' => '', 'description' => '', 'url' => '', 'year' => '']; ?>
                            <div class="admin-repeat-row">
                                <label>Title <input name="genealogy_title[]" value="<?= e($item['title'] ?? '') ?>"></label>
                                <label>Year <input name="genealogy_year[]" value="<?= e($item['year'] ?? '') ?>" placeholder="Ej: 2026"></label>
                                <label>Description <input name="genealogy_description[]" value="<?= e($item['description'] ?? '') ?>"></label>
                                <label>URL (optional) <input name="genealogy_url[]" value="<?= e($item['url'] ?? '') ?>"></label>
                            </div>
                        <?php endfor; ?>
                    </fieldset>

                    <fieldset class="admin-span admin-fieldset admin-studio-images" data-draggable-list="studio-images" data-drag-axis="vertical">
                        <legend>Studio images</legend>
                        <?php $studioRows = max(4, count($artistProfile['studio_images']) + 2); ?>
                        <?php for ($index = 0; $index < $studioRows; $index++): ?>
                            <?php $image = $artistProfile['studio_images'][$index] ?? ['image' => '', 'alt' => '', 'caption' => '', 'sort' => $index + 1]; ?>
                            <div class="admin-studio-row" draggable="true" data-draggable-item="true">
                                <span class="admin-drag-handle" aria-hidden="true">☰</span>
                                <div class="admin-studio-row__thumb">
                                    <?php if (!empty($image['image'])): ?><img src="<?= e(asset_url($image['image'])) ?>" alt="<?= e($image['alt'] ?? '') ?>"><?php else: ?><span>No image</span><?php endif; ?>
                                </div>
                                <input type="hidden" name="studio_existing[]" value="<?= e($image['image'] ?? '') ?>">
                                <label>Image file <input type="file" name="studio_image_uploads[]" accept="image/jpeg,image/png,image/webp"></label>
                                <label>Alt text <input name="studio_alt[]" value="<?= e($image['alt'] ?? '') ?>"></label>
                                <label>Caption <input name="studio_caption[]" value="<?= e($image['caption'] ?? '') ?>"></label>
                                <label>Sort order <input name="studio_sort[]" value="<?= e($image['sort'] ?? ($index + 1)) ?>" inputmode="numeric"></label>
                                <label class="admin-check"><input type="checkbox" name="studio_remove[<?= $index ?>]" value="1"> Remove</label>
                            </div>
                        <?php endfor; ?>
                    </fieldset>

                    <fieldset class="admin-span admin-fieldset">
                        <legend>Exhibitions / collections links</legend>
                        <?php for ($index = 0; $index < 3; $index++): ?>
                            <?php $link = $artistProfile['links'][$index] ?? ['text' => '', 'url' => '']; ?>
                            <div class="admin-repeat-row">
                                <label>Button text <input name="link_text[]" value="<?= e($link['text'] ?? '') ?>"></label>
                                <label>Button URL <input name="link_url[]" value="<?= e($link['url'] ?? '') ?>"></label>
                            </div>
                        <?php endfor; ?>
                    </fieldset>

                    <fieldset class="admin-span admin-fieldset">
                        <legend>SEO</legend>
                        <label>SEO title <input name="seo_title" value="<?= e($artistProfile['seo']['title'] ?? '') ?>"></label>
                        <label>Keywords <input name="seo_keywords" value="<?= e($artistProfile['seo']['keywords'] ?? '') ?>"></label>
                        <label class="admin-span">Meta description <textarea name="seo_description" rows="3"><?= e($artistProfile['seo']['description'] ?? '') ?></textarea></label>
                        <input type="hidden" name="seo_og_image" value="<?= e($artistProfile['seo']['og_image'] ?? '') ?>">
                        <label>Open Graph image upload <input type="file" name="og_image_upload" accept="image/jpeg,image/png,image/webp"></label>
                    </fieldset>

                    <button class="button admin-span" type="submit">Actualizar Artist Page</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'statement'): ?>
            <section class="admin-panel admin-panel--wide" id="statement">
                <div class="admin-panel__head">
                    <h2>Artist Statement Page</h2>
                    <a href="<?= e(url_for('artist-statement')) ?>" target="_blank" rel="noopener">View statement page</a>
                </div>
                <form method="post" class="admin-form admin-form--grid">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_artist_statement">
                    <fieldset class="admin-span admin-fieldset">
                        <legend>Artist statement page</legend>
                        <p class="admin-help">Edit the content of `/artist-statement` here.</p>
                        <label>Page title <input name="statement_page_title" value="<?= e($artistProfile['statement_page']['title'] ?? '') ?>"></label>
                        <label class="admin-span">Intro text <textarea name="statement_page_intro" rows="3"><?= e($artistProfile['statement_page']['intro'] ?? '') ?></textarea></label>
                        <label class="admin-span">Statement body <textarea name="statement_page_body" rows="8"><?= e(implode("\n\n", $artistProfile['statement_page']['body'] ?? [])) ?></textarea></label>
                    </fieldset>
                    <button class="button admin-span" type="submit">Actualizar Artist Statement</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'exhibitions'): ?>
            <section class="admin-panel admin-panel--wide" id="exhibitions">
                <div class="admin-panel__head">
                    <h2>Exhibitions & Collections</h2>
                    <a href="<?= e(url_for('exhibitions-collections')) ?>" target="_blank" rel="noopener">View page</a>
                </div>
                <form method="post" class="admin-form admin-form--grid">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_artist_exhibitions">
                    <fieldset class="admin-span admin-fieldset">
                        <legend>Editable cards</legend>
                        <p class="admin-help">These cards are rendered on `/exhibitions-collections` and can optionally link to more information.</p>
                        <?php $exhibitionRows = max(4, count($artistProfile['exhibitions'] ?? [])); ?>
                        <?php for ($index = 0; $index < $exhibitionRows; $index++): ?>
                            <?php $item = $artistProfile['exhibitions'][$index] ?? ['title' => '', 'description' => '', 'url' => '']; ?>
                            <div class="admin-repeat-row">
                                <label>Title <input name="exhibition_title[]" value="<?= e($item['title'] ?? '') ?>"></label>
                                <label>Description <input name="exhibition_description[]" value="<?= e($item['description'] ?? '') ?>"></label>
                                <label>URL (optional) <input name="exhibition_url[]" value="<?= e($item['url'] ?? '') ?>"></label>
                            </div>
                        <?php endfor; ?>
                    </fieldset>
                    <button class="button admin-span" type="submit">Actualizar Exhibitions</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'journal'): ?>
            <section class="admin-panel" id="journal">
                <div class="admin-panel__head">
                    <h2>Studio Notes</h2>
                    <a href="<?= e(url_for('admin')) ?>?section=journal#journal">New entry</a>
                </div>
                <div class="admin-list admin-list--thumbs">
                    <?php foreach ($journal as $slug => $entry): ?>
                        <a class="admin-list-item" href="<?= e(url_for('admin')) ?>?section=journal&studioNotes=<?= e($slug) ?>#journal">
                            <?php if (!empty($entry['image'])): ?>
                                <img src="<?= e(asset_url($entry['image'])) ?>" alt="<?= e(($entry['title'] ?? 'Studio Notes entry') . ' thumbnail') ?>">
                            <?php endif; ?>
                            <span><?= e($entry['title']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_journal">
                    <input type="hidden" name="original_slug" value="<?= e($editJournalSlug) ?>">
                    <input type="hidden" name="image" value="<?= e($editJournal['image'] ?? '') ?>">
                    
                    <div class="admin-form-row">
                        <fieldset class="admin-fieldset">
                            <legend>1. Información General</legend>
                            <div class="admin-fieldset-grid">
                                <label class="admin-span-2">Slug (URL) <input name="slug" value="<?= e($editJournalSlug) ?>" placeholder="post-or-essay-slug"></label>
                                <label class="admin-span-2">Título <input name="title" value="<?= e($editJournal['title']) ?>" required></label>
                                <label class="admin-span-2">SEO Title <input name="seo_title" value="<?= e($editJournal['seo_title']) ?>"></label>
                                <label class="admin-span-2">Descripción (SEO) <textarea name="description" rows="3"><?= e($editJournal['description']) ?></textarea></label>
                            </div>
                        </fieldset>

                        <fieldset class="admin-fieldset">
                            <legend>2. Portada (Thumbnail)</legend>
                            <div class="admin-fieldset-grid">
                                <div class="admin-span-2 admin-media-upload-row">
                                    <div class="admin-root-image-box admin-root-image">
                                        <div class="admin-root-image__preview" data-root-image-preview>
                                            <?php if (!empty($editJournal['image'])): ?>
                                                <img src="<?= e(asset_url($editJournal['image'])) ?>" alt="Preview">
                                            <?php else: ?>
                                                <span class="preview-placeholder">Sin imagen de portada</span>
                                            <?php endif; ?>
                                        </div>
                                        <label class="admin-root-image__upload-btn">
                                            <span>Seleccionar Imagen de Portada</span>
                                            <input type="file" name="journal_image_upload" accept="image/jpeg,image/png,image/webp" data-root-image-input>
                                        </label>
                                        <?php if (!empty($editJournal['image'])): ?>
                                            <label class="admin-span-2" style="margin-top: 10px; display: flex; align-items: center; gap: 8px;">
                                                <input type="checkbox" name="remove_journal_image" value="1">
                                                <span>Eliminar imagen de portada actual</span>
                                            </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </fieldset>
                    </div>

                    <input type="hidden" name="body" value="<?= e(implode("\n\n", $editJournal['body'] ?? [])) ?>">
                    <div class="admin-span admin-block-editor">
                        <div>
                            <p class="eyebrow">Studio Notes structure</p>
                            <h3>Content blocks</h3>
                        </div>
                        <?php for ($blockIndex = 0; $blockIndex < 4; $blockIndex++): ?>
                            <?php $block = $editJournalBlocks[$blockIndex] ?? ['level' => $blockIndex === 0 ? 'h2' : 'h3', 'title' => '', 'text' => '', 'image' => '', 'caption' => '']; ?>
                            <fieldset class="admin-content-block">
                                <legend>Block <?= $blockIndex + 1 ?></legend>
                                <label>Heading level
                                    <select name="block_level[<?= $blockIndex ?>]">
                                        <?php foreach (['h2', 'h3', 'h4'] as $level): ?>
                                            <option value="<?= e($level) ?>" <?= ($block['level'] ?? '') === $level ? 'selected' : '' ?>><?= strtoupper(e($level)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Subtitle / heading <input name="block_title[<?= $blockIndex ?>]" value="<?= e($block['title'] ?? '') ?>"></label>
                                <label>Image caption <input name="block_caption[<?= $blockIndex ?>]" value="<?= e($block['caption'] ?? '') ?>"></label>
                                <label class="admin-span">Description <textarea name="block_text[<?= $blockIndex ?>]" rows="5"><?= e($block['text'] ?? '') ?></textarea></label>
                                <input type="hidden" name="block_image_existing[<?= $blockIndex ?>]" value="<?= e($block['image'] ?? '') ?>">
                                <div class="admin-span admin-root-image" style="display: flex; flex-direction: column; gap: 8px;">
                                    <div class="admin-root-image-box" style="display: flex; gap: 12px; align-items: center; background: transparent; border: none; padding: 0;">
                                        <div class="admin-root-image__preview" data-root-image-preview style="width: 80px; height: 60px;">
                                            <?php if (!empty($block['image'])): ?>
                                                <img src="<?= e(asset_url($block['image'])) ?>" alt="">
                                            <?php else: ?>
                                                <span style="font-size: 10px; color: var(--muted);">No image</span>
                                            <?php endif; ?>
                                        </div>
                                        <label class="admin-root-image__upload-btn" style="flex: 1; margin: 0;">
                                            <span>Select Block Image</span>
                                            <input type="file" name="block_image_uploads[<?= $blockIndex ?>]" accept="image/jpeg,image/png,image/webp" data-root-image-input>
                                        </label>
                                    </div>
                                </div>
                            </fieldset>
                        <?php endfor; ?>
                    </div>
                    <button class="button" type="submit">Save Studio Note Entry</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'series'): ?>
            <section class="admin-panel" id="series">
                <div class="admin-panel__head">
                    <h2>Series</h2>
                    <a href="<?= e(url_for('admin')) ?>?section=series#series">New series</a>
                </div>
                <div class="admin-list">
                    <?php foreach ($series as $slug => $item): ?>
                        <a href="<?= e(url_for('admin')) ?>?section=series&series=<?= e($slug) ?>#series"><?= e($item['title']) ?></a>
                    <?php endforeach; ?>
                </div>
                <form method="post" enctype="multipart/form-data" class="admin-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_series">
                    <input type="hidden" name="original_slug" value="<?= e($editSeriesSlug) ?>">
                    <input type="hidden" name="image" value="<?= e($editSeries['image'] ?? '') ?>">
                    <label>Slug <input name="slug" value="<?= e($editSeriesSlug) ?>" placeholder="series-slug"></label>
                    <label>Title <input name="title" value="<?= e($editSeries['title']) ?>" required></label>
                    <label>Series thumbnail <input type="file" name="series_image_upload" accept="image/jpeg,image/png,image/webp"></label>
                    <label>SEO title <input name="seo_title" value="<?= e($editSeries['seo_title']) ?>"></label>
                    <label>Description <textarea name="description" rows="4"><?= e($editSeries['description']) ?></textarea></label>
                    <label>Keywords <input name="keywords" value="<?= e(implode(', ', $editSeries['keywords'])) ?>"></label>
                    <div class="admin-actions">
                        <button class="button" type="submit">Save Series</button>
                    </div>
                </form>
                <?php if ($editSeriesSlug && isset($series[$editSeriesSlug])): ?>
                    <form method="post" class="admin-delete-form" onsubmit="return confirm('Delete this series? Works assigned to it will be moved to another series.');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="delete_series">
                        <input type="hidden" name="slug" value="<?= e($editSeriesSlug) ?>">
                        <button class="button button--danger" type="submit">Delete Series</button>
                    </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($adminSection === 'catalog'): ?>
            <section class="admin-panel admin-panel--wide" id="catalog">
                <div class="admin-panel__head">
                    <div>
                        <h2>Artwork Catalog</h2>
                        <p>Index root images and edit the catalog ficha for each artwork.</p>
                    </div>
                </div>
                <a class="button admin-add-button" href="<?= e(url_for('admin')) ?>?section=catalog#catalog-form">Agregar nueva obra</a>
                <div class="admin-artwork-index" aria-label="Indexed artworks">
                    <?php foreach ($orderedArtworks as $slug => $artwork): ?>
                        <article class="admin-artwork-row">
                            <div class="admin-artwork-row__image">
                                <?php if (!empty($artwork['image'])): ?>
                                    <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e(($artwork['title'] ?? 'Artwork') . ' thumbnail') ?>">
                                <?php else: ?>
                                    <span>No image</span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-artwork-row__title">
                                <strong><?= e($artwork['title'] ?? $slug) ?></strong>
                                <span><?= e(($artwork['status'] ?? 'available') === 'sold' ? 'Sold / Placed' : 'In Studio') ?></span>
                            </div>
                            <div class="admin-artwork-row__actions">
                                <a href="<?= e(url_for('admin')) ?>?section=catalog&artwork=<?= e($slug) ?>#catalog-form">Modificar</a>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="move_artwork">
                                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" <?= $slug === array_key_first($orderedArtworks) ? 'disabled' : '' ?>>Subir</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="move_artwork">
                                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" <?= $slug === array_key_last($orderedArtworks) ? 'disabled' : '' ?>>Bajar</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Eliminar esta obra del catálogo?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="delete_artwork">
                                    <input type="hidden" name="slug" value="<?= e($slug) ?>">
                                    <button type="submit">Eliminar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form id="catalog-form" method="post" enctype="multipart/form-data" class="admin-form admin-artwork-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="save_artwork">
                    <input type="hidden" name="original_slug" value="<?= e($editArtworkSlug) ?>">
                    <input type="hidden" name="sort_order" value="<?= e($editArtwork['sort_order'] ?? '') ?>">
                    
                    <div class="admin-form-row">
                        <fieldset class="admin-fieldset">
                            <legend>1. Información General</legend>
                            <div class="admin-fieldset-grid">
                                <label class="admin-span-2">Título <input name="title" value="<?= e($editArtwork['title'] ?? '') ?>" required></label>
                                <label class="admin-span-2">Slug (URL) <input name="slug" value="<?= e($editArtworkSlug) ?>" placeholder="crossing-lines-territory"></label>
                                <label>Año <input name="year" value="<?= e($editArtwork['year'] ?? '') ?>"></label>
                                <label>Precio <input name="price" value="<?= e($editArtwork['price'] ?? '') ?>" placeholder="Ej: 1800"></label>
                                <label>Moneda 
                                    <select name="currency">
                                        <option value="EUR" <?= ($editArtwork['currency'] ?? 'EUR') === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                        <option value="USD" <?= ($editArtwork['currency'] ?? 'EUR') === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                    </select>
                                </label>
                                <label class="admin-span-2">URL de Compra / Checkout <input name="purchase_url" value="<?= e($editArtwork['purchase_url'] ?? '') ?>" placeholder="https://... o vacío para usar enlace interno"></label>
                                <label>Técnica (Medium)
                                    <select name="medium">
                                        <?php foreach (array_unique(array_filter(['Acrylic on canvas', 'Oil on canvas', 'Mixed media on canvas', $editArtwork['medium'] ?? ''])) as $medium): ?>
                                            <option value="<?= e($medium) ?>" <?= ($editArtwork['medium'] ?? '') === $medium ? 'selected' : '' ?>><?= e($medium) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Serie
                                    <select name="series">
                                        <?php foreach ($series as $slug => $item): ?>
                                            <option value="<?= e($slug) ?>" <?= ($editArtwork['series'] ?? '') === $slug ? 'selected' : '' ?>><?= e($item['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Orientación
                                    <select name="orientation">
                                        <?php foreach (array_unique(array_filter(['Horizontal', 'Vertical', 'Square', $editArtwork['orientation'] ?? ''])) as $orientation): ?>
                                            <option value="<?= e($orientation) ?>" <?= ($editArtwork['orientation'] ?? '') === $orientation ? 'selected' : '' ?>><?= e($orientation) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>
                        </fieldset>

                        <fieldset class="admin-fieldset">
                            <legend>2. Dimensiones (cm)</legend>
                            <div class="admin-fieldset-grid admin-dimensions-grid">
                                <label>Ancho (Width) <input name="dimension_width_cm" data-dimension-width-cm value="<?= e($dimensionParts['width']) ?>" inputmode="decimal"></label>
                                <label>Alto (Height) <input name="dimension_height_cm" data-dimension-height-cm value="<?= e($dimensionParts['height']) ?>" inputmode="decimal"></label>
                                <label>Profundidad (Depth) <input name="dimension_depth_cm" data-dimension-depth-cm value="<?= e($dimensionParts['depth']) ?>" inputmode="decimal"></label>
                            </div>
                            <input type="hidden" name="dimensions_cm" data-dimensions-cm value="<?= e($editArtwork['dimensions_cm'] ?? '') ?>">
                            <input type="hidden" name="dimensions_in" data-dimensions-in value="<?= e($editArtwork['dimensions_in'] ?? '') ?>">
                        </fieldset>
                    </div>

                    <fieldset class="admin-fieldset">
                        <legend>3. Estado & Mapa (Obras colocadas)</legend>
                        <div class="admin-fieldset-grid">
                            <label>Disponibilidad
                                <select name="status">
                                    <option value="available" <?= ($editArtwork['status'] ?? '') === 'available' ? 'selected' : '' ?>>In Studio (Disponible)</option>
                                    <option value="sold" <?= ($editArtwork['status'] ?? '') === 'sold' ? 'selected' : '' ?>>Sold (Vendida)</option>
                                    <option value="placed" <?= ($editArtwork['status'] ?? '') === 'placed' ? 'selected' : '' ?>>Placed (Colocada)</option>
                                    <option value="reserved" <?= ($editArtwork['status'] ?? '') === 'reserved' ? 'selected' : '' ?>>Reserved (Reservada)</option>
                                    <option value="archive" <?= ($editArtwork['status'] ?? '') === 'archive' ? 'selected' : '' ?>>Archived / Private (Archivada)</option>
                                </select>
                            </label>
                            <label style="display: flex; align-items: center; gap: 10px; font-weight: bold; cursor: pointer; height: 100%; padding-top: 14px;">
                                <input type="checkbox" name="pinned" value="1" <?= !empty($editArtwork['pinned']) ? 'checked' : '' ?> style="width: auto; min-height: auto; cursor: pointer;">
                                <span>Anclar como Obra Principal (Máx 3)</span>
                            </label>
                            <input type="hidden" name="sale_platform" value="<?= e($editArtwork['sale_platform'] ?? '') ?>">
                            <input type="hidden" name="sale_result" value="<?= e($editArtwork['sale_result'] ?? '') ?>">
                            
                            <div class="admin-span-2 admin-fieldset-grid-sub map-fields-container">
                                <label>Cód. Postal / ZIP <input name="postal_code" value="<?= e($editLocation['postal_code'] ?? '') ?>"></label>
                                <label>País
                                    <select name="country">
                                        <?php foreach (admin_country_options() as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= ($editLocation['country'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Latitud <input name="lat" value="<?= e($editLocation['lat'] ?? '') ?>" inputmode="decimal"></label>
                                <label>Longitud <input name="lng" value="<?= e($editLocation['lng'] ?? '') ?>" inputmode="decimal"></label>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="admin-fieldset">
                        <legend>4. Textos y Curaduría</legend>
                        <div class="admin-fieldset-grid">
                            <label class="admin-span-2">Resumen / Descripción Corta <textarea name="summary" rows="3"><?= e($editArtwork['summary'] ?? '') ?></textarea></label>
                            <label class="admin-span-2">Nota Conceptual <textarea name="concept" rows="5"><?= e($editArtwork['concept'] ?? '') ?></textarea></label>
                            <label class="admin-span-2">Nota de Estudio (Comercial) <textarea name="commercial_note" rows="3"><?= e($editArtwork['commercial_note'] ?? '') ?></textarea></label>
                        </div>
                    </fieldset>

                    <fieldset class="admin-fieldset">
                        <legend>5. Fotografías y Archivos Multimedia</legend>
                        <div class="admin-fieldset-grid">
                            <div class="admin-span-2 admin-media-upload-row">
                                <div class="admin-root-image-box admin-root-image">
                                    <div class="admin-root-image__preview" data-root-image-preview>
                                        <?php if (!empty($editArtwork['image'])): ?>
                                            <img src="<?= e(asset_url($editArtwork['image'])) ?>" alt="Preview">
                                        <?php else: ?>
                                            <span class="preview-placeholder">Sin imagen principal</span>
                                        <?php endif; ?>
                                    </div>
                                    <label class="admin-root-image__upload-btn">
                                        <span>Seleccionar Imagen Principal</span>
                                        <input type="file" name="image_upload" accept="image/jpeg,image/png,image/webp" data-root-image-input>
                                    </label>
                                    <input type="hidden" name="image" value="<?= e($editArtwork['image'] ?? '') ?>">
                                </div>
                            </div>

                            <div class="admin-span-2">
                                <label>Subir fotos de detalle (Múltiples)
                                    <input type="file" name="detail_uploads[]" accept="image/jpeg,image/png,image/webp" multiple>
                                </label>
                                <input type="hidden" name="detail_image" value="<?= e($editArtwork['detail_image'] ?? '') ?>">
                                <input type="hidden" name="detail_images" value="<?= e($detailText) ?>">
                            </div>

                            <div class="admin-span-2">
                                <label>Subir montajes / mockups (Múltiples)
                                    <input type="file" name="mockup_uploads[]" accept="image/jpeg,image/png,image/webp" multiple>
                                </label>
                                <input type="hidden" name="mockups" value="<?= e($mockupText) ?>">
                            </div>

                            <?php if (!empty($editDetailImages) || !empty($editArtwork['mockups'])): ?>
                                <div class="admin-span-4 admin-existing-galleries">
                                    <?php if (!empty($editDetailImages)): ?>
                                        <div class="admin-gallery-group">
                                            <p class="admin-image-group-title">Fotos de detalle actuales (Arrastra para reordenar)</p>
                                            <div class="admin-mockup-thumbs" aria-label="Detail photos" data-draggable-list="detail-images" data-target-input-name="detail_images" data-drag-axis="horizontal">
                                                <?php foreach ($editDetailImages as $detail): ?>
                                                    <?php if (!empty($detail['image'])): ?>
                                                        <a href="<?= e(asset_url($detail['image'])) ?>" target="_blank" rel="noopener" draggable="true" data-draggable-item="true">
                                                            <img src="<?= e(asset_url($detail['image'])) ?>" alt="Detail">
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($editArtwork['mockups'])): ?>
                                        <div class="admin-gallery-group">
                                            <p class="admin-image-group-title">Mockups actuales (Arrastra para reordenar)</p>
                                            <div class="admin-mockup-thumbs" aria-label="Mockups" data-draggable-list="mockups" data-target-input-name="mockups" data-drag-axis="horizontal">
                                                <?php foreach ($editArtwork['mockups'] as $mockup): ?>
                                                    <?php if (!empty($mockup['image'])): ?>
                                                        <a href="<?= e(asset_url($mockup['image'])) ?>" target="_blank" rel="noopener" draggable="true" data-draggable-item="true">
                                                            <img src="<?= e(asset_url($mockup['image'])) ?>" alt="Mockup">
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </fieldset>

                    <button class="button admin-span admin-submit-btn" type="submit"><?= $editArtworkSlug ? 'Actualizar Obra' : 'Crear Obra' ?></button>
                </form>
            </section>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

function render_privacy_policy(array $site): void
{
    ?>
    <section class="page-hero">
        <p class="eyebrow"><?= e(site_copy('privacy.eyebrow')) ?></p>
        <h1><?= e(site_copy('privacy.title')) ?></h1>
        <p><?= e(site_copy('privacy.intro')) ?></p>
    </section>
    <section class="section artist-statement-body">
        <div>
            <p class="eyebrow"><?= e(site_copy('privacy.legal')) ?></p>
            <h2><?= e(site_copy('privacy.data_title')) ?></h2>
        </div>
        <div class="prose">
            <p><?= e(site_copy('privacy.site_text')) ?></p>
            <h3><?= e(site_copy('privacy.personal_title')) ?></h3>
            <p><?= e(site_copy('privacy.personal_text')) ?></p>
            <h3><?= e(site_copy('privacy.analytics_title')) ?></h3>
            <p><?= e(site_copy('privacy.analytics_text')) ?></p>
            <h3><?= e(site_copy('privacy.third_title')) ?></h3>
            <p><?= e(site_copy('privacy.third_text')) ?></p>
            <h3><?= e(site_copy('privacy.rights_title')) ?></h3>
            <p><?= e(site_copy('privacy.rights_text', ['email' => $site['email']])) ?></p>
        </div>
    </section>
    <?php
}

admin_handle_post($site, $series, $artworks, $sold_records, $sold_locations, $journal);

$profile = app_artist_profile()?->get();
$artistName = $profile['artist_name'] ?? 'Maurizio Valch';

ob_start();
$handled = true;

switch ($segments[0] ?? '') {
    case '':
        $meta = page_meta(
            site_copy('home.meta_title'),
            site_copy('home.meta_description'),
            $site['url'] . '/'
        );
        render_home($site, $artworks);
        break;
    case 'available-paintings':
        header('Location: ' . url_for('paintings') . '?status=available', true, 301);
        exit;
    case 'paintings':
        header('Location: ' . url_for('artworks' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 302);
        exit;
    case 'artworks':
        $profile = app_artist_profile()?->get();
        $artistName = $profile['artist_name'] ?? 'Artist';
        $publishedCatalog = app_catalog();
        $publishedItems = $publishedCatalog?->all() ?? [];
        if (!isset($segments[1])) {
            $meta = page_meta(
                site_t('Published Artworks', 'Obras publicadas') . ' | ' . $artistName,
                site_t('Published original paintings by ', 'Pinturas originales publicadas de ') . $artistName
                    . site_t(' with contextual mockups and individual visual records.', ' con estudios de contexto y registros visuales individuales.'),
                $site['url'] . '/artworks/'
            );
            // Unlisted publications stay reachable by direct link (below) but must not appear in the main catalog grid.
            $publicItems = array_filter($publishedItems, fn(array $item): bool => $item['visibility'] === 'public');
            
            // Apply search query filtering
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                // What visitors type here is direct vocabulary intelligence.
                app_record_site_event('search', 'catalog', '', $siteLanguage, (string)($_SERVER['HTTP_REFERER'] ?? ''), $q);
                $publicItems = array_filter($publicItems, function(array $item) use ($q): bool {
                    $term = mb_strtolower($q);
                    return mb_strpos(mb_strtolower((string)$item['title']), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['series'] ?? '')), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['short_description'] ?? '')), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['description'] ?? '')), $term) !== false;
                });
            }
            
            render_published_catalog(
                $publicItems,
                site_t('Artworks', 'Obras'),
                site_t('Selected works published by ', 'Obras seleccionadas publicadas por ') . $artistName . site_t(', with their contextual studies and visual records.', ', con sus estudios de contexto y registros visuales.'),
                site_t('Artwork Catalog', 'Catálogo de obras')
            );
            break;
        }

        $publishedArtwork = $publishedCatalog?->one($segments[1]);
        if (!$publishedArtwork) { $handled = false; break; }
        if (($segments[2] ?? '') === 'video' && !isset($segments[3])) {
            if (!is_array($publishedArtwork['video'] ?? null)) { $handled = false; break; }
            if ($segments[1] !== (string)$publishedArtwork['slug']) {
                header('Location: ' . artist_site_url_with_language(
                    url_for('artworks/' . $publishedArtwork['slug'] . '/video'),
                    artist_site_language()
                ), true, 301);
                exit;
            }
            $videoDescription = trim((string)($publishedArtwork['short_description'] ?: $publishedArtwork['description']));
            $videoTitle = site_t('Artwork Video: ', 'Video de la obra: ') . $publishedArtwork['title'] . ' | ' . $artistName;
            $videoCanonical = rtrim((string)$site['url'], '/') . '/artworks/' . $publishedArtwork['slug'] . '/video/';
            $meta = page_meta(
                $videoTitle,
                $videoDescription,
                $videoCanonical,
                !empty($publishedArtwork['video']['has_poster'])
                    ? app_publication_video_url($publishedArtwork, true)
                    : app_publication_media_url($publishedArtwork, (string)$publishedArtwork['source_image_file'])
            );
            $meta['keywords'] = trim(implode(', ', array_filter([
                (string)($publishedArtwork['artwork_keywords'] ?? ''),
                (string)($publishedArtwork['artwork_tags'] ?? ''),
                (string)($publishedArtwork['series'] ?? ''),
            ])));
            artist_site_set_language_urls([
                'en' => url_for('artworks/' . $publishedArtwork['slug'] . '/video'),
                'es' => url_for('artworks/' . $publishedArtwork['slug'] . '/video'),
            ]);
            render_published_artwork_video($site, $publishedArtwork);
            break;
        }
        if (($segments[2] ?? '') === 'mockups' && isset($segments[3])) {
            $record = $publishedCatalog?->mockup($segments[1], $segments[3]);
            if (!$record) { $handled = false; break; }
            $publishedArtwork = $record['artwork'];
            $mockup = $record['mockup'];
            $currentMockupSlug = artist_site_language() === 'es'
                ? (string)$mockup['public_slug_es']
                : (string)$mockup['public_slug_en'];
            if ($segments[1] !== (string)$publishedArtwork['slug'] || $segments[3] !== $currentMockupSlug) {
                header('Location: ' . artist_site_url_with_language(
                    url_for('artworks/' . $publishedArtwork['slug'] . '/mockups/' . $currentMockupSlug),
                    artist_site_language()
                ), true, 301);
                exit;
            }
            $localLanguageUrls = [
                'en' => url_for('artworks/' . $publishedArtwork['slug'] . '/mockups/' . $mockup['public_slug_en']),
                'es' => url_for('artworks/' . $publishedArtwork['slug'] . '/mockups/' . $mockup['public_slug_es']),
            ];
            artist_site_set_language_urls($localLanguageUrls);
            $mockupTitle = trim((string)($mockup['title'] ?: $publishedArtwork['title'] . ' — Context Study'));
            $mockupDescription = trim((string)($mockup['description'] ?: $mockup['caption'] ?: site_t('A contextual presentation of ', 'Una presentación contextual de ') . $publishedArtwork['title'] . site_t(' by ', ' por ') . $artistName . '.'));
            $mockupSeoTitle = trim((string)($mockup['seo_title'] ?? '')) ?: $mockupTitle . ' | ' . $artistName;
            $mockupSeoDescription = trim((string)($mockup['seo_description'] ?? '')) ?: $mockupDescription;
            $canonicalBase = preg_replace('~/(?:en|es)$~', '', rtrim((string)$site['url'], '/'))
                . '/artworks/' . $publishedArtwork['slug'] . '/mockups/';
            $meta = page_meta($mockupSeoTitle, $mockupSeoDescription, $canonicalBase . $currentMockupSlug . '/', app_publication_media_url($publishedArtwork, $mockup['mockup_file']));
            $meta['language_urls'] = [
                'en' => $canonicalBase . $mockup['public_slug_en'] . '/',
                'es' => $canonicalBase . $mockup['public_slug_es'] . '/',
            ];
            $meta['keywords'] = trim((string)$mockup['keywords']);
            render_published_mockup($site, $publishedArtwork, $mockup);
            break;
        }
        if (isset($segments[2])) { $handled = false; break; }
        if ($segments[1] !== (string)$publishedArtwork['slug']) {
            header('Location: ' . artist_site_url_with_language(
                url_for('artworks/' . $publishedArtwork['slug']),
                artist_site_language()
            ), true, 301);
            exit;
        }
        $description = trim((string)($publishedArtwork['short_description'] ?: $publishedArtwork['description']));
        $artworkSeoTitle = published_seo_title(
            trim((string)($publishedArtwork['seo_title'] ?? '')),
            (string)$publishedArtwork['title'],
            (string)$artistName
        ) ?: $publishedArtwork['title'] . ' | ' . $artistName;
        $artworkSeoDescription = trim((string)($publishedArtwork['seo_description'] ?? '')) ?: $description;
        $artworkMetaImage = trim((string)($publishedArtwork['header_file'] ?? '')) ?: (string)$publishedArtwork['source_image_file'];
        $meta = page_meta($artworkSeoTitle, $artworkSeoDescription, $site['url'] . '/artworks/' . $segments[1] . '/', app_publication_media_url($publishedArtwork, $artworkMetaImage));
        $meta['keywords'] = trim(implode(', ', array_filter([
            (string)($publishedArtwork['artwork_keywords'] ?? ''),
            (string)($publishedArtwork['artwork_tags'] ?? ''),
        ])));
        render_published_artwork($site, $publishedArtwork);
        break;
    case 'acquire':
        if (!isset($segments[1]) || isset($segments[2])) { $handled = false; break; }
        $publishedArtwork = app_catalog()?->one($segments[1]);
        if (!$publishedArtwork) { $handled = false; break; }
        $meta = page_meta('Acquire ' . $publishedArtwork['title'] . ' | ' . $artistName, 'Private acquisition request for ' . $publishedArtwork['title'] . '.', $site['url'] . '/acquire/' . $segments[1] . '/', app_publication_media_url($publishedArtwork, (string)$publishedArtwork['source_image_file']));
        $meta['robots'] = 'noindex,nofollow';
        render_acquisition($site, $publishedArtwork);
        break;
    case 'payment':
        if (($segments[1] ?? '') !== 'success' || isset($segments[2])) { $handled = false; break; }
        $meta = page_meta('Payment confirmation | ' . $artistName, 'Secure acquisition payment confirmation.', $site['url'] . '/payment/success/');
        $meta['robots'] = 'noindex,nofollow';
        render_stripe_payment_result();
        break;
    case 'sold-works':
        $profile = app_artist_profile()?->get();
        $artistName = $profile['artist_name'] ?? 'Artist';
        
        $publishedCatalog = app_catalog();
        $publishedItems = $publishedCatalog?->all() ?? [];
        $publicItems = array_filter($publishedItems, fn(array $item): bool => $item['visibility'] === 'public');

        $managedConstellations = $publishedCatalog?->constellations() ?? [];
        if ($managedConstellations) {
            $soldItems = array_filter($publicItems, static function (array $item) use ($managedConstellations): bool {
                return isset($managedConstellations[(int)($item['canonical_artwork_id'] ?? 0)]);
            });
        } else {
            // Preserve legacy keyword-driven entries until the artist manages Constellations explicitly.
            $soldItems = array_filter($publicItems, function(array $item): bool {
                $keywords = strtolower((string)($item['artwork_keywords'] ?? ''));
                $tags = strtolower((string)($item['artwork_tags'] ?? ''));
                return str_contains($keywords, 'sold')
                    || str_contains($keywords, 'constellation')
                    || str_contains($tags, 'sold')
                    || str_contains($tags, 'constellation');
            });
        }

        $soldRecords = [];
        $dynamicLocations = [];
        $allowedCountries = ['spain', 'united states', 'uruguay', 'france', 'germany', 'italy', 'uk', 'canada', 'mexico', 'brazil', 'argentina', 'colombia'];

        foreach ($soldItems as $slug => $item) {
            $managedLocation = $managedConstellations[(int)($item['canonical_artwork_id'] ?? 0)] ?? null;
            $dims = $item['width'] && $item['height'] 
                ? $item['width'] . ' × ' . $item['height'] . ($item['depth'] ? ' × ' . $item['depth'] : '') . ' ' . $item['unit']
                : 'Custom dimensions';
            $soldRecords[] = [
                'title' => $item['title'],
                'year' => $item['artwork_year'] ?: 'N/A',
                'dimensions' => $dims,
                'platform' => 'Private Collection',
                'public_price' => 'Placed',
                'cluster' => $item['series'] ?: 'Independent Study',
                'url' => url_for('artworks/' . $slug)
            ];

            if ($managedLocation) {
                if ((string)$managedLocation['privacy'] === 'private') continue;
                $country = trim((string)$managedLocation['country']);
                $coordinates = null;
                if ((string)$managedLocation['privacy'] === 'approximate'
                    && is_numeric($managedLocation['latitude']) && is_numeric($managedLocation['longitude'])) {
                    $coordinates = ['lat' => (float)$managedLocation['latitude'], 'lng' => (float)$managedLocation['longitude']];
                }
                $coordinates ??= $country !== '' ? country_coordinates($country) : null;
                if ($coordinates) {
                    $dynamicLocations[] = [
                        'title' => $item['title'],
                        'artwork_slug' => $slug,
                        'country' => $country,
                        'postal_code' => (string)$managedLocation['privacy'] === 'approximate' ? (string)$managedLocation['postal_code'] : '',
                        'lat' => $coordinates['lat'],
                        'lng' => $coordinates['lng'],
                    ];
                }
                continue;
            }

            // Detect country for map coordinates
            $keywords = strtolower((string)($item['artwork_keywords'] ?? ''));
            $tags = strtolower((string)($item['artwork_tags'] ?? ''));
            $combined = $keywords . ' ' . $tags;
            $detectedCountry = 'Spain'; // Fallback
            foreach ($allowedCountries as $c) {
                if (str_contains($combined, $c)) {
                    $detectedCountry = ucwords($c);
                    break;
                }
            }
            $coords = country_coordinates($detectedCountry);

            $dynamicLocations[] = [
                'title' => $item['title'],
                'artwork_slug' => $slug,
                'country' => $detectedCountry,
                'postal_code' => '',
                'lat' => $coords ? $coords['lat'] : 39.4699,
                'lng' => $coords ? $coords['lng'] : -0.3763,
            ];
        }

        $mergedLocations = $managedConstellations ? $dynamicLocations : array_merge($sold_locations, $dynamicLocations);

        $meta = page_meta(
            site_copy('constellations.meta_title', ['artist' => $artistName]),
            site_copy('constellations.meta_description'),
            $site['url'] . '/sold-works/'
        );
        render_published_catalog(
            $soldItems,
            site_copy('constellations.title'),
            site_copy('constellations.intro', ['artist' => $artistName]),
            site_copy('constellations.eyebrow'),
            $mergedLocations,
            $soldRecords,
            true
        );
        break;
    case 'series':
        $publishedSeries = app_series_catalog()?->all() ?? [];
        if (isset($segments[1])) {
            $seriesItem = $publishedSeries[$segments[1]] ?? null;
            if (!$seriesItem) { $handled = false; break; }
            $seriesMetaTitle = trim((string)($seriesItem['seo_title'] ?? ''));
            $seriesMetaDescription = trim((string)($seriesItem['seo_description'] ?? ''));
            $meta = page_meta(
                $seriesMetaTitle !== '' ? $seriesMetaTitle : $seriesItem['title'] . ' | ' . $artistName,
                $seriesMetaDescription !== '' ? $seriesMetaDescription : (string)$seriesItem['description'],
                $site['url'] . '/series/' . $segments[1] . '/'
            );
            render_published_series_detail($seriesItem);
        } else {
            $meta = page_meta(site_t('Painting Series', 'Series pictóricas') . ' | ' . $artistName, site_t('Artwork series and concept clusters managed from Artwork Mockups.', 'Series y núcleos conceptuales gestionados desde Artwork Mockups.'), $site['url'] . '/series/');
            render_published_series_index($publishedSeries);
        }
        break;
    case 'series2':
        header('Location: ' . url_for('series' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 302);
        exit;
    case 'artist':
        $profile = app_artist_profile()?->get();
        if (!$profile) { $handled = false; break; }
        $profile = localized_artist_profile($profile);
        $description = trim((string)($profile['short_bio'] ?: $profile['statement']));
        $photoFile = trim((string)($profile['photo_file'] ?? ''));
        $metaImage = $photoFile !== '' ? app_artist_photo_url($photoFile) : '';
        $meta = page_meta(
            site_copy('artist.meta_title', ['artist' => $profile['artist_name'] ?: $artistName]),
            site_copy('artist.meta_description'),
            $site['url'] . '/artist/',
            $metaImage
        );
        render_published_artist_page($profile);
        break;
    case 'artist-2':
        header('Location: ' . url_for('artist/'), true, 302);
        exit;
    case 'artist-statement':
        $meta = page_meta(
            site_copy('statement.meta_title', ['artist' => $artistName]),
            site_copy('statement.meta_description'),
            $site['url'] . '/artist-statement/'
        );
        render_statement($site);
        break;
    case 'studio-process':
        $meta = page_meta(
            site_copy('process.meta_title', ['artist' => $artistName]),
            site_copy('process.meta_description'),
            $site['url'] . '/studio-process/'
        );
        render_process();
        break;
    case 'exhibitions-collections':
        $meta = page_meta(
            site_copy('exhibitions.meta_title', ['artist' => $artistName]),
            site_copy('exhibitions.meta_description'),
            $site['url'] . '/exhibitions-collections/'
        );
        render_exhibitions($site);
        break;
    case 'journal':
        header('Location: ' . url_for('studio-notes' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 301);
        exit;
    case 'blog':
        header('Location: ' . url_for('studio-notes' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 302);
        exit;
    case 'studio-notes':
        $publishedNotes = app_studio_notes_catalog()?->all($siteLanguage) ?? [];
        if (isset($segments[1])) {
            $slug = $segments[1];
            if (!isset($publishedNotes[$slug])) {
                foreach ($publishedNotes as $localizedSlug => $candidateNote) {
                    if ((string)($candidateNote['legacy_slug'] ?? '') !== $slug) continue;
                    header('Location: ' . url_for('studio-notes/' . $localizedSlug . '/'), true, 301);
                    exit;
                }
                $handled = false;
                break;
            }
            $post = $publishedNotes[$slug];
            $languageSlugs = (array)($post['language_slugs'] ?? []);
            $languageUrls = [];
            foreach (['en', 'es'] as $language) {
                $languageSlug = trim((string)($languageSlugs[$language] ?? ''));
                if ($languageSlug !== '') {
                    $languageUrls[$language] = $site['url'] . '/studio-notes/' . $languageSlug . '/';
                }
            }
            $description = trim((string)($post['seo_description'] ?? ''))
                ?: trim((string)($post['excerpt'] ?? ''))
                ?: trim(strip_tags((string)$post['objective']));
            if (mb_strlen($description) > 160) $description = mb_substr($description, 0, 157) . '...';
            $meta = page_meta(
                trim((string)($post['seo_title'] ?? '')) ?: $post['title'] . ' | ' . $artistName,
                $description,
                $site['url'] . '/studio-notes/' . $slug . '/'
            );
            $meta['language_urls'] = $languageUrls;
            $handled = render_published_journal_post($publishedNotes, $slug);
        } else {
            $meta = page_meta(
                site_t('Studio Notes', 'Notas de estudio') . ' | ' . $artistName,
                site_t(
                    'Studio Notes on architectural abstract painting, territory, silence, presence and structural metaphysical work by ' . $artistName . '.',
                    'Notas de estudio sobre pintura abstracta, territorio, silencio, presencia y pintura metafísica estructural de ' . $artistName . '.'
                ),
                $site['url'] . '/studio-notes/'
            );
            render_published_journal($publishedNotes);
        }
        break;
    case 'contact':
        $meta = page_meta(
            site_copy('contact.meta_title', ['artist' => $artistName]),
            site_copy('contact.meta_description'),
            $site['url'] . '/contact/'
        );
        render_contact($site, $artworks);
        break;
    case 'privacy-policy':
        $meta = page_meta(
            site_copy('privacy.meta_title', ['artist' => $artistName]),
            site_copy('privacy.meta_description'),
            $site['url'] . '/privacy-policy/'
        );
        render_privacy_policy($site);
        break;
    case 'admin':
        $meta = page_meta('Admin | ' . $artistName, 'Studio administration for ' . $artistName . ' website content.', $site['url'] . '/admin/');
        render_admin_page($site, $series, $artworks, $sold_records, $sold_locations, $journal);
        break;
    default:
        $handled = false;
}

$content = ob_get_clean();

if (!$handled) {
    http_response_code(404);
    $meta = page_meta(
        site_copy('errors.not_found_title', ['artist' => $artistName]),
        site_copy('errors.not_found_description'),
        $site['url'] . $path
    );
    $content = '<section class="page-hero"><p class="eyebrow">404</p><h1>'
        . e(site_copy('errors.not_found_heading')) . '</h1><p>'
        . e(site_copy('errors.not_found_text')) . '</p><a class="button" href="'
        . e(url_for('/')) . '">' . e(site_copy('errors.return_home')) . '</a></section>';
}

require __DIR__ . '/inc/header.php';
echo $content;
require __DIR__ . '/inc/footer.php';
