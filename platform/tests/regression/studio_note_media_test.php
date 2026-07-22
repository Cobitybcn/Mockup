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

$public = safe_studio_note_rich_text(
    $normalized['html'],
    [$file],
    static fn(string $allowedFile): string => '/studio-note/' . rawurlencode($allowedFile)
);
studio_note_media_assert(str_contains($public, '<img class="studio-note-inline-image'), 'authorized image is rendered in the public note');
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

$removed = StudioNoteMediaService::normalize(24680, 987654, '<p>Image removed.</p>', $normalized['payload'], []);
studio_note_media_assert(count((array)$removed['payload']['media']) === 0, 'removed inline image does not remain in the note media payload');
studio_note_media_assert(!isset($removed['payload']['source']), 'removed inline image does not remain as the note cover');

if (is_file($path)) unlink($path);
echo "PASS: Studio Note images persist and render through authorized media\n";
