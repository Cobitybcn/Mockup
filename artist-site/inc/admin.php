<?php

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

function admin_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: '';
    return trim($value, '-');
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

function artist_profile_defaults(array $profile = []): array
{
    $defaults = [
        'name' => 'Maurizio Valch',
        'tagline' => 'Abstract painting / Territory and Thought',
        'intro' => 'Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth. His work explores horizons, strata, monolithic presences and incised lines as signs of formation, silence and emerging consciousness.',
        'portrait' => [
            'image' => '',
            'alt' => 'Maurizio Valch portrait',
            'caption' => '',
        ],
        'biography' => 'Maurizio Valch is a visual artist whose work moves between abstract painting, symbolic territory and structural silence. His practice develops through horizons, strata, monolithic presences and incised lines that behave as signs of formation and emerging consciousness.',
        'statement_excerpt' => 'Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.',
        'statement_button_text' => 'Read Artist Statement',
        'statement_button_url' => '/artist-statement',
        'statement_page' => [
            'title' => 'Genesis of Metaphysical Territories',
            'intro' => 'Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth.',
            'body' => [
                'His work explores horizons, strata, monolithic presences and incised lines as signs of an inner and territorial formation. Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.',
                'The surface becomes a place of discovery. Fault lines, ground frequency and tectonic tension suggest a territory in formation, not as a fixed geography, but as a perceptual space where thought begins to organize itself before language.',
                'Valch\'s painting relates to the act of marking as an originary gesture: closer to the first human need to leave signs upon the world than to academic construction. Each work proposes a quiet encounter between matter, distance, silence and emerging consciousness.',
            ],
        ],
        'studio_images' => [],
        'genealogy' => [
            ['title' => 'Inner Vortex', 'description' => 'The force before territory.', 'url' => '/series/inner-vortex-series/'],
            ['title' => 'Stratified Faces', 'description' => 'The face as divided territory.', 'url' => '/series/stratified-faces/'],
            ['title' => 'Structural Metaphysical Painting', 'description' => 'The territory becomes landscape, horizon and passage.', 'url' => '/series/structural-metaphysical-painting/'],
            ['title' => 'Strata', 'description' => 'The landscape is reduced to layers, fault lines and ground frequency.', 'url' => '/series/strata-series-maurizio-valch/'],
        ],
        'links' => [
            ['text' => 'Read Artist Statement', 'url' => '/artist-statement'],
            ['text' => 'View Exhibitions & Collections', 'url' => '/exhibitions-collections'],
            ['text' => 'Explore Painting Series', 'url' => '/series'],
        ],
        'exhibitions' => [
            ['title' => 'Reial Cercle Artistic de Barcelona', 'description' => 'Selected group exhibition context', 'url' => ''],
            ['title' => 'Gran Teatre del Liceu', 'description' => 'Work presented in Barcelona, 2017', 'url' => ''],
            ['title' => 'Private Collections', 'description' => 'Works acquired internationally through direct and marketplace channels', 'url' => ''],
            ['title' => 'Upcoming Solo Exhibition', 'description' => 'Valencia, Spain, 2026', 'url' => ''],
        ],
        'seo' => [
            'title' => 'Maurizio Valch | Artist',
            'description' => 'Maurizio Valch is a visual artist developing abstract painting centered on territory, thought, horizons, strata and emerging consciousness.',
            'keywords' => 'Maurizio Valch, abstract painting, territory and thought, contemporary artist',
            'og_image' => '',
        ],
    ];

    $profile = array_replace_recursive($defaults, $profile);
    $profile['studio_images'] = array_values($profile['studio_images'] ?? []);
    usort($profile['studio_images'], fn ($a, $b) => (int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0));
    $profile['genealogy'] = array_values($profile['genealogy'] ?? $defaults['genealogy']);
    $profile['links'] = array_values($profile['links'] ?? $defaults['links']);
    $profile['statement_page']['body'] = array_values($profile['statement_page']['body'] ?? $defaults['statement_page']['body']);
    return $profile;
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

function admin_save_content(array $content): void
{
    $dataPath = __DIR__ . "/../data";
    if (isset($content["settings"])) {
        file_put_contents($dataPath . "/settings.json", json_encode($content["settings"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (isset($content["sold_records"])) {
        file_put_contents($dataPath . "/sold-records.json", json_encode($content["sold_records"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (isset($content["sold_locations"])) {
        file_put_contents($dataPath . "/sold-locations.json", json_encode($content["sold_locations"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $folders = ["series" => $dataPath . "/series", "artworks" => $dataPath . "/artworks", "studioNotes" => $dataPath . "/studio-notes"];
    foreach ($folders as $key => $dir) {
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $existingFiles = glob($dir . "/*.json");
        if ($existingFiles) { foreach ($existingFiles as $file) unlink($file); }
        $items = $content[$key] ?? [];
        if ($key === "studioNotes" && !$items) {
            $items = $content["studio-notes"] ?? $content["journal"] ?? [];
        }
        if (is_array($items)) {
            foreach ($items as $slug => $data) {
                file_put_contents($dir . "/" . $slug . ".json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $extensionMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    if (!isset($allowed[$mime])) {
        $mime = $extensionMap[$extension] ?? '';
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

    if (!move_uploaded_file($file['tmp_name'], $target)) {
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

function country_coordinates(string $country): ?array
{
    $key = strtolower(trim($country));
    $key = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Ã¡', 'Ã©', 'Ã­', 'Ã³', 'Ãº', 'Ã±'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'],
        $key
    );
    $coordinates = [
        'argentina' => [-38.4161, -63.6167],
        'alemania' => [51.1657, 10.4515],
        'australia' => [-25.2744, 133.7751],
        'austria' => [47.5162, 14.5501],
        'belgium' => [50.5039, 4.4699],
        'belgica' => [50.5039, 4.4699],
        'bélgica' => [50.5039, 4.4699],
        'brasil' => [-14.2350, -51.9253],
        'brazil' => [-14.2350, -51.9253],
        'canada' => [56.1304, -106.3468],
        'chile' => [-35.6751, -71.5430],
        'colombia' => [4.5709, -74.2973],
        'bulgaria' => [42.7339, 25.4858],
        'denmark' => [56.2639, 9.5018],
        'france' => [46.2276, 2.2137],
        'francia' => [46.2276, 2.2137],
        'germany' => [51.1657, 10.4515],
        'greece' => [39.0742, 21.8243],
        'grecia' => [39.0742, 21.8243],
        'greek' => [39.0742, 21.8243],
        'italy' => [41.8719, 12.5674],
        'italia' => [41.8719, 12.5674],
        'mexico' => [23.6345, -102.5528],
        'netherlands' => [52.1326, 5.2913],
        'paises bajos' => [52.1326, 5.2913],
        'norway' => [60.4720, 8.4689],
        'portugal' => [39.3999, -8.2245],
        'spain' => [40.4637, -3.7492],
        'espana' => [40.4637, -3.7492],
        'sweden' => [60.1282, 18.6435],
        'switzerland' => [46.8182, 8.2275],
        'uk' => [55.3781, -3.4360],
        'united kingdom' => [55.3781, -3.4360],
        'reino unido' => [55.3781, -3.4360],
        'usa' => [37.0902, -95.7129],
        'united states' => [37.0902, -95.7129],
        'estados unidos' => [37.0902, -95.7129],
        'uruguay' => [-32.5228, -55.7658],
    ];

    return isset($coordinates[$key]) ? ['lat' => $coordinates[$key][0], 'lng' => $coordinates[$key][1]] : null;
}

function map_location_coordinates(array $location): ?array
{
    $countryCoordinates = !empty($location['country']) ? country_coordinates((string) $location['country']) : null;

    if (isset($location['lat'], $location['lng']) && is_numeric($location['lat']) && is_numeric($location['lng'])) {
        $coordinates = ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
        if ($countryCoordinates) {
            $latDelta = abs($coordinates['lat'] - $countryCoordinates['lat']);
            $lngDelta = abs($coordinates['lng'] - $countryCoordinates['lng']);
            if ($latDelta > 12 || $lngDelta > 18) {
                return $countryCoordinates;
            }
        }
        return $coordinates;
    }

    if ($countryCoordinates) {
        return $countryCoordinates;
    }

    return null;
}

function map_project_coordinates(float $lat, float $lng): array
{
    $x = (($lng + 180) / 360) * 100;
    $y = ((90 - $lat) / 180) * 100;
    if ($lat >= 30 && $lat <= 72 && $lng >= -25 && $lng <= 45) {
        $x = ($x * .98) + 1.4;
        $y = ($y * 1.15) + 3.8;
    }

    return [
        'x' => max(0, min(100, $x)),
        'y' => max(0, min(100, $y)),
    ];
}

function constellation_visual_coordinates(array $location): array
{
    $country = strtolower(trim((string) ($location['country'] ?? '')));
    $country = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $country);
    $overrides = [
        'belgica' => [49.9, 34.4],
        'belgium' => [49.9, 34.4],
        'france' => [48.8, 37.5],
        'germany' => [52.1, 32.7],
        'mexico' => [24.2, 49.8],
        'netherlands' => [50.6, 32.8],
        'spain' => [47.4, 41.7],
        'espana' => [47.4, 41.7],
        'united states' => [25.6, 39.4],
        'usa' => [25.6, 39.4],
    ];

    if (isset($overrides[$country])) {
        return ['x' => $overrides[$country][0], 'y' => $overrides[$country][1]];
    }

    return map_project_coordinates((float) ($location['map_lat'] ?? 0), (float) ($location['map_lng'] ?? 0));
}

function admin_handle_post(array &$site, array &$series, array &$artworks, array &$soldRecords, array &$soldLocations, array &$journal): void
{
    if (($GLOBALS['segments'][0] ?? '') !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'setup_password') {
        $password = trim($_POST['password'] ?? '');
        if ($password !== '' && !admin_is_configured()) {
            file_put_contents(admin_password_file(), json_encode(['hash' => password_hash($password, PASSWORD_DEFAULT)]));
            $_SESSION['admin_ok'] = true;
        }
        header('Location: ' . url_for('admin'));
        exit;
    }

    if ($action === 'login') {
        $config = json_decode((string) file_get_contents(admin_password_file()), true);
        if (!empty($config['hash']) && password_verify($_POST['password'] ?? '', $config['hash'])) {
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
            $existing = $content['studioNotes'][$original] ?? $content['studioNotes'][$slug] ?? [];
            if ($original && $original !== $slug) {
                unset($content['studioNotes'][$original]);
            }
            $image = admin_upload_image($_FILES['journal_image_upload'] ?? []) ?: trim($_POST['image'] ?? ($existing['image'] ?? ''));
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

            $content['artworks'][$slug] = [
                'title' => admin_clean_field($_POST['title'] ?? ''),
                'year' => admin_clean_field($_POST['year'] ?? ''),
                'series' => $_POST['series'] ?? array_key_first($content['series']),
                'status' => ($_POST['status'] ?? 'available') === 'sold' ? 'sold' : 'available',
                'medium' => admin_clean_field($_POST['medium'] ?? ''),
                'dimensions_cm' => $dimensionsCm,
                'dimensions_in' => $dimensionsIn,
                'orientation' => admin_clean_field($_POST['orientation'] ?? ''),
                'image' => $image,
                'detail_image' => $detailImage,
                'detail_images' => array_map(fn ($path) => ['image' => $path, 'alt' => admin_clean_field($_POST['title'] ?? '') . ' detail'], $detailPaths),
                'mockups' => array_map(fn ($path) => ['image' => $path, 'alt' => admin_clean_field($_POST['title'] ?? '') . ' mockup'], $mockupPaths),
                'price' => admin_clean_field($_POST['price'] ?? ''),
                'sale_platform' => admin_clean_field($_POST['sale_platform'] ?? ($existing['sale_platform'] ?? '')),
                'sale_result' => admin_clean_field($_POST['sale_result'] ?? ($existing['sale_result'] ?? '')),
                'summary' => admin_clean_field($_POST['summary'] ?? ''),
                'concept' => admin_clean_field($_POST['concept'] ?? ''),
                'commercial_note' => admin_clean_field($_POST['commercial_note'] ?? ''),
                'sort_order' => (int) ($_POST['sort_order'] ?? ($existing['sort_order'] ?? ((count($content['artworks']) + 1) * 10))),
            ];

            $locations = admin_map_locations_by_artwork($content['sold_locations']);
            if ($content['artworks'][$slug]['status'] === 'sold') {
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

