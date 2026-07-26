<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/artist-site/inc/functions.php';

function studio_note_media_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
$html = '<h2>Material</h2><p>Before — territory</p><p><img src="data:image/png;base64,' . $png
    . '" alt="Studio detail" data-editor-size="small" data-editor-align="right" onerror="alert(1)"></p><script>alert(1)</script>';
$legacyImage = StudioNoteEmbeddedImage::decodeFirst($html);
studio_note_media_assert(StudioNoteEmbeddedImage::has($html), 'legacy embedded image is detected');
studio_note_media_assert(is_array($legacyImage) && ($legacyImage['mime'] ?? '') === 'image/png', 'legacy embedded image is decoded with its verified MIME type');
studio_note_media_assert(is_array($legacyImage) && getimagesizefromstring((string)($legacyImage['bytes'] ?? '')) !== false, 'legacy embedded image bytes remain a valid image');
$genericHtml = '<p><img src="data:application/octet-stream;base64,' . $png . '" alt="Dropped image"></p>';
$genericImage = StudioNoteEmbeddedImage::decodeFirst($genericHtml);
studio_note_media_assert(
    StudioNoteEmbeddedImage::has($genericHtml)
        && is_array($genericImage)
        && ($genericImage['mime'] ?? '') === 'image/png',
    'dragged images with a generic browser MIME are detected by their verified bytes'
);

$normalized = StudioNoteMediaService::normalize(24680, 987654, $html, [
    'channels' => ['website_blog'],
    'media' => [],
], []);

studio_note_media_assert(!str_contains($normalized['html'], 'data:image/'), 'embedded data image is removed from stored HTML');
studio_note_media_assert(count((array)$normalized['payload']['media']) === 1, 'persisted image is registered as note media');
$media = (array)$normalized['payload']['media'][0];
$file = basename((string)($media['file'] ?? ''));
$path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
studio_note_media_assert((string)($media['type'] ?? '') === 'studio_note', 'persisted image uses the Studio Note media type');
studio_note_media_assert(is_file($path), 'embedded image is persisted as a real file');
studio_note_media_assert(is_array($normalized['payload']['source'] ?? null), 'first embedded image becomes the source of a custom note');
studio_note_media_assert(
    str_contains(
        (string)$normalized['html'],
        'studio_note_media.php?note=987654&amp;file=' . rawurlencode($file) . '&amp;w=1200'
    ),
    'the editor and public website share the note-scoped media URL'
);
$normalizedGeneric = StudioNoteMediaService::normalize(24680, 987654, $genericHtml, [
    'channels' => ['website_blog'],
    'media' => [],
], []);
studio_note_media_assert(
    !str_contains((string)$normalizedGeneric['html'], 'application/octet-stream')
        && count((array)$normalizedGeneric['payload']['media']) === 1,
    'a dragged generic data URI is persisted instead of remaining inside the editorial document'
);

$protected = StudioNoteMediaService::protectImagesForAdaptation($normalized['html']);
studio_note_media_assert(
    !str_contains((string)$protected['html'], '<img')
        && str_contains((string)$protected['html'], 'STUDIO_NOTE_IMAGE_SLOT_0'),
    'adaptation receives an immutable image slot instead of an editable image URL'
);
$restored = StudioNoteMediaService::restoreImagesAfterAdaptation(
    '<h2>Material</h2><p>Translated territory.</p>',
    (array)$protected['images']
);
studio_note_media_assert(
    str_contains($restored, 'src="studio_note_media.php?note=987654&amp;file=' . rawurlencode($file)),
    'a model that omits the slot cannot delete the source image'
);
$rewrittenLegacy = StudioNoteMediaService::rewriteDeliveryUrls(
    24680,
    987654,
    '<p><img src="media.php?file=' . rawurlencode($file)
        . '&amp;thumb=1&amp;w=1200" data-editor-size="small" data-editor-align="right"></p>'
);
studio_note_media_assert(
    str_contains($rewrittenLegacy, 'studio_note_media.php?note=987654&amp;file=' . rawurlencode($file))
        && str_contains($rewrittenLegacy, 'data-editor-size="small"')
        && str_contains($rewrittenLegacy, 'data-editor-align="right"'),
    'historical editor URLs move to note-scoped delivery without changing layout'
);

$orphanPayload = $normalized['payload'];
$orphanPayload['media'] = [];
$orphanPayload['inline_media_files'] = [];
unset($orphanPayload['source']);
$recovered = StudioNoteMediaService::normalize(
    24680,
    987654,
    $normalized['html'],
    $orphanPayload,
    []
);
studio_note_media_assert(
    count((array)$recovered['payload']['media']) === 1
        && basename((string)$recovered['payload']['media'][0]['file']) === $file,
    'a persistent Studio Note file is re-associated from the Spanish body after a legacy adaptation orphaned it'
);

