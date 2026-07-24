<?php

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function safe_rich_text(string $html): string
{
    $withBreaks = preg_replace(
        '~<(?:br\s*/?|/p|/div|/li|/h[1-6])\s*>~i',
        "\n",
        $html
    ) ?? $html;
    $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\r\n?|\n{3,}/", "\n\n", $text) ?? $text;
    return nl2br(e(trim($text)), false);
}

function artist_site_language(): string
{
    static $resolved = null;
    if (is_string($resolved)) return $resolved;

    $pathLanguage = artist_site_path_language();
    $requested = strtolower(trim((string)($_GET['lang'] ?? '')));
    $stored = strtolower(trim((string)($_COOKIE['artist_site_language'] ?? '')));
    $resolved = $pathLanguage !== ''
        ? $pathLanguage
        : (in_array($requested, ['es', 'en'], true)
            ? $requested
            : (in_array($stored, ['es', 'en'], true) ? $stored : 'en'));

    if (($pathLanguage === $resolved || $requested === $resolved) && !headers_sent()) {
        $cookiePath = rtrim(base_path(), '/') . '/';
        setcookie('artist_site_language', $resolved, [
            'expires' => time() + 31536000,
            'path' => $cookiePath === '//' ? '/' : $cookiePath,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['artist_site_language'] = $resolved;
    }

    return $resolved;
}

function site_t(string $english, string $spanish): string
{
    return artist_site_language() === 'es' ? $spanish : $english;
}

function artist_site_url_with_language(string $url, string $language): string
{
    $language = in_array($language, ['es', 'en'], true) ? $language : 'en';
    [$urlWithoutFragment, $fragment] = array_pad(explode('#', $url, 2), 2, null);
    [$base, $queryString] = array_pad(explode('?', $urlWithoutFragment, 2), 2, '');
    parse_str($queryString, $query);
    unset($query['lang']);

    $parts = parse_url($base);
    $path = is_array($parts) ? (string)($parts['path'] ?? '/') : $base;
    $prefix = '';
    if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
        $prefix = (string)$parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $prefix .= (string)$parts['user'];
            if (isset($parts['pass'])) $prefix .= ':' . (string)$parts['pass'];
            $prefix .= '@';
        }
        $prefix .= (string)$parts['host'];
        if (isset($parts['port'])) $prefix .= ':' . (int)$parts['port'];
    }

    $applicationBase = base_path();
    $routePath = $path;
    $pathBase = '';
    if ($applicationBase !== '' && ($path === $applicationBase || str_starts_with($path, $applicationBase . '/'))) {
        $pathBase = $applicationBase;
        $routePath = substr($path, strlen($applicationBase)) ?: '/';
    }
    $segments = array_values(array_filter(explode('/', trim($routePath, '/')), 'strlen'));
    if (in_array($segments[0] ?? '', ['en', 'es'], true)) array_shift($segments);
    $trailingSlash = $routePath === '/' || str_ends_with($routePath, '/');
    $localizedPath = '/' . $language;
    if ($segments !== []) $localizedPath .= '/' . implode('/', $segments);
    if ($trailingSlash) $localizedPath .= '/';
    $localized = $prefix . $pathBase . $localizedPath;
    $localizedQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    if ($localizedQuery !== '') $localized .= '?' . $localizedQuery;
    return $localized . ($fragment !== null ? '#' . $fragment : '');
}

function artist_site_language_url(string $language): string
{
    $languageUrls = $GLOBALS['artist_site_language_urls'] ?? [];
    if (is_array($languageUrls) && isset($languageUrls[$language])) {
        return artist_site_url_with_language((string)$languageUrls[$language], $language);
    }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    return artist_site_url_with_language($uri, $language);
}

/** @param array<string,string> $urls */
function artist_site_set_language_urls(array $urls): void
{
    $GLOBALS['artist_site_language_urls'] = $urls;
}

