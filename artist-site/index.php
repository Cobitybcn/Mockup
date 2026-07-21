<?php
require __DIR__ . '/data/site.php';
require __DIR__ . '/inc/functions.php';
require __DIR__ . '/inc/AppDatabase.php';
require __DIR__ . '/inc/TenantResolver.php';
require __DIR__ . '/inc/AppPublishedCatalog.php';
require __DIR__ . '/inc/AppPublishedSeriesCatalog.php';
require __DIR__ . '/inc/AppPublishedArtistProfile.php';
require __DIR__ . '/inc/AppPublishedStudioNotes.php';
require __DIR__ . '/inc/AppPublishedSiteSettings.php';

$path = current_path();
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

$resolvedArtistEmail = '';
try {
    $resolvedArtistEmail = resolved_artist_email();
} catch (Throwable $error) {
    http_response_code(503);
    header('Retry-After: 60');
    exit('Artist site configuration is temporarily unavailable.');
}

try {
    $managedSiteSettings = AppPublishedSiteSettings::fromApp(dirname(__DIR__) . '/platform', $resolvedArtistEmail)->get();
    if (trim((string)($managedSiteSettings['site_title'] ?? '')) !== '') $site['name'] = trim((string)$managedSiteSettings['site_title']);
    if (trim((string)($managedSiteSettings['tagline'] ?? '')) !== '') $site['tagline'] = trim((string)$managedSiteSettings['tagline']);
    if (trim((string)($managedSiteSettings['contact_email'] ?? '')) !== '') $site['email'] = trim((string)$managedSiteSettings['contact_email']);
    $site['inquiry_intro'] = trim((string)($managedSiteSettings['inquiry_intro'] ?? ''));
} catch (Throwable $error) {
    error_log('Artist Site Manager settings unavailable: ' . $error->getMessage());
}

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    $urls = ['/', '/paintings/', '/artworks/', '/sold-works/', '/series/', '/artist/', '/artist-statement/', '/studio-process/', '/exhibitions-collections/', '/studio-notes/', '/blog/', '/contact/', '/privacy-policy/'];
    foreach (array_keys($artworks) as $slug) {
        $urls[] = '/paintings/' . $slug . '/';
    }
    foreach (array_keys($series) as $slug) {
        $urls[] = '/series/' . $slug . '/';
    }
    foreach (array_keys($journal) as $slug) {
        $urls[] = '/studio-notes/' . $slug . '/';
    }
    foreach (array_keys($blog ?? []) as $slug) {
        $urls[] = '/blog/' . $slug . '/';
    }
    try {
        foreach (app_catalog()?->all() ?? [] as $slug => $publishedArtwork) {
            if ($publishedArtwork['visibility'] !== 'public') continue;
            $urls[] = '/artworks/' . $slug . '/';
            foreach ($publishedArtwork['items'] as $mockup) {
                $urls[] = '/artworks/' . $slug . '/mockups/' . $mockup['public_slug'] . '/';
            }
        }
    } catch (Throwable $error) {
        error_log('Published artwork sitemap unavailable: ' . $error->getMessage());
    }
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $url) {
        echo "  <url><loc>" . e($site['url'] . $url) . "</loc></url>\n";
    }
    echo "</urlset>";
    exit;
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
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

