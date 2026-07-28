<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';
$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
$userId = (int)$user['id'];
$sourceType = '';
$sourceId = 0;
if ((int)($_GET['generation_id'] ?? 0) > 0) { $sourceType = 'generation'; $sourceId = (int)$_GET['generation_id']; }
elseif ((int)($_GET['export_id'] ?? 0) > 0) { $sourceType = 'export'; $sourceId = (int)$_GET['export_id']; }
elseif ((int)($_GET['reference_asset_id'] ?? 0) > 0) { $sourceType = 'reference_asset'; $sourceId = (int)$_GET['reference_asset_id']; }
$service = new VideoEditorService(Database::connection(), new VideoJobRepository(Database::connection()), new VideoTaskDispatcher());
$source = $sourceType !== '' ? $service->source($userId, $sourceType, $sourceId) : null;
$editable = is_array($source) && (float)$source['durationSeconds'] > 0 && (float)$source['durationSeconds'] <= VideoReferencePolicy::MAX_VIDEO_SECONDS + .05;
$csrf = VideoHttp::csrfToken();

function editor_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function editor_duration(float $seconds): string { return rtrim(rtrim(number_format($seconds, 1, ',', ''), '0'), ',') . ' s'; }
?>
<!doctype html>
<html lang="<?= editor_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= editor_h(t('Video Editor - Artwork Mockups', 'Video Editor - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="video_editor.css?v=1">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= editor_h($user['email']) ?></a></header>
        <div class="alert-strip"><?= editor_h(t('Each edit creates a new version. The original video is preserved.', 'Cada edición crea una nueva versión. El video original se conserva.')) ?></div>
        <div class="ved-page">
            <header class="catalog-heading ved-heading">
                <div><span><?= editor_h(t('Standalone tool', 'Herramienta independiente')) ?></span><h1><?= editor_h(t('Video Editor', 'Video Editor')) ?></h1><p><?= editor_h(t('Refine a clip with Gemini Omni.', 'Refiná un clip con Gemini Omni.')) ?></p></div>
                <a class="button-link secondary" href="videos.php"><?= editor_h(t('Back to Videos', 'Volver a Videos')) ?></a>
            </header>

            <?php if (!$source): ?>
                <section class="catalog-panel ved-empty">
                    <span aria-hidden="true">▶</span><h2><?= editor_h(t('Select a video', 'Seleccioná un video')) ?></h2>
                    <p><?= editor_h(t('In Videos, choose "Edit" on a generated clip or final video.', 'En Videos, elegí "Editar" en un clip generado o un video final.')) ?></p>
                    <a class="button-link" href="videos.php"><?= editor_h(t('Choose video', 'Elegir video')) ?></a>
                </section>
            <?php else: ?>
                <section class="catalog-panel ved-workspace">
                    <aside class="ved-source">
                        <div class="ved-section-label"><span><?= editor_h(t('Source', 'Origen')) ?></span><strong><?= editor_h($source['title']) ?></strong></div>
                        <video src="<?= editor_h($source['previewUrl']) ?>" controls playsinline preload="metadata"></video>
                        <div class="ved-source-meta"><strong><?= editor_h($source['projectTitle']) ?></strong><span><?= editor_h(editor_duration((float)$source['durationSeconds'])) ?> · <?= editor_h($source['aspectRatio']) ?></span></div>
                    </aside>
                    <form class="ved-form" data-video-editor-form enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= editor_h($csrf) ?>">
                        <input type="hidden" name="sourceType" value="<?= editor_h($sourceType) ?>">
                        <input type="hidden" name="sourceId" value="<?= $sourceId ?>">
                        <label class="ved-prompt"><span><?= editor_h(t('What would you like to change?', '¿Qué te gustaría cambiar?')) ?></span><textarea name="prompt" required placeholder="<?= editor_h(t('Describe only the change. Omni will preserve everything else.', 'Describí solo el cambio. Omni va a conservar todo lo demás.')) ?>"></textarea></label>
                        <details class="ved-references">
                            <summary><span><?= editor_h(t('Reference images', 'Imágenes de referencia')) ?> <small><?= editor_h(t('Optional · up to 10', 'Opcional · hasta 10')) ?></small></span><b>+</b></summary>
                            <div>
                                <label class="ved-file"><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-editor-images><span>＋ <?= editor_h(t('Add from computer', 'Agregar desde la computadora')) ?></span><small><?= editor_h(t('You can refer to them as Image 1, Image 2… in their displayed order.', 'Podés referirte a ellas como Imagen 1, Imagen 2… en su orden de aparición.')) ?></small></label>
                                <div class="ved-image-list" data-editor-image-list></div>
                            </div>
                        </details>
                        <?php if (!$editable): ?><p class="ved-warning"><?= editor_h(t('This video is longer than 10 seconds. Omni cannot edit it in full; split it into shorter clips.', 'Este video dura más de 10 segundos. Omni no puede editarlo completo; dividilo en clips más cortos.')) ?></p><?php endif; ?>
                        <p class="ved-error" data-editor-error role="alert" hidden></p>
                        <footer><span data-editor-state><?= $editable ? editor_h(t('Ready to edit', 'Listo para editar')) : editor_h(t('Editing unavailable', 'Edición no disponible')) ?></span><button type="submit"<?= $editable ? '' : ' disabled' ?>><?= editor_h(t('Create new version', 'Crear nueva versión')) ?></button></footer>
                    </form>
                </section>
                <section class="catalog-panel ved-result" data-editor-result hidden>
                    <div><span><?= editor_h(t('New version', 'Nueva versión')) ?></span><h2><?= editor_h(t('Edited result', 'Resultado editado')) ?></h2></div>
                    <video controls playsinline data-editor-result-video></video>
                    <footer><a class="button-link secondary" data-editor-download><?= editor_h(t('Download MP4', 'Descargar MP4')) ?></a><a class="button-link" href="videos.php"><?= editor_h(t('View in Videos', 'Ver en Videos')) ?></a></footer>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($source): ?><script src="video_editor.js?v=2"></script><?php endif; ?>
</body>
</html>
