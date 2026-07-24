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
    [str_contains($site, "site_t('Available for acquisition', 'Disponible para adquisición')") && str_contains($site, "site_t('Acquire this work', 'Adquirir esta obra')"), 'artwork acquisition controls follow the selected public language'],
    [str_contains($site, 'artwork-detail__supporting-image') && str_contains($site, '<figcaption><?= e($viewCaption) ?></figcaption>'), 'every published artwork view carries a visible caption'],
    [str_contains($site, "<?php if (\$artwork['artwork_views'] && !\$artwork['items']): ?>"), 'additional root views remain hidden whenever contextual mockups are available'],
    [str_contains($site, "basename((string)(\$mockup['mockup_file'] ?? '')) !== basename(\$mainImageFile)") && str_contains($site, 'foreach ($galleryMockups as $mockupIndex => $mockup)'), 'a mockup used as the artwork cover is not repeated in the gallery'],
    [str_contains($site, "site_t('Context study ', 'Estudio de contexto ')") && str_contains($site, '$mockupCaption'), 'mockups without a reviewed caption receive a distinct contextual label instead of repeating the artwork title'],
    [!str_contains($site, "\$profile['conceptual_keywords']") && str_contains($site, "site_t('Artist profile', 'Perfil del artista')"), 'internal artist keywords never render as a visible public tagline'],
    [substr_count($site, "nl2br(e(\$profile['short_bio']))") === 1, 'the public artist page renders the biography only once'],
    [!str_contains((string)file_get_contents($root . '/inc/footer.php'), "\$profile['short_bio']") && str_contains((string)file_get_contents($root . '/inc/footer.php'), 'Pintura abstracta / territorio y pensamiento'), 'the footer uses the concise site identity instead of truncating the artist biography'],
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