function render_home(array $site, array $series, array $artworks): void
{
    $available = artworks_by_status($artworks, 'available');
    $heroSlides = array_values(array_filter(array_map(
        fn ($artwork) => !empty($artwork['image']) ? [
            'image' => $artwork['image'],
            'title' => $artwork['title'] ?? 'Maurizio Valch artwork',
        ] : null,
        $available
    )));
    if (!$heroSlides) {
        $heroSlides[] = [
            'image' => $site['hero_image'] ?? '/assets/images/the-path-before-architecture.jpg',
            'title' => 'Maurizio Valch artwork',
        ];
    }
    ?>
    <section class="hero">
        <div class="hero__media" data-hero-slider>
            <div class="hero__slides">
                <?php foreach ($heroSlides as $index => $slide): ?>
                    <img class="hero__slide" src="<?= e(asset_url($slide['image'])) ?>" alt="<?= e($slide['title'] . ' root artwork image') ?>" data-hero-slide <?= $index === 0 ? 'data-active="true"' : '' ?>>
                <?php endforeach; ?>
            </div>
            <?php if (count($heroSlides) > 1): ?>
                <button class="hero__arrow hero__arrow--prev" type="button" data-hero-prev aria-label="Previous artwork image">‹</button>
                <button class="hero__arrow hero__arrow--next" type="button" data-hero-next aria-label="Next artwork image">›</button>
            <?php endif; ?>
        </div>
        <div class="hero__content">
            <p class="eyebrow">Abstract Painting / Territory and Thought</p>
            <h1>Strata, fault lines and ground frequency</h1>
            <p class="lead">A catalog of works organized by scale, status, series, monoliths, horizons, tectonic tension, and the sedimented time of territory.</p>
            <form class="hero-search" action="<?= e(url_for('paintings')) ?>" method="get" role="search">
                <label class="sr-only" for="hero-search-input">Search artwork catalog</label>
                <input id="hero-search-input" name="q" type="search" placeholder="Try: strata, fault lines, monolith, 120 cm">
                <button type="submit">Search Catalog</button>
            </form>
            <div class="actions">
                <a class="button" href="<?= e(url_for('paintings')) ?>">Open Catalog</a>
                <a class="button button--quiet" href="<?= e(url_for('sold-works')) ?>">View Constellations</a>
                <a class="button button--quiet" href="<?= e(url_for('artist-statement')) ?>">Read Artist Statement</a>
            </div>
        </div>
    </section>

    <section class="section search-paths">
        <div class="path-card">
            <p class="eyebrow">Catalog</p>
            <h2>Root images</h2>
            <p>The catalog begins with one essential image per work. Detail pages hold the complete visual context and mockup sets.</p>
            <a href="<?= e(url_for('paintings')) ?>">Enter catalog</a>
        </div>
        <div class="path-card">
            <p class="eyebrow">Archive</p>
            <h2>Constellations</h2>
            <p>A map of works that have left the studio, preserving provenance context without reducing the work to transaction.</p>
            <a href="<?= e(url_for('sold-works')) ?>">View constellations</a>
        </div>
        <div class="path-card">
            <p class="eyebrow">Explore</p>
            <h2>Series and concepts</h2>
            <p>Browse by strata, fault lines, ground frequency, monoliths, horizons, and structural silence.</p>
            <a href="<?= e(url_for('series')) ?>">Explore series</a>
        </div>
    </section>

    <section class="section section--split">
        <div>
            <p class="eyebrow">For collectors, architects and curators</p>
            <h2>Structural metaphysical painting for slow perception</h2>
        </div>
        <div class="prose">
            <p>Valch's work is built for slow perception: controlled mass, spatial silence, and structural clarity. The paintings function as contemplative presences rather than decorative abstractions.</p>
            <p>The catalog is not arranged as a store. It is a visual index: root images, contextual mockups, conceptual notes, and a quiet distinction between works in the studio and works already placed.</p>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <p class="eyebrow">In studio</p>
            <h2>Selected Works</h2>
            <a href="<?= e(url_for('paintings')) ?>?status=available">Filter available works</a>
        </div>
        <div class="art-grid">
            <?php foreach ($available as $slug => $artwork) render_artwork_card($slug, $artwork, $series); ?>
        </div>
    </section>

    <section class="section band">
        <p class="eyebrow">Artist identity</p>
        <h2>Painting as sedimented structure</h2>
        <p>At the center of this language stands the ground: strata, fault lines, and frequencies that precede language. The monolith anchors presence. The horizon organizes perception. Silence is not absence; it is structure.</p>
    </section>

    <section class="section">
        <div class="section-head">
            <p class="eyebrow">Concept clusters</p>
            <h2>Series and Concepts</h2>
            <a href="<?= e(url_for('series')) ?>">Explore all series</a>
        </div>
        <div class="series-grid">
            <?php foreach ($series as $slug => $item): ?>
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
    <section class="section constellation-section" aria-label="World map of placed works">
        <div class="constellation-real-map" aria-label="Night world map of placed works" data-constellation-leaflet data-map-items="<?= e(json_encode($mapItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">
            <div class="constellation-real-map__canvas" data-map-canvas aria-label="Placed works on the map"></div>
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

function app_publication_media_url(array $artwork, string $file): string
{
    return artworkmockups_public_url() . '/publication_media.php?slug=' . rawurlencode((string)$artwork['slug']) . '&file=' . rawurlencode(basename($file));
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
            $catalog = AppPublishedSeriesCatalog::fromApp(dirname(__DIR__) . '/platform', resolved_artist_email());
        } catch (Throwable $error) {
            error_log('Published series catalog unavailable: ' . $error->getMessage());
            $catalog = null;
        }
    }
    return $catalog;
}

function app_series_media_url(array $series, string $file): string
{
    if ($file === '') return '';
    return artworkmockups_public_url() . '/series_media.php?slug=' . rawurlencode((string)$series['slug']) . '&file=' . rawurlencode(basename($file));
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

function app_studio_note_media_url(array $post, string $file): string
{
    if ($file === '' || (int)($post['id'] ?? 0) <= 0) return '';
    return artworkmockups_public_url() . '/studio_note_media.php?note=' . (int)$post['id'] . '&file=' . rawurlencode(basename($file));
}

function first_html_image_src(string $html): string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
        return $matches[1];
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
    return $cm . ' / ' . $in;
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
            'image' => app_publication_media_url($artwork, $cardImageFile),
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
                    <a class="constellation-real-map__link" data-constellation-link href="<?= e($mapItems[0]['url'] ?? '#') ?>">Study placement context</a>
                </div>
            </aside>
        </div>
    </section>
    <?php
}

function render_published_catalog(array $items, string $title = 'Artworks', string $intro = 'Selected works published by the artist, with their contextual studies and visual records.', string $eyebrow = 'Artwork Catalog', array $soldLocations = [], array $soldRecords = []): void
{
    ?>
    <section class="page-hero <?= $eyebrow === 'Constellations' ? 'page-hero--compact' : '' ?>">
        <p class="eyebrow"><?= e($eyebrow) ?></p>
        <h1><?= e($title) ?></h1>
        <p><?= e($intro) ?></p>
    </section>
    <?php if ($eyebrow === 'Constellations' && !empty($soldLocations)): ?>
        <?php render_published_constellation_map($soldLocations, $items); ?>
    <?php endif; ?>
    <section class="section catalog-grid-section">
        <div class="art-grid">
            <?php foreach ($items as $slug => $artwork): ?>
                <?php $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']; ?>
                <article class="art-card">
                    <a class="art-card__image" href="<?= e(url_for('artworks/' . $slug)) ?>">
                        <img src="<?= e(app_publication_media_url($artwork, $cardImageFile)) ?>" alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' by ' . ($artistName ?? 'Artist')) ?>">
                    </a>
                    <div class="art-card__body">
                        <div class="eyebrow"><?= e($artwork['series'] ?: 'Original work') ?></div>
                        <h3><a href="<?= e(url_for('artworks/' . $slug)) ?>"><?= e($artwork['title']) ?></a></h3>
                        <p><?= e(implode(', ', array_filter([$artwork['medium'], published_dimensions($artwork)]))) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if (!$items): ?><p>No artworks have been published here yet.</p><?php endif; ?>
    </section>
    <?php if ($eyebrow === 'Constellations' && !empty($soldRecords)): ?>
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