function studio_note_image_file(string $source): string
{
    $source = html_entity_decode(trim($source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $query = parse_url($source, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') return '';
    parse_str($query, $parameters);
    return basename((string)($parameters['file'] ?? ''));
}

/**
 * Renders the small rich-text vocabulary used by Studio Notes. Images are
 * accepted only when their file is registered in the note payload.
 *
 * @param array<int,string> $allowedImageFiles
 * @param callable(string):string $imageUrl
 * @param array<int,string> $excludedImageFiles
 */
function safe_studio_note_rich_text(
    string $html,
    array $allowedImageFiles,
    callable $imageUrl,
    array $excludedImageFiles = []
): string {
    if (!class_exists(DOMDocument::class)) return safe_rich_text($html);

    $allowed = [];
    foreach ($allowedImageFiles as $file) {
        $file = basename((string)$file);
        if ($file !== '') $allowed[$file] = true;
    }
    $excluded = [];
    foreach ($excludedImageFiles as $file) {
        $file = basename((string)$file);
        if ($file !== '') $excluded[$file] = true;
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><!doctype html><html><body><div data-studio-note-root="1">' . $html . '</div></body></html>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return safe_rich_text($html);

    $root = null;
    foreach ($document->getElementsByTagName('div') as $candidate) {
        if ($candidate->getAttribute('data-studio-note-root') === '1') {
            $root = $candidate;
            break;
        }
    }
    if (!$root) return safe_rich_text($html);

    $render = null;
    $renderChildren = static function (DOMNode $node) use (&$render): string {
        $result = '';
        foreach ($node->childNodes as $child) $result .= $render($child);
        return $result;
    };
    $render = static function (DOMNode $node) use (&$render, $renderChildren, $allowed, $excluded, $imageUrl): string {
        if ($node->nodeType === XML_TEXT_NODE) return e($node->nodeValue ?? '');
        if ($node->nodeType !== XML_ELEMENT_NODE || !$node instanceof DOMElement) return '';

        $tag = strtolower($node->tagName);
        if ($tag === 'img') {
            $file = studio_note_image_file($node->getAttribute('src'));
            if ($file === '' || !isset($allowed[$file]) || isset($excluded[$file])) return '';
            $url = $imageUrl($file);
            if ($url === '') return '';
            $size = strtolower($node->getAttribute('data-editor-size'));
            if (!in_array($size, ['small', 'medium', 'large'], true)) $size = 'medium';
            $align = strtolower($node->getAttribute('data-editor-align'));
            if (!in_array($align, ['left', 'center', 'right'], true)) $align = 'center';
            $class = 'studio-note-inline-image studio-note-inline-image--' . $size . ' studio-note-inline-image--' . $align;
            return '<img class="' . e($class) . '" src="' . e($url) . '" alt="'
                . e($node->getAttribute('alt')) . '" loading="lazy" decoding="async">';
        }

        $children = $renderChildren($node);
        $simpleTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'ul', 'ol', 'li', 'h2', 'h3'];
        if (in_array($tag, $simpleTags, true)) {
            if ($tag === 'br') return '<br>';
            return '<' . $tag . '>' . $children . '</' . $tag . '>';
        }
        if ($tag === 'h1') return '<h2>' . $children . '</h2>';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('~^(?:https?://|mailto:|/|#)~i', $href) !== 1) return $children;
            $external = preg_match('~^https?://~i', $href) === 1;
            return '<a href="' . e($href) . '"' . ($external ? ' rel="noopener noreferrer"' : '') . '>' . $children . '</a>';
        }
        return $children;
    };

    $output = trim($renderChildren($root));
    for ($pass = 0; $pass < 2; $pass++) {
        $output = preg_replace('~<(p|h2|h3|blockquote|li)>\s*(?:<br>\s*)*</\1>~i', '', $output) ?? $output;
    }
    return trim($output);
}

function url_for(string $path = ''): string
{
    if (preg_match('~^(?:https?:|mailto:|tel:|#)~', $path)) {
        return $path;
    }
    $base = base_path();
    $path = '/' . ltrim($path, '/');
    $path = $path === '//' ? '/' : $path;
    $url = rtrim($base, '/') . $path;
    $route = strtolower((string)(parse_url($path, PHP_URL_PATH) ?: '/'));
    $unlocalized = preg_match('~^/(?:assets|admin|admin-v2|api|data|inc|tests|tools|scripts|docs)(?:/|$)~', $route) === 1
        || in_array($route, ['/sitemap.xml', '/robots.txt', '/favicon.ico', '/favicon.svg'], true)
        || preg_match('~^/(?:draft-media|draft-preview|audit_migration_web|run_migration[^/]*)\.php$~', $route) === 1;
    return $unlocalized ? $url : artist_site_url_with_language($url, artist_site_language());
}

function base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($script), '/');
    return $dir === '/' || $dir === '.' ? '' : $dir;
}