$public = safe_studio_note_rich_text(
    $normalized['html'],
    [$file],
    static fn(string $allowedFile): string => '/studio-note/' . rawurlencode($allowedFile),
    [],
    [$file => [
        'alt_text' => 'Pintura abstracta roja con divisiones horizontales.',
        'caption' => 'Detalle del territorio pictórico.',
    ]]
);
studio_note_media_assert(str_contains($public, '<img class="studio-note-inline-image'), 'authorized image is rendered in the public note');
studio_note_media_assert(str_contains($public, 'alt="Pintura abstracta roja con divisiones horizontales."'), 'published images use the analyzed Spanish alt text');
studio_note_media_assert(str_contains($public, '<figcaption>Detalle del territorio pictórico.</figcaption>'), 'published images use the analyzed Spanish editorial caption');
studio_note_media_assert(str_contains($public, 'Before — territory'), 'UTF-8 punctuation survives safe public rendering');
studio_note_media_assert(str_contains($public, 'studio-note-inline-image--small') && str_contains($public, 'studio-note-inline-image--right'), 'editor size and alignment survive publication');
studio_note_media_assert(!str_contains($public, 'onerror') && !str_contains($public, '<script'), 'unsafe image attributes and scripts are removed');

$excluded = safe_studio_note_rich_text(
    $normalized['html'],
    [$file],
    static fn(string $allowedFile): string => '/studio-note/' . rawurlencode($allowedFile),
    [$file]
);
studio_note_media_assert(!str_contains($excluded, '<img'), 'cover image can be excluded from the body to prevent duplication');

$semanticBase = [
    'title' => 'La piel del territorio',
    'body_html' => '<p>El territorio conserva memoria.</p><p><img src="media.php?file=mockup.jpg" data-editor-align="left"></p>',
    'excerpt' => 'Una nota sobre territorio.',
];
$semanticLayout = $semanticBase;
$semanticLayout['body_html'] = '<p>El territorio conserva memoria.</p><p><img src="media.php?file=mockup.jpg" data-editor-align="center" data-editor-size="large"></p>';
studio_note_media_assert(
    hash_equals(
        StudioNoteMediaService::semanticHash($semanticBase),
        StudioNoteMediaService::semanticHash($semanticLayout)
    ),
    'image alignment and size do not request a new editorial analysis'
);
$semanticText = $semanticBase;
$semanticText['body_html'] = '<p>El territorio conserva memoria y presión.</p>';
studio_note_media_assert(
    !hash_equals(
        StudioNoteMediaService::semanticHash($semanticBase),
        StudioNoteMediaService::semanticHash($semanticText)
    ),
    'real prose changes remain eligible for reanalysis'
);
$mirroredLayout = StudioNoteMediaService::mirrorImages(
    $semanticLayout['body_html'],
    '<p>Territory preserves memory.</p><p><img src="old.jpg" data-editor-align="left"></p>'
);
studio_note_media_assert(
    str_contains($mirroredLayout, 'data-editor-align="center"')
        && str_contains($mirroredLayout, 'data-editor-size="large"')
        && str_contains($mirroredLayout, 'Territory preserves memory.'),
    'visual image changes are mirrored into English without rewriting translated prose'
);

$catalogSources = [[
    'type' => 'mockup',
    'file' => 'mockup.jpg',
    'editorialGuide' => [
        'altText' => 'Obra roja de la serie STRATA en un espacio arquitectónico.',
        'caption' => 'Vista de conjunto de una obra de STRATA.',
    ],
    'editorialGuideEn' => [
        'altText' => 'Red work from the STRATA series in an architectural space.',
        'caption' => 'Overall view of a work from the STRATA series.',
    ],
]];
$hydratedSpanish = StudioNoteMediaService::hydrateImageMetadata($semanticBase, $catalogSources, 'es');
$hydratedEnglish = StudioNoteMediaService::hydrateImageMetadata([
    'body_html' => '<p>Territory preserves memory.</p><p><img src="studio_note_media.php?note=1&amp;file=mockup.jpg&amp;w=1200"></p>',
    'image_metadata' => [],
], $catalogSources, 'en');
studio_note_media_assert(
    (string)($hydratedSpanish['image_metadata'][0]['alt_text'] ?? '') === $catalogSources[0]['editorialGuide']['altText']
        && (string)($hydratedEnglish['image_metadata'][0]['caption'] ?? '') === $catalogSources[0]['editorialGuideEn']['caption'],
    'catalog mockups restore their bilingual image SEO without another visual analysis'
);

$removed = StudioNoteMediaService::normalize(24680, 987654, '<p>Image removed.</p>', $normalized['payload'], []);
studio_note_media_assert(count((array)$removed['payload']['media']) === 0, 'removed inline image does not remain in the note media payload');
studio_note_media_assert(!isset($removed['payload']['source']), 'removed inline image does not remain as the note cover');

if (is_file($path)) unlink($path);
echo "PASS: Studio Note images persist and render through authorized media\n";