function render_published_artwork(array $site, array $artwork): void
{
    $summary = trim((string)($artwork['short_description'] ?: $artwork['description']));
    $metadata = $artwork['artwork_metadata'] ?? [];
    $analysis = $artwork['artwork_analysis'] ?? [];
    $facts = $analysis['confirmed_facts'] ?? [];
    $interpretation = $analysis['interpretation'] ?? [];
    $conceptualNote = trim((string)$artwork['description']);
    $studioInformation = trim(implode("\n\n", array_filter([
        (string)($interpretation['central_reading'] ?? ''),
        (string)($metadata['caption'] ?? ''),
    ])));
    $medium = trim((string)($artwork['medium'] ?: ($facts['medium'] ?? '')));
    $year = trim((string)($artwork['artwork_year'] ?: ($facts['year'] ?? '')));
    $mainImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file'];
    $artworkSeriesTitle = trim((string)($artwork['series'] ?? ''));
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
            <img src="<?= e(app_publication_media_url($artwork, $mainImageFile)) ?>" alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' original painting by Maurizio Valch') ?>">
            <?php if ($artwork['artwork_views']): ?>
                <div class="mockup-gallery artwork-view-gallery" aria-label="<?= e($artwork['title'] . ' additional artwork views') ?>">
                    <?php foreach ($artwork['artwork_views'] as $view): ?>
                        <a href="<?= e(app_publication_media_url($artwork, $view['file_name'])) ?>" target="_blank" rel="noopener">
                            <img src="<?= e(app_publication_media_url($artwork, $view['file_name'])) ?>" alt="<?= e($artwork['title'] . ' ' . str_replace('-', ' ', $view['view_type']) . ' view') ?>">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($artwork['items']): ?>
                <div class="mockup-gallery" aria-label="<?= e($artwork['title'] . ' contextual mockups') ?>">
                    <?php foreach ($artwork['items'] as $mockup): ?>
                        <a class="mockup-gallery__link" href="<?= e(url_for('artworks/' . $artwork['slug'] . '/mockups/' . $mockup['public_slug'])) ?>">
                            <img src="<?= e(app_publication_media_url($artwork, $mockup['mockup_file'])) ?>" alt="<?= e($mockup['alt_text'] ?: $mockup['title'] ?: $artwork['title'] . ' contextual mockup') ?>">
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="artwork-detail__content">
            <p class="eyebrow">Published work<?= $artwork['series'] ? ' / ' . e($artwork['series']) : '' ?></p>
            <h1><?= e($artwork['title']) ?></h1>
            <?php if ($artwork['subtitle']): ?><p class="lead"><?= e($artwork['subtitle']) ?></p><?php endif; ?>
            <dl class="specs">
                <?php if ($year): ?><div><dt>Year</dt><dd><?= e($year) ?></dd></div><?php endif; ?>
                <?php if ($medium): ?><div><dt>Medium</dt><dd><?= e($medium) ?></dd></div><?php endif; ?>
                <?php if (published_dimensions($artwork)): ?><div><dt>Size</dt><dd><?= e(published_dimensions($artwork)) ?></dd></div><?php endif; ?>
                <?php if ($artworkSeriesTitle !== ''): ?>
                    <div>
                        <dt>Series</dt>
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
                                                <img src="<?= e(app_series_media_url($publishedSeries, (string)$publishedSeries['header_file'])) ?>" alt="" style="<?= e(series_header_style($publishedSeries)) ?>">
                                            </span>
                                        <?php endif; ?>
                                        <span class="artwork-series-preview__content">
                                            <span class="artwork-series-preview__eyebrow">Painting series</span>
                                            <strong><?= e((string)$publishedSeries['title']) ?></strong>
                                            <span class="artwork-series-preview__meta">
                                                <?= $seriesYear !== '' ? e($seriesYear) . ' · ' : '' ?><?= (int)($publishedSeries['artwork_count'] ?? 0) ?> <?= (int)($publishedSeries['artwork_count'] ?? 0) === 1 ? 'work' : 'works' ?>
                                            </span>
                                            <?php if ($seriesDescription !== ''): ?><span class="artwork-series-preview__description"><?= e($seriesDescription) ?></span><?php endif; ?>
                                            <span class="artwork-series-preview__action">View series</span>
                                        </span>
                                    </span>
                                </span>
                            <?php else: ?>
                                <?= e($artworkSeriesTitle) ?>
                            <?php endif; ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if (!empty($facts['orientation'])): ?><div><dt>Orientation</dt><dd><?= e(ucfirst((string)$facts['orientation'])) ?></dd></div><?php endif; ?>
                <?php if (!empty($facts['certificate_of_authenticity'])): ?><div><dt>Certificate</dt><dd><?= e($facts['certificate_of_authenticity']) ?></dd></div><?php endif; ?>
                <div><dt>Context studies</dt><dd><?= count($artwork['items']) ?></dd></div>
            </dl>
            <div class="prose">
                <?php if ($conceptualNote): ?><h2>Conceptual Note</h2><p><?= nl2br(e($conceptualNote)) ?></p><?php endif; ?>
                <?php if ($studioInformation): ?><h2>Studio Information</h2><p><?= nl2br(e($studioInformation)) ?></p><?php endif; ?>
                <?php if (!empty($facts['shipping_notes'])): ?><h2>Shipping</h2><p><?= nl2br(e($facts['shipping_notes'])) ?></p><?php endif; ?>
            </div>
            <div class="actions"><a class="button" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>">Inquire about this work</a></div>
        </div>
    </section>
    <?php
    echo json_ld(['@context'=>'https://schema.org','@type'=>'VisualArtwork','name'=>$artwork['title'],'creator'=>['@type'=>'Person','name'=>$site['name']],'artMedium'=>$artwork['medium'],'dateCreated'=>$artwork['artwork_year'],'image'=>app_publication_media_url($artwork,$artwork['source_image_file']),'description'=>$summary]);
}