function asset_url(string $path): string
{
    return url_for($path);
}

function asset_version_url(string $path): string
{
    $relativePath = ltrim(parse_url($path, PHP_URL_PATH) ?: $path, '/');
    $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : (string) time();
    $separator = str_contains($path, '?') ? '&' : '?';
    return asset_url($path . $separator . 'v=' . $version);
}

function artworks_ordered(array $artworks): array
{
    $ordered = [];
    $position = 0;

    foreach ($artworks as $slug => $artwork) {
        $artwork['__sort_index'] = $position++;
        if (!isset($artwork['sort_order']) || !is_numeric($artwork['sort_order'])) {
            $artwork['sort_order'] = $position * 10;
        }
        $ordered[$slug] = $artwork;
    }

    uasort($ordered, function (array $a, array $b): int {
        $aPinned = !empty($a['pinned']) ? 1 : 0;
        $bPinned = !empty($b['pinned']) ? 1 : 0;
        if ($aPinned !== $bPinned) {
            return $bPinned <=> $aPinned; // Pinned first
        }
        $byOrder = ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0));
        if ($byOrder !== 0) {
            return $byOrder;
        }
        return ((int) ($a['__sort_index'] ?? 0)) <=> ((int) ($b['__sort_index'] ?? 0));
    });

    foreach ($ordered as &$artwork) {
        unset($artwork['__sort_index']);
    }
    unset($artwork);

    return $ordered;
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($scriptDir && $scriptDir !== '/' && str_starts_with($uri, $scriptDir)) {
        $uri = substr($uri, strlen($scriptDir)) ?: '/';
    }
    $uri = '/' . ltrim($uri, '/');
    if (str_starts_with($uri, '/index.php')) {
        $uri = substr($uri, 10) ?: '/';
    }
    return '/' . trim($uri, '/');
}

function artist_site_path_language(?string $path = null): string
{
    $path ??= current_path();
    $first = strtolower((string)(explode('/', trim($path, '/'))[0] ?? ''));
    return in_array($first, ['en', 'es'], true) ? $first : '';
}

function artist_site_path_without_language(string $path): string
{
    $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (in_array(strtolower((string)($segments[0] ?? '')), ['en', 'es'], true)) array_shift($segments);
    return '/' . implode('/', $segments);
}

function format_artwork_price(mixed $price, string $currency = 'EUR'): string
{
    if ($price === null || $price === '') {
        return 'Price on request';
    }
    $priceStr = trim((string)$price);
    if (strtolower($priceStr) === 'inquire' || strtolower($priceStr) === 'price on request') {
        return 'Price on request';
    }
    $numericVal = preg_replace('/[^\d.]/', '', $priceStr);
    if (is_numeric($numericVal) && (float)$numericVal > 0) {
        $formatted = number_format((float)$numericVal, 0, '.', ',');
        if ($currency === 'EUR' || strtolower($currency) === 'eur') {
            return '€' . $formatted;
        } else if ($currency === 'USD' || strtolower($currency) === 'usd') {
            return '$' . $formatted;
        }
        return $currency . ' ' . $formatted;
    }
    return $priceStr;
}

function artworks_by_status(array $artworks, string $status): array
{
    return array_filter(artworks_ordered($artworks), function ($artwork) use ($status) {
        $artworkStatus = $artwork['status'] ?? '';
        if ($status === 'available') {
            return $artworkStatus === 'available' || $artworkStatus === 'for sale';
        }
        if ($status === 'sold') {
            return $artworkStatus === 'sold' || $artworkStatus === 'placed' || $artworkStatus === 'collected';
        }
        return $artworkStatus === $status;
    });
}

