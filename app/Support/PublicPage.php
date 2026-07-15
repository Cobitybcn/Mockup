<?php
declare(strict_types=1);

final class PublicPage
{
    public static function url(string $path = ''): string
    {
        $base = rtrim(app_env('APP_PUBLIC_URL', 'https://artworkmockups.com'), '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function path(string $path = ''): string
    {
        $configured = trim(app_env('APP_BASE_PATH', ''));
        if ($configured !== '') {
            $base = '/' . trim($configured, '/');
        } else {
            $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            if (preg_match('#^(.*/artworkmockups-faithful)(?:/|$)#', $script, $matches) === 1) {
                $base = rtrim($matches[1], '/');
            } else {
                $base = preg_match('#^/mockups(?:/|$)#', $script) === 1 ? '/mockups' : '';
            }
        }
        return $base . '/' . ltrim($path, '/');
    }

    public static function start(string $title, string $description, string $path, bool $noindex = false): void
    {
        $robots = $noindex ? '<meta name="robots" content="noindex, nofollow">' : '';
        $canonical = self::url($path);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title><meta name="description" content="' . self::h($description) . '">'
            . '<link rel="canonical" href="' . self::h($canonical) . '">' . $robots
            . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">'
            . '<link rel="stylesheet" href="' . self::h(self::path('style.css')) . '"><link rel="stylesheet" href="' . self::h(self::path('assets/public-pages.css')) . '"></head><body class="public-page">'
            . '<header class="public-header"><a class="brand public-brand" href="' . self::h(self::path()) . '" aria-label="Artwork Mockups home"><span class="brand-title">ARTWORK MOCKUPS <span class="brand-mark" aria-hidden="true"></span></span></a>'
            . '<nav class="public-nav" aria-label="Public navigation"><a href="' . self::h(self::path()) . '">Home</a><a href="' . self::h(self::path('integrations/pinterest/')) . '">Pinterest</a><a href="' . self::h(self::path('integrations/meta/')) . '">Facebook</a><a href="' . self::h(self::path('integrations/instagram/')) . '">Instagram</a><a href="' . self::h(self::path('contact/')) . '">Contact</a></nav></header><main class="public-main">';
    }

    public static function end(): void
    {
        echo '</main>';
        self::footer();
        echo '</body></html>';
    }

    public static function footer(): void
    {
        echo '<footer class="public-footer"><div class="public-footer-brand"><a class="brand" href="' . self::h(self::path()) . '" aria-label="Artwork Mockups home"><span class="brand-title">ARTWORK MOCKUPS <span class="brand-mark" aria-hidden="true"></span></span></a><span>Professional presentations for original artwork.</span></div>'
            . '<nav aria-label="Legal and integration links"><a href="' . self::h(self::path('privacy/')) . '">Privacy Policy</a><a href="' . self::h(self::path('terms/')) . '">Terms of Service</a>'
            . '<a href="' . self::h(self::path('data-deletion/')) . '">Data Deletion</a><a href="' . self::h(self::path('contact/')) . '">Contact</a><a href="' . self::h(self::path('integrations/pinterest/')) . '">Pinterest Integration</a><a href="' . self::h(self::path('integrations/meta/')) . '">Facebook Connection</a><a href="' . self::h(self::path('integrations/instagram/')) . '">Instagram Connection</a></nav>'
            . '<p>&copy; ' . date('Y') . ' ArtworkMockups.com. All rights reserved.</p></footer>';
    }

    public static function h(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