function render_published_mockup(array $site, array $artwork, array $mockup): void
{
    $title = trim((string)($mockup['title'] ?: $artwork['title'] . ' — Context Study'));
    $description = trim((string)($mockup['description'] ?: $mockup['caption'] ?: 'A contextual presentation of ' . $artwork['title'] . ' by Maurizio Valch.'));
    ?>
    <section class="artwork-detail mockup-landing">
        <div class="artwork-detail__image">
            <img src="<?= e(app_publication_media_url($artwork, $mockup['mockup_file'])) ?>" alt="<?= e($mockup['alt_text'] ?: $title) ?>">
        </div>
        <div class="artwork-detail__content">
            <p class="eyebrow">Context study / <?= e($artwork['title']) ?></p>
            <h1><?= e($title) ?></h1>
            <div class="prose"><p><?= nl2br(e($description)) ?></p></div>
            <?php if ($mockup['caption']): ?><p class="lead"><?= e($mockup['caption']) ?></p><?php endif; ?>
            <dl class="specs">
                <div><dt>Original artwork</dt><dd><?= e($artwork['title']) ?></dd></div>
                <?php if ($artwork['medium']): ?><div><dt>Medium</dt><dd><?= e($artwork['medium']) ?></dd></div><?php endif; ?>
                <?php if (published_dimensions($artwork)): ?><div><dt>Actual dimensions</dt><dd><?= e(published_dimensions($artwork)) ?></dd></div><?php endif; ?>
                <?php if ($artwork['artwork_year']): ?><div><dt>Year</dt><dd><?= e($artwork['artwork_year']) ?></dd></div><?php endif; ?>
                <div><dt>Presentation</dt><dd>Digital contextual visualization</dd></div>
            </dl>
            <?php if (trim((string)$mockup['keywords'])): ?>
                <div class="prose"><h2>Context references</h2><p><?= e(str_replace(',', ' · ', (string)$mockup['keywords'])) ?></p></div>
            <?php endif; ?>
            <div class="prose">
                <h2>Collector note</h2>
                <p>This visualization presents the artwork at its declared physical scale in an architectural context. The original work remains the authority for color, surface, dimensions and material presence.</p>
            </div>
            <div class="actions" style="display:flex;flex-direction:column;gap:10px">
                <a class="button" href="<?= e(url_for('contact')) ?>?artwork=<?= e($artwork['slug']) ?>">Inquire about the original work</a>
                <a class="button button--quiet" href="<?= e(url_for('artworks/' . $artwork['slug'])) ?>">Return to the artwork</a>
            </div>
        </div>
    </section>
    <?php
    echo json_ld(['@context'=>'https://schema.org','@type'=>'ImageObject','name'=>$title,'description'=>$description,'contentUrl'=>app_publication_media_url($artwork,$mockup['mockup_file']),'creator'=>['@type'=>'Person','name'=>$site['name']],'isPartOf'=>['@type'=>'VisualArtwork','name'=>$artwork['title'],'url'=>$site['url'].'/artworks/'.$artwork['slug'].'/']]);
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
        <p class="eyebrow">Concept clusters · preview</p>
        <h1>Painting Series</h1>
        <p>Series managed from Artwork Mockups, published here for review before they replace the main Series page.</p>
    </section>
    <section class="section">
        <?php if (!$items): ?>
            <p>No series have been published yet.</p>
        <?php else: ?>
            <div class="series-grid series-grid--primary">
                <?php foreach ($current as $slug => $item): ?>
                    <?php $yearLabel = series_year_label($item); ?>
                    <a class="series-card" href="<?= e(url_for('series2/' . $slug)) ?>">
                        <?php if ($item['header_file']): ?>
                            <div class="series-card__image-container">
                                <img src="<?= e(app_series_media_url($item, (string)$item['header_file'])) ?>" alt="<?= e($item['title'] . ' series thumbnail') ?>" style="<?= e(series_header_style($item)) ?>">
                            </div>
                        <?php endif; ?>
                        <span><?= e($item['title']) ?><?= $yearLabel !== '' ? ' (' . e($yearLabel) . ')' : '' ?></span>
                        <p><?= e($item['description']) ?></p>
                    </a>
                <?php endforeach; ?>

                <?php if ($earlier): ?>
                    <article class="series-card series-card--earlier">
                        <span>Earlier Works</span>
                        <?php foreach ($earlier as $slug => $item): ?>
                            <?php $yearLabel = series_year_label($item); ?>
                            <a class="earlier-work" href="<?= e(url_for('series2/' . $slug)) ?>">
                                <?php if ($item['header_file']): ?>
                                    <div class="earlier-work__image-container">
                                        <img src="<?= e(app_series_media_url($item, (string)$item['header_file'])) ?>" alt="<?= e($item['title'] . ' series thumbnail') ?>" style="<?= e(series_header_style($item)) ?>">
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
        <p class="eyebrow">Series · preview<?= $yearLabel !== '' ? ' · ' . e($yearLabel) : '' ?></p>
        <h1><?= e($item['title']) ?></h1>
        <?php if (trim((string)($item['subtitle'] ?? '')) !== ''): ?><p><?= e($item['subtitle']) ?></p><?php endif; ?>
    </section>
    <?php if ($item['header_file']): ?>
        <section class="section series-preview-hero">
            <img src="<?= e(app_series_media_url($item, (string)$item['header_file'])) ?>" alt="<?= e($item['title'] . ' representative image') ?>" style="<?= e(series_header_style($item)) ?>">
        </section>
    <?php endif; ?>
    <section class="section section--split">
        <div>
            <h2>About this series</h2>
            <p><?= nl2br(e((string)($item['description'] ?? ''))) ?></p>
        </div>
        <?php if (trim((string)($item['long_description'] ?? '')) !== ''): ?>
            <div><?= nl2br(e((string)$item['long_description'])) ?></div>
        <?php endif; ?>
    </section>
    <?php if (trim((string)($item['tags'] ?? '')) !== ''): ?>
        <section class="section">
            <div class="section-head"><h2>Tags</h2></div>
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
                <p class="eyebrow">Series works</p>
                <h2>Works in this series</h2>
            </div>
        </div>
        <?php if ($dependentArtworks): ?>
            <div class="art-grid">
                <?php foreach ($dependentArtworks as $slug => $artwork): ?>
                    <?php $cardImageFile = trim((string)($artwork['header_file'] ?? '')) ?: (string)$artwork['source_image_file']; ?>
                    <article class="art-card">
                        <a class="art-card__image" href="<?= e(url_for('artworks/' . $slug)) ?>">
                            <img src="<?= e(app_publication_media_url($artwork, $cardImageFile)) ?>" alt="<?= e($artwork['artwork_alt'] ?: $artwork['title'] . ' artwork') ?>">
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
            <p>No works have been published in this series yet.</p>
        <?php endif; ?>
    </section>
    <p><a href="<?= e(url_for('series2')) ?>">Back to Series preview</a></p>
    <?php
}