function artworks_by_series(array $artworks, string $seriesSlug): array
{
    return array_filter(artworks_ordered($artworks), fn ($artwork) => $artwork['series'] === $seriesSlug);
}

function page_meta(string $title, string $description, string $canonical, string $image = ''): array
{
    return [
        'title' => $title,
        'description' => $description,
        'canonical' => $canonical,
        'image' => $image,
    ];
}

function site_absolute_asset_url(string $url, string $siteUrl): string
{
    $url = trim($url);
    if ($url === '' || preg_match('~^https?://~i', $url)) return $url;

    $siteUrl = rtrim(trim($siteUrl), '/');
    if (str_starts_with($url, '//')) {
        $scheme = strtolower((string)(parse_url($siteUrl, PHP_URL_SCHEME) ?: 'https'));
        return $scheme . ':' . $url;
    }
    if (str_starts_with($url, '/')) {
        $scheme = (string)(parse_url($siteUrl, PHP_URL_SCHEME) ?: 'https');
        $host = (string)(parse_url($siteUrl, PHP_URL_HOST) ?: '');
        $port = parse_url($siteUrl, PHP_URL_PORT);
        if ($host !== '') return $scheme . '://' . $host . ($port ? ':' . $port : '') . $url;
    }
    return $siteUrl . '/' . ltrim($url, '/');
}

function render_artwork_card(string $slug, array $artwork, array $series): void
{
    $statusVal = $artwork['status'] ?? '';
    $status = ($statusVal === 'available' || $statusVal === 'for sale') ? 'In Studio' : (($statusVal === 'sold' || $statusVal === 'placed' || $statusVal === 'collected') ? 'Placed' : ucfirst($statusVal));
    $seriesTitle = $series[$artwork['series'] ?? '']['title'] ?? 'Unassigned';
    $searchText = implode(' ', [
        $artwork['title'],
        $status,
        $seriesTitle,
        $artwork['medium'],
        $artwork['dimensions_cm'],
        $artwork['summary'],
        $artwork['concept'],
    ]);
    ?>
    <article class="art-card" data-artwork-card data-status="<?= e($artwork['status']) ?>" data-series="<?= e($artwork['series']) ?>" data-search="<?= e(strtolower($searchText)) ?>">
        <a class="art-card__image" href="<?= e(url_for('paintings/' . $slug)) ?>" aria-label="<?= e($artwork['title']) ?>">
            <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e($artwork['title'] . ' by Maurizio Valch') ?>">
        </a>
        <div class="art-card__body">
            <div class="eyebrow"><?= e($status) ?> / <?= e($seriesTitle) ?></div>
            <h3><a href="<?= e(url_for('paintings/' . $slug)) ?>"><?= e($artwork['title']) ?></a></h3>
            <p><?= e($artwork['medium']) ?>, <?= e($artwork['dimensions_cm']) ?> / <?= e($artwork['dimensions_in']) ?></p>
            <?php if ($statusVal === 'available' || $statusVal === 'for sale'): ?>
                <div class="art-card__price" style="margin-top: 12px; font-weight: 600; font-size: 16px; color: var(--ink);">
                    <?= e(format_artwork_price($artwork['price'] ?? '', $artwork['currency'] ?? 'EUR')) ?>
                </div>
                <div class="art-card__actions" style="margin-top: 14px;">
                    <a class="button" href="<?= e(url_for('paintings/' . $slug)) ?>" style="width: 100%; justify-content: center;">Buy / Inquire</a>
                </div>
            <?php endif; ?>
        </div>
    </article>
    <?php
}

function json_ld(array $data): string
{
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}

function admin_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace("/[^a-z0-9]+/", "-", $value) ?: "";
    return trim($value, "-");
}

