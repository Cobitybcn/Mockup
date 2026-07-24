<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = (string)file_get_contents($root . '/inc/header.php');
$styles = (string)file_get_contents($root . '/assets/css/styles.css');
$script = (string)file_get_contents($root . '/assets/js/catalog.js');
$site = (string)file_get_contents($root . '/index.php');
$functions = (string)file_get_contents($root . '/inc/functions.php');
$platform = dirname($root) . '/platform';

$checks = [
    [str_contains($header, 'data-mobile-nav-toggle') && str_contains($header, 'aria-controls="header-tools"'), 'mobile navigation has an accessible toggle'],
    [str_contains($header, 'class="language-switch"') && str_contains($header, "artist_site_language_url('es')") && str_contains($header, "artist_site_language_url('en')"), 'the public header exposes the Spanish and English language selector'],
    [str_contains($styles, '.has-js .site-header.is-menu-open .header-tools') && str_contains($styles, 'min-height: 44px;'), 'mobile navigation opens explicitly and preserves touch target size'],
    [str_contains($functions, "in_array(\$requested, ['es', 'en']") && str_contains($functions, "artist_site_language() === 'es'"), 'the public website resolves Spanish or international English explicitly'],
    [str_contains($script, "window.matchMedia('(min-width: 1181px)')") && !str_contains($script, 'setInterval('), 'navigation collapses before its tools overflow and the hero never auto-advances'],
    [str_contains($site, 'data-srcset=') && str_contains($site, 'app_publication_media_srcset'), 'home defers secondary hero images and publishes responsive candidates'],
    [str_contains($site, "preg_match('~^(?:data:|javascript:)~i'") && str_contains($site, 'loading="lazy"'), 'Studio Notes never emits embedded data images as listing thumbnails'],
    [str_contains((string)file_get_contents($platform . '/publication_media.php'), 'ResponsiveImage::prepare'), 'published artwork media supports responsive delivery'],
    [str_contains((string)file_get_contents($platform . '/series_media.php'), 'ResponsiveImage::prepare'), 'series media supports responsive delivery'],
    [str_contains((string)file_get_contents($platform . '/studio_note_media.php'), 'ResponsiveImage::prepare'), 'Studio Notes media supports responsive delivery'],
    [str_contains($site, 'app_studio_note_embedded_image_url') && is_file($platform . '/studio_note_embedded_image.php'), 'legacy embedded Studio Note images have a safe public compatibility path'],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

echo "PASS: artist website mobile navigation and responsive media contract\n";