function render_published_artist_page(array $profile): void
{
    $photoFile = trim((string)($profile['photo_file'] ?? ''));
    $portraitImage = $photoFile !== '' ? app_artist_photo_url($photoFile) : '';
    $portraitCanRender = $portraitImage !== '';

    $tagline = trim((string)($profile['conceptual_keywords'] ?? ''));
    if ($tagline !== '') {
        $tagline = implode(' · ', array_map('trim', preg_split('/[,;]+/', $tagline) ?: []));
    } else {
        $tagline = 'Abstract Painting';
    }

    $processItems = [];
    if (trim((string)($profile['visual_language'] ?? '')) !== '') {
        $processItems[] = ['title' => 'Core Visual Language', 'description' => $profile['visual_language']];
    }
    if (trim((string)($profile['materials'] ?? '')) !== '') {
        $processItems[] = ['title' => 'Materials & Process', 'description' => $profile['materials']];
    }
    if (trim((string)($profile['recurring_themes'] ?? '')) !== '') {
        $processItems[] = ['title' => 'Recurring Themes', 'description' => $profile['recurring_themes']];
    }
    if (trim((string)($profile['palette_notes'] ?? '')) !== '') {
        $processItems[] = ['title' => 'Atmosphere & Palette', 'description' => $profile['palette_notes']];
    }
    ?>
    <section class="page-hero artist-page-hero">
        <p class="eyebrow"><?= e($tagline) ?></p>
        <h1><?= e($profile['artist_name'] ?: 'Maurizio Valch') ?></h1>
        <p><?= nl2br(e($profile['short_bio'])) ?></p>
    </section>
    <section class="section artist-profile-block<?= $portraitCanRender ? '' : ' artist-profile-block--text-only' ?>">
        <?php if ($portraitCanRender): ?>
            <figure class="artist-profile-block__portrait">
                <img src="<?= e($portraitImage) ?>" alt="<?= e($profile['artist_name'] ?: 'Maurizio Valch') ?> portrait">
            </figure>
        <?php endif; ?>
        <div class="prose">
            <p><?= nl2br(e($profile['short_bio'])) ?></p>
        </div>
    </section>
    <?php if (trim((string)($profile['statement'] ?? '')) !== ''): ?>
        <section class="section artist-statement-excerpt">
            <div>
                <p class="eyebrow">Artist statement</p>
                <h2>Statement Excerpt</h2>
            </div>
            <div class="prose">
                <p><?= nl2br(e($profile['statement'])) ?></p>
                <a class="button button--quiet" href="<?= e(url_for('artist-statement')) ?>">Full Statement</a>
            </div>
        </section>
    <?php endif; ?>
    
    <?php if ($processItems): ?>
        <section class="section">
            <div class="section-head section-head--simple">
                <h2>Artistic Process & Language</h2>
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

    <section class="section artist-link-panel">
        <a class="button" href="<?= e(url_for('artworks/')) ?>">View Artworks</a>
        <a class="button button--quiet" href="<?= e(url_for('contact')) ?>">Inquire / Contact</a>
    </section>
    <?php
}