function artist_profile_defaults(array $profile = []): array
{
    $defaults = [
        "name" => "Maurizio Valch",
        "tagline" => "Abstract painting / Territory and Thought",
        "intro" => "Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth. His work explores horizons, strata, monolithic presences and incised lines as signs of formation, silence and emerging consciousness.",
        "portrait" => [
            "image" => "",
            "alt" => "Maurizio Valch portrait",
            "caption" => "",
        ],
        "biography" => "Maurizio Valch is a visual artist whose work moves between abstract painting, symbolic territory and structural silence. His practice develops through horizons, strata, monolithic presences and incised lines that behave as signs of formation and emerging consciousness.",
        "statement_excerpt" => "Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.",
        "statement_button_text" => "Read Artist Statement",
        "statement_button_url" => "/artist-statement",
        "statement_page" => [
            "title" => "Genesis of Metaphysical Territories",
            "intro" => "Maurizio Valch develops an abstract painting practice centered on territories where thought seems to emerge from the earth.",
            "body" => [
                "His work explores horizons, strata, monolithic presences and incised lines as signs of an inner and territorial formation. Rather than depicting landscapes, the paintings construct silent fields of appearance, where structure, matter and consciousness seem to arise together.",
                "The surface becomes a place of discovery. Fault lines, ground frequency and tectonic tension suggest a territory in formation, not as a fixed geography, but as a perceptual space where thought begins to organize itself before language.",
                "Valch\"s painting relates to the act of marking as an originary gesture: closer to the first human need to leave signs upon the world than to academic construction. Each work proposes a quiet encounter between matter, distance, silence and emerging consciousness.",
            ],
        ],
        "studio_images" => [],
        "genealogy" => [
            ["title" => "Inner Vortex", "description" => "The force before territory.", "url" => "/series/inner-vortex-series/", "year" => "2017"],
            ["title" => "Stratified Faces", "description" => "The face as divided territory.", "url" => "/series/stratified-faces/", "year" => "2019"],
            ["title" => "Structural Metaphysical Painting", "description" => "The territory becomes landscape, horizon and passage.", "url" => "/series/structural-metaphysical-painting/", "year" => "2026"],
            ["title" => "Strata", "description" => "The landscape is reduced to layers, fault lines and ground frequency.", "url" => "/series/strata-series-maurizio-valch/", "year" => "2026"],
        ],
        "links" => [
            ["text" => "Read Artist Statement", "url" => "/artist-statement"],
            ["text" => "View Exhibitions & Collections", "url" => "/exhibitions-collections"],
            ["text" => "Explore Painting Series", "url" => "/series"],
        ],
        "exhibitions" => [
            ["title" => "Reial Cercle Artistic de Barcelona", "description" => "Selected group exhibition context", "url" => ""],
            ["title" => "Gran Teatre del Liceu", "description" => "Work presented in Barcelona, 2017", "url" => ""],
            ["title" => "Private Collections", "description" => "Works acquired internationally through direct and marketplace channels", "url" => ""],
            ["title" => "Upcoming Solo Exhibition", "description" => "Valencia, Spain, 2026", "url" => ""],
        ],
        "seo" => [
            "title" => "Maurizio Valch | Artist",
            "description" => "Maurizio Valch is a visual artist developing abstract painting centered on territory, thought, horizons, strata and emerging consciousness.",
            "keywords" => "Maurizio Valch, abstract painting, territory and thought, contemporary artist",
            "og_image" => "",
        ],
    ];

    $profile = array_replace_recursive($defaults, $profile);
    $profile["studio_images"] = array_values($profile["studio_images"] ?? []);
    usort($profile["studio_images"], fn ($a, $b) => (int) ($a["sort"] ?? 0) <=> (int) ($b["sort"] ?? 0));
    $profile["genealogy"] = array_values($profile["genealogy"] ?? $defaults["genealogy"]);
    $profile["links"] = array_values($profile["links"] ?? $defaults["links"]);
    $profile["statement_page"]["body"] = array_values($profile["statement_page"]["body"] ?? $defaults["statement_page"]["body"]);
    return $profile;
}

