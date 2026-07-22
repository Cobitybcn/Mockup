<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = (string)file_get_contents($root . '/inc/header.php');
$styles = (string)file_get_contents($root . '/assets/css/styles.css');
$script = (string)file_get_contents($root . '/assets/js/catalog.js');
$site = (string)file_get_contents($root . '/index.php');
$platform = dirname($root) . '/platform';

$checks = [
    [str_contains($header, 'data-mobile-nav-toggle') && str_contains($header, 'aria-controls="header-tools"'), 'mobile navigation has an accessible toggle'],
    [str_contains($styles, '.has-js .site-header.is-menu-open .header-tools') && str_contains($styles, 'min-height: 44px;'), 'mobile navigation opens explicitly and preserves touch target size'],
    [str_contains($script, "window.matchMedia('(min-width: 941px)')") && !str_contains($script, 'setInterval('), 'hero is static on mobile and never auto-advances'],
    [str_contains($site, 'data-srcset=') && str_contains($site, 'app_publication_media_srcset'), 'home defers secondary hero images and publishes responsive candidates'],
    [str_contains($site, "preg_match('~^(?:data:|javascript:)~i'") && str_contains($site, 'loading="lazy"'), 'Studio Notes never emits embedded data images as listing thumbnails'],
    [str_contains((string)file_get_contents($platform . '/publication_media.php'), 'ResponsiveImage::prepare'), 'published artwork media supports responsive delivery'],
    [str_contains((string)file_get_contents($platform . '/series_media.php'), 'ResponsiveImage::prepare'), 'series media supports responsive delivery'],
    [str_contains((string)file_get_contents($platform . '/studio_note_media.php'), 'ResponsiveImage::prepare'), 'Studio Notes media supports responsive delivery'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "PASS: artist website mobile navigation and responsive media contract\n";