function render_published_journal(array $notes): void
{
    ?>
    <section class="page-hero">
        <p class="eyebrow">Studio Notes</p>
        <h1>Concepts in Architectural Abstract Painting</h1>
        <p>Reflections, essays, and notes on abstract painting, space, and territory.</p>
    </section>
    <section class="section article-list">
        <?php foreach ($notes as $slug => $post): ?>
            <?php 
            $thumbUrl = '';
            if (!empty($post['media_files'][0] ?? $post['mockup_files'][0] ?? '')) {
                $thumbUrl = app_studio_note_media_url($post, (string)($post['media_files'][0] ?? $post['mockup_files'][0]));
            } else {
                $thumbUrl = first_html_image_src((string)$post['objective']);
            }
            
            $snippet = trim(strip_tags((string)$post['objective']));
            if (mb_strlen($snippet) > 200) {
                $snippet = mb_substr($snippet, 0, 197) . '...';
            }
            if ($snippet === '') {
                $snippet = 'Reflections on the studio process.';
            }
            ?>
            <article>
                <?php if ($thumbUrl !== ''): ?>
                    <a class="article-thumb" href="<?= e(url_for('studio-notes/' . $slug)) ?>">
                        <img src="<?= e($thumbUrl) ?>" alt="<?= e($post['title'] . ' thumbnail') ?>">
                    </a>
                <?php endif; ?>
                <p class="eyebrow">Essay</p>
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
    
    $coverUrl = '';
    if (!empty($post['media_files'][0] ?? $post['mockup_files'][0] ?? '')) {
        $coverUrl = app_studio_note_media_url($post, (string)($post['media_files'][0] ?? $post['mockup_files'][0]));
    }
    ?>
    <section class="page-hero artist-page-hero journal-post-hero__intro">
        <p class="eyebrow">Studio Notes</p>
        <h1><?= e($post['title']) ?></h1>
        <p><?= e($post['source_label'] ?: 'Studio Essay') ?></p>
    </section>
    
    <?php if ($coverUrl !== ''): ?>
        <section class="section artist-profile-block journal-post-feature">
            <figure class="artist-profile-block__portrait journal-post-feature__portrait">
                <img src="<?= e($coverUrl) ?>" alt="<?= e($post['title']) ?>" loading="eager">
            </figure>
            <div class="prose">
                <?= safe_rich_text((string)$post['objective']) ?>
            </div>
        </section>
    <?php else: ?>
        <section class="section prose prose--wide" style="margin-top: 40px; margin-bottom: 80px;">
            <?= safe_rich_text((string)$post['objective']) ?>
        </section>
    <?php endif; ?>
    
    <?php if (count($post['mockup_files']) > 1): ?>
        <section class="section">
            <div class="section-head section-head--simple">
                <h2>Context & Studies</h2>
            </div>
            <div class="artist-studio-grid">
                <?php foreach (array_slice($post['mockup_files'], 1) as $mockupFile): ?>
                    <?php 
                    $appsBase = rtrim(str_replace('\\', '/', dirname(base_path())), '/');
                    $imgUrl = $appsBase . '/platform/media.php?file=' . rawurlencode(basename($mockupFile));
                    ?>
                    <figure>
                        <img src="<?= e($imgUrl) ?>" alt="Context mockup">
                    </figure>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section artist-link-panel">
        <a class="button button--quiet" href="<?= e(url_for('studio-notes')) ?>">Back to Studio Notes</a>
    </section>
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
    $profile = artist_profile_defaults($site['artist_profile'] ?? []);
    $statement = $profile['statement_page'] ?? [];
    ?>
    <section class="page-hero artist-page-hero artist-statement-hero">
        <p class="eyebrow">Artist statement</p>
        <h1><?= e($statement['title'] ?? 'Genesis of Metaphysical Territories') ?></h1>
        <p><?= e($statement['intro'] ?? 'Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth.') ?></p>
    </section>
    <section class="section artist-statement-body">
        <div>
            <p class="eyebrow">Artist statement</p>
            <h2><?= e($statement['title'] ?? 'Genesis of Metaphysical Territories') ?></h2>
        </div>
        <div class="prose">
            <?php foreach (($statement['body'] ?? []) as $paragraph): ?>
                <p><?= e($paragraph) ?></p>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function render_process(): void
{
    ?>
    <section class="page-hero">
        <p class="eyebrow">Studio process</p>
        <h1>Structural Painting Process</h1>
        <p>Painting begins with spatial order: field, mass, horizon, vertical tension, and only then chromatic vibration.</p>
    </section>
    <section class="section process-grid">
        <div><span>01</span><h2>Field</h2><p>The dominant field establishes silence and scale.</p></div>
        <div><span>02</span><h2>Structure</h2><p>Masses, segments, and horizons organize the pictorial territory.</p></div>
        <div><span>03</span><h2>Ascent</h2><p>Stairways and thresholds introduce the measure of consciousness.</p></div>
        <div><span>04</span><h2>Presence</h2><p>The final painting holds equilibrium between void, material, and perception.</p></div>
    </section>
    <?php
}

function render_exhibitions(array $site): void
{
    $profile = artist_profile_defaults($site['artist_profile'] ?? []);
    $items = $profile['exhibitions'] ?? [];
    ?>
    <section class="page-hero">
        <p class="eyebrow">Trust signals</p>
        <h1>Exhibitions and Collections</h1>
        <p>Selected public references, marketplace history, and collection context for collectors and curators researching Maurizio Valch.</p>
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
    $subject = isset($artworks[$requested]) ? 'Inquiry about ' . $artworks[$requested]['title'] : 'Painting inquiry';

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
            $error = 'All fields (Name, Email, Message) are required.';
        } else if (!filter_var($submittedEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            try {
                $contactPdo = artist_site_database_connection(dirname(__DIR__) . '/platform');
                if (!artist_site_rate_limit($contactPdo, 'artist_contact', $submittedEmail, 5, 3600)) {
                    $error = 'Too many messages have been submitted. Please try again later.';
                }
            } catch (Throwable $rateLimitError) {
                error_log('Artist contact rate limiter unavailable: ' . $rateLimitError->getMessage());
                $error = 'The contact form is temporarily unavailable. Please email the studio directly.';
            }
        }

        if ($error === '' && $honeypot === '') {
            $profile = app_artist_profile()?->get();
            $artistName = $profile['artist_name'] ?? 'Artist';
            $to = filter_var((string)($site['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$site['email'] : resolved_artist_email();
            $from = $to; // e.g. studio@artistdomain.com
            
            $submittedSubject = str_replace(["\r", "\n"], ' ', mb_substr($submittedSubject, 0, 120));
            $emailSubject = "[" . $artistName . " Website] " . $submittedSubject;
            
            $body = "New message from " . $artistName . " Website:\n\n";
            $body .= "Name: " . $submittedName . "\n";
            $body .= "Email: " . $submittedEmail . "\n";
            $body .= "Subject: " . $submittedSubject . "\n\n";
            $body .= "Message:\n" . $submittedMessage . "\n";
            
            $headers = [
                "From: " . $from,
                "Reply-To: " . $submittedEmail,
                "X-Mailer: PHP/" . phpversion(),
                "Content-Type: text/plain; charset=UTF-8"
            ];
            
            if (mail($to, $emailSubject, $body, implode("\r\n", $headers))) {
                $success = true;
                $submittedName = '';
                $submittedEmail = '';
                $submittedSubject = 'Painting inquiry';
                $submittedMessage = '';
            } else {
                $error = 'There was an error sending your message. Please try again or email directly at ' . $to;
            }
        }
    }
    ?>
    <section class="page-hero">
        <p class="eyebrow">Inquiries</p>
        <h1>Contact the Studio</h1>
        <p><?= e(trim((string)($site['inquiry_intro'] ?? '')) ?: 'For catalog documentation, curatorial questions, commissions, trade inquiries, or studio availability.') ?></p>
    </section>
    <section class="section contact-panel">
        <div style="min-height: auto; padding: 24px;">
            <h2 style="font-size: clamp(20px, 1.8vw, 26px); margin-bottom: 20px; font-family: var(--serif-display); font-weight: normal; color: var(--ink); margin-top: 0;">Send Message</h2>
            
            <?php if ($success): ?>
                <div style="background: #f1fcf4; border: 1px solid #a3e2b4; color: #1b5e20; padding: 10px; margin-bottom: 15px; font-size: 14px; min-height: auto;">
                    Message sent. Thank you.
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
                    <label for="website">Leave blank</label>
                    <input type="text" name="website" id="website" autocomplete="off">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-name" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Name</label>
                    <input type="text" name="name" id="contact-name" value="<?= e($submittedName) ?>" required placeholder="Your name" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-email" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Email</label>
                    <input type="email" name="email" id="contact-email" value="<?= e($submittedEmail) ?>" required placeholder="your@email.com" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-subject" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Subject</label>
                    <input type="text" name="subject" id="contact-subject" value="<?= e($submittedSubject) ?>" style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 4px; min-height: auto; padding: 0; border: none; background: transparent;">
                    <label for="contact-message" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600;">Message</label>
                    <textarea name="message" id="contact-message" required placeholder="Your message..." style="padding: 8px 10px; border: 1px solid var(--line); background: #ffffff; font-family: inherit; font-size: 14px; color: var(--ink); border-radius: 0; width: 100%; resize: vertical; min-height: 80px;"><?= e($submittedMessage) ?></textarea>
                </div>

                <button type="submit" class="button" onmouseover="this.style.background='var(--red)'; this.style.borderColor='var(--red)';" onmouseout="this.style.background='var(--ink)'; this.style.borderColor='var(--ink)';" style="cursor: pointer; justify-content: center; min-height: 36px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.05em; width: 100%; border-radius: 0; transition: background 0.18s, border-color 0.18s;">Send Message</button>
            </form>
        </div>
        <div>
            <h2>Collector Notes</h2>
            <p>Works are original hand-painted acrylic paintings. Certificates of authenticity and professional shipping from Spain can be documented for each acquisition.</p>
        </div>
        <div>
            <h2>Profiles</h2>
            <div class="social-links" aria-label="Social and marketplace profiles">
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
        <p class="eyebrow">Studio</p>
        <h1>Privacy Policy</h1>
        <p>Information about how data is handled by the studio.</p>
    </section>
    <section class="section artist-statement-body">
        <div>
            <p class="eyebrow">Legal</p>
            <h2>Privacy & Data</h2>
        </div>
        <div class="prose">
            <p>This website is the digital representation and catalog of Maurizio Valch’s original paintings.</p>
            <h3>Personal Information</h3>
            <p>When you contact the studio using the inquiry form or via email, we collect the personal information you provide, such as your name, email address, and the content of your message. We use this information solely to respond to your inquiries regarding the artwork, acquisitions, and exhibitions.</p>
            <h3>Analytics & Cookies</h3>
            <p>This website uses Google Analytics to analyze traffic and understand visitor behavior. This helps us optimize user experience. Google Analytics uses cookies to gather standard internet log information and visitor behavior in an anonymous form.</p>
            <h3>Third Parties</h3>
            <p>We do not sell, trade, or otherwise transfer your personal information to outside parties. This does not include trusted third parties who assist us in operating our website or conducting our studio activities, so long as those parties agree to keep this information confidential.</p>
            <h3>Your Rights</h3>
            <p>You have the right to request access to the personal information we hold about you, to ask for it to be updated or deleted. Please contact the studio at <?= e($site['email']) ?> for any inquiries regarding your data.</p>
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
        render_home($site, $series, $artworks);
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
            $meta = page_meta('Published Artworks | ' . $artistName, 'Published original paintings by ' . $artistName . ' with contextual mockups and individual visual records.', $site['url'] . '/artworks/');
            // Unlisted publications stay reachable by direct link (below) but must not appear in the main catalog grid.
            $publicItems = array_filter($publishedItems, fn(array $item): bool => $item['visibility'] === 'public');
            
            // Apply search query filtering
            $q = trim((string)($_GET['q'] ?? ''));
            if ($q !== '') {
                $publicItems = array_filter($publicItems, function(array $item) use ($q): bool {
                    $term = mb_strtolower($q);
                    return mb_strpos(mb_strtolower((string)$item['title']), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['series'] ?? '')), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['short_description'] ?? '')), $term) !== false
                        || mb_strpos(mb_strtolower((string)($item['description'] ?? '')), $term) !== false;
                });
            }
            
            render_published_catalog($publicItems, 'Artworks', 'Selected works published by ' . $artistName . ', with their contextual studies and visual records.', 'Artwork Catalog');
            break;
        }

        $publishedArtwork = $publishedItems[$segments[1]] ?? null;
        if (!$publishedArtwork) { $handled = false; break; }
        if (($segments[2] ?? '') === 'mockups' && isset($segments[3])) {
            $record = $publishedCatalog?->mockup($segments[1], $segments[3]);
            if (!$record) { $handled = false; break; }
            $mockup = $record['mockup'];
            $mockupTitle = trim((string)($mockup['title'] ?: $publishedArtwork['title'] . ' — Context Study'));
            $mockupDescription = trim((string)($mockup['description'] ?: $mockup['caption'] ?: 'A contextual presentation of ' . $publishedArtwork['title'] . ' by ' . $artistName . '.'));
            $meta = page_meta($mockupTitle . ' | ' . $artistName, $mockupDescription, $site['url'] . '/artworks/' . $segments[1] . '/mockups/' . $segments[3] . '/', app_publication_media_url($publishedArtwork, $mockup['mockup_file']));
            $meta['keywords'] = trim((string)$mockup['keywords']);
            render_published_mockup($site, $publishedArtwork, $mockup);
            break;
        }
        if (isset($segments[2])) { $handled = false; break; }
        $description = trim((string)($publishedArtwork['short_description'] ?: $publishedArtwork['description']));
        $meta = page_meta($publishedArtwork['title'] . ' | ' . $artistName, $description, $site['url'] . '/artworks/' . $segments[1] . '/', app_publication_media_url($publishedArtwork, $publishedArtwork['source_image_file']));
        render_published_artwork($site, $publishedArtwork);
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

        $meta = page_meta('Constellations of Works | ' . $artistName, 'Map of placed works, preserving provenance context.', $site['url'] . '/sold-works/');
        render_published_catalog(
            $soldItems, 
            'Constellations of Works', 
            'A map of works by ' . $artistName . ' that have left the studio, preserving provenance, relationships, and traces of circulation.', 
            'Constellations',
            $mergedLocations,
            $soldRecords
        );
        break;
    case 'series':
        $publishedSeries = app_series_catalog()?->all() ?? [];
        if (isset($segments[1])) {
            $seriesItem = $publishedSeries[$segments[1]] ?? null;
            if (!$seriesItem) { $handled = false; break; }
            $meta = page_meta($seriesItem['title'] . ' | ' . $artistName, (string)$seriesItem['description'], $site['url'] . '/series/' . $segments[1] . '/');
            render_published_series_detail($seriesItem);
        } else {
            $meta = page_meta('Painting Series | ' . $artistName, 'Artwork series and concept clusters managed from Artwork Mockups.', $site['url'] . '/series/');
            render_published_series_index($publishedSeries);
        }
        break;
    case 'series2':
        header('Location: ' . url_for('series' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 302);
        exit;
    case 'artist':
        $profile = app_artist_profile()?->get();
        if (!$profile) { $handled = false; break; }
        $description = trim((string)($profile['short_bio'] ?: $profile['statement']));
        $photoFile = trim((string)($profile['photo_file'] ?? ''));
        $metaImage = $photoFile !== '' ? app_artist_photo_url($photoFile) : '';
        $meta = page_meta(
            ($profile['artist_name'] ?: $artistName) . ' | Artist Profile',
            $description,
            $site['url'] . '/artist/',
            $metaImage
        );
        render_published_artist_page($profile);
        break;
    case 'artist-2':
        header('Location: ' . url_for('artist/'), true, 302);
        exit;
    case 'artist-statement':
        $artistProfile = artist_profile_defaults($site['artist_profile'] ?? []);
        $statementPage = $artistProfile['statement_page'] ?? [];
        $meta = page_meta(
            'Artist Statement | ' . $artistName,
            trim(($statementPage['intro'] ?? 'Genesis of metaphysical territories: artist statement by ' . $artistName . '.') . ' ' . implode(' ', $statementPage['body'] ?? [])),
            $site['url'] . '/artist-statement/'
        );
        render_statement($site);
        break;
    case 'studio-process':
        $meta = page_meta('Studio Process | ' . $artistName, 'The structural painting process behind ' . $artistName . ' paintings.', $site['url'] . '/studio-process/');
        render_process();
        break;
    case 'exhibitions-collections':
        $meta = page_meta('Exhibitions and Collections | ' . $artistName, 'Exhibitions, collection context, and trust signals for ' . $artistName . '.', $site['url'] . '/exhibitions-collections/');
        render_exhibitions($site);
        break;
    case 'journal':
        header('Location: ' . url_for('studio-notes' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 301);
        exit;
    case 'blog':
        header('Location: ' . url_for('studio-notes' . (isset($segments[1]) ? '/' . $segments[1] : '/')), true, 302);
        exit;
    case 'studio-notes':
        $publishedNotes = app_studio_notes_catalog()?->all() ?? [];
        if (isset($segments[1])) {
            $slug = $segments[1];
            if (!isset($publishedNotes[$slug])) { $handled = false; break; }
            $post = $publishedNotes[$slug];
            $description = trim(strip_tags((string)$post['objective']));
            if (mb_strlen($description) > 160) $description = mb_substr($description, 0, 157) . '...';
            $meta = page_meta($post['title'] . ' | ' . $artistName, $description, $site['url'] . '/studio-notes/' . $slug . '/');
            $handled = render_published_journal_post($publishedNotes, $slug);
        } else {
            $meta = page_meta('Studio Notes | ' . $artistName, 'Studio Notes on architectural abstract painting, territory, silence, presence and structural metaphysical work by ' . $artistName . '.', $site['url'] . '/studio-notes/');
            render_published_journal($publishedNotes);
        }
        break;
    case 'contact':
        $meta = page_meta('Studio Contact | ' . $artistName, 'Contact ' . $artistName . ' studio for catalog documentation, curatorial questions, collector inquiries, and art consultant requests.', $site['url'] . '/contact/');
        render_contact($site, $artworks);
        break;
    case 'privacy-policy':
        $meta = page_meta('Privacy Policy | ' . $artistName, 'Privacy Policy for the studio website of ' . $artistName . '.', $site['url'] . '/privacy-policy/');
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
    $meta = page_meta('Page Not Found | ' . $artistName, 'The requested page could not be found.', $site['url'] . $path);
    $content = '<section class="page-hero"><p class="eyebrow">404</p><h1>Page not found</h1><p>The requested page does not exist.</p><a class="button" href="' . e(url_for('/')) . '">Return home</a></section>';
}

require __DIR__ . '/inc/header.php';
echo $content;
require __DIR__ . '/inc/footer.php';