function country_coordinates(string $country): ?array
{
    $key = strtolower(trim($country));
    $key = str_replace(
        ["á", "é", "í", "ó", "ú", "ñ", "Ã¡", "Ã©", "Ã­", "Ã³", "Ãº", "Ã±"],
        ["a", "e", "i", "o", "u", "n", "a", "e", "i", "o", "u", "n"],
        $key
    );
    $coordinates = [
        "argentina" => [-38.4161, -63.6167],
        "alemania" => [51.1657, 10.4515],
        "australia" => [-25.2744, 133.7751],
        "austria" => [47.5162, 14.5501],
        "belgium" => [50.5039, 4.4699],
        "belgica" => [50.5039, 4.4699],
        "bélgica" => [50.5039, 4.4699],
        "brasil" => [-14.2350, -51.9253],
        "brazil" => [-14.2350, -51.9253],
        "canada" => [56.1304, -106.3468],
        "chile" => [-35.6751, -71.5430],
        "colombia" => [4.5709, -74.2973],
        "bulgaria" => [42.7339, 25.4858],
        "denmark" => [56.2639, 9.5018],
        "france" => [46.2276, 2.2137],
        "francia" => [46.2276, 2.2137],
        "germany" => [51.1657, 10.4515],
        "greece" => [39.0742, 21.8243],
        "grecia" => [39.0742, 21.8243],
        "greek" => [39.0742, 21.8243],
        "italy" => [41.8719, 12.5674],
        "italia" => [41.8719, 12.5674],
        "mexico" => [23.6345, -102.5528],
        "netherlands" => [52.1326, 5.2913],
        "paises bajos" => [52.1326, 5.2913],
        "norway" => [60.4720, 8.4689],
        "portugal" => [39.3999, -8.2245],
        "spain" => [40.4637, -3.7492],
        "espana" => [40.4637, -3.7492],
        "sweden" => [60.1282, 18.6435],
        "switzerland" => [46.8182, 8.2275],
        "uk" => [55.3781, -3.4360],
        "united kingdom" => [55.3781, -3.4360],
        "reino unido" => [55.3781, -3.4360],
        "usa" => [37.0902, -95.7129],
        "united states" => [37.0902, -95.7129],
        "estados unidos" => [37.0902, -95.7129],
        "uruguay" => [-32.5228, -55.7658],
    ];

    return isset($coordinates[$key]) ? ["lat" => $coordinates[$key][0], "lng" => $coordinates[$key][1]] : null;
}

function map_location_coordinates(array $location): ?array
{
    $countryCoordinates = !empty($location["country"]) ? country_coordinates((string) $location["country"]) : null;

    if (isset($location["lat"], $location["lng"]) && is_numeric($location["lat"]) && is_numeric($location["lng"])) {
        $coordinates = ["lat" => (float) $location["lat"], "lng" => (float) $location["lng"]];
        if ($countryCoordinates) {
            $latDelta = abs($coordinates["lat"] - $countryCoordinates["lat"]);
            $lngDelta = abs($coordinates["lng"] - $countryCoordinates["lng"]);
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
        "x" => max(0, min(100, $x)),
        "y" => max(0, min(100, $y)),
    ];
}

function series_representative_image(string $slug, array $item, array $artworks): string
{
    $seriesImage = $item["image"] ?? "";
    foreach ($artworks as $artwork) {
        if ($seriesImage === "" && ($artwork["series"] ?? "") === $slug && !empty($artwork["image"])) {
            $seriesImage = $artwork["image"];
            break;
        }
    }
    return $seriesImage;
}

function seo_rename_image(string $filePath, string $targetBaseName, string $suffix = ''): string
{
    if (empty($filePath)) {
        return '';
    }
    $dir = dirname(__DIR__) . '/assets/uploads';
    $fileName = basename($filePath);
    $fullPath = $dir . '/' . $fileName;
    if (!is_file($fullPath)) {
        return $filePath;
    }
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $newFileName = $targetBaseName . ($suffix !== '' ? '_' . $suffix : '') . '.' . $extension;
    
    if ($fileName === $newFileName) {
        return $filePath;
    }
    
    $newFullPath = $dir . '/' . $newFileName;
    if (is_file($newFullPath)) {
        $newFileName = $targetBaseName . ($suffix !== '' ? '_' . $suffix : '') . '_' . bin2hex(random_bytes(2)) . '.' . $extension;
        $newFullPath = $dir . '/' . $newFileName;
    }
    
    if (rename($fullPath, $newFullPath)) {
        return '/assets/uploads/' . $newFileName;
    }
    return $filePath;
}
