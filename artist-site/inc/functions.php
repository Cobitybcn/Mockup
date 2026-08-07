<?php

require_once __DIR__ . '/SiteCopy.php';

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

function site_copy(string $key, array $replacements = []): string
{
    return SiteCopy::text(artist_site_language(), $key, $replacements);
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
 * @param array<string,array{alt_text?:string,caption?:string}> $imageMetadata
 */
function safe_studio_note_rich_text(
    string $html,
    array $allowedImageFiles,
    callable $imageUrl,
    array $excludedImageFiles = [],
    array $imageMetadata = []
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
    $metadata = [];
    foreach ($imageMetadata as $file => $item) {
        $file = basename((string)$file);
        if ($file === '' || !is_array($item)) continue;
        $metadata[$file] = [
            'alt_text' => trim((string)($item['alt_text'] ?? '')),
            'caption' => trim((string)($item['caption'] ?? '')),
        ];
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
    $render = static function (DOMNode $node) use (&$render, $renderChildren, $allowed, $excluded, $imageUrl, $metadata): string {
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
            $seo = (array)($metadata[$file] ?? []);
            $alt = trim((string)($seo['alt_text'] ?? '')) ?: trim($node->getAttribute('alt'));
            $caption = trim((string)($seo['caption'] ?? ''));
            $image = '<img class="' . e($class) . '" src="' . e($url) . '" alt="'
                . e($alt) . '" loading="lazy" decoding="async">';
            if ($caption === '') return $image;
            return '<figure class="studio-note-inline-figure studio-note-inline-figure--' . e($align) . '">'
                . $image . '<figcaption>' . e($caption) . '</figcaption></figure>';
        }

        $children = $renderChildren($node);
        $simpleTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'ul', 'ol', 'li', 'h2', 'h3'];
        if (in_array($tag, $simpleTags, true)) {
            if ($tag === 'br') return '<br>';
            if ($tag === 'p' && str_starts_with(ltrim($children), '<figure class="studio-note-inline-figure')) {
                return $children;
            }
            return '<' . $tag . '>' . $children . '</' . $tag . '>';
        }
        if ($tag === 'h1') return '<h2>' . $children . '</h2>';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('~^(?:https?://|mailto:|tel:|/|#)~i', $href) !== 1) return $children;
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

function published_default_seo_title(string $artworkTitle, string $seriesTitle, string $artistName, int $max = 60): string
{
    $artworkTitle = trim($artworkTitle);
    $artistName = trim($artistName);
    $seriesTitle = trim($seriesTitle);

    if ($seriesTitle !== '') {
        $candidate = $artworkTitle . ' | ' . $seriesTitle . ' Series | ' . $artistName;
        if (mb_strlen($candidate) <= $max) {
            return $candidate;
        }
    }

    $candidate = $artworkTitle . ' | Contemporary Abstract Painting | ' . $artistName;
    if (mb_strlen($candidate) <= $max) {
        return $candidate;
    }

    return $artworkTitle . ' | ' . $artistName;
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
            <img src="<?= e(asset_url($artwork['image'])) ?>" alt="<?= e($artwork['title'] . ' by Maurizio Valch') ?>" loading="lazy" decoding="async">
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

function render_published_paragraphs(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    // Normalizar saltos de línea y reparar falta de espacio entre punto y mayúscula al colapsar oraciones
    $normalizedText = preg_replace('/(?<=[a-z0-9\.\,\;\:\!\?])\.(?=[A-Z])/', '. ', $text);

    // Dividir por saltos de línea dobles o múltiples
    $paragraphs = preg_split('/\n\s*\n|\r\n\s*\r\n/', $normalizedText);
    $html = [];
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $formatted = nl2br(e($p));
        $html[] = '<p>' . $formatted . '</p>';
    }

    if (empty($html)) {
        return '<p>' . e($normalizedText) . '</p>';
    }

    return implode("\n", $html);
}

function canonical_artwork_title(string $title): string
{
    $title = trim($title);
    if ($title === '') return '';

    // Reemplazar guiones simples o en-dash entre espacios con em-dash canónico
    $normalized = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+/u', ' — ', $title);

    // Si tiene prefijo de serie (SERIE — TITULO), convertir el prefijo a MAYÚSCULAS
    if (str_contains($normalized, ' — ')) {
        $parts = explode(' — ', $normalized, 2);
        $prefix = mb_strtoupper(trim($parts[0]));
        $suffix = trim($parts[1]);
        return $prefix . ' — ' . $suffix;
    }

    return $normalized;
}

