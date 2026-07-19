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
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Editor de video - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="video_editor.css?v=1">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= editor_h($user['email']) ?></a></header>
        <div class="alert-strip">Cada edición crea una nueva versión. El video original se conserva.</div>
        <div class="ved-page">
            <header class="catalog-heading ved-heading">
                <div><span>Herramienta independiente</span><h1>Editor de video</h1><p>Ajusta un clip con Gemini Omni.</p></div>
                <a class="button-link secondary" href="videos.php">Volver a Videos</a>
            </header>

            <?php if (!$source): ?>
                <section class="catalog-panel ved-empty">
                    <span aria-hidden="true">▶</span><h2>Selecciona un video</h2>
                    <p>En Videos, pulsa “Editar” sobre un clip generado o un video final.</p>
                    <a class="button-link" href="videos.php">Elegir video</a>
                </section>
            <?php else: ?>
                <section class="catalog-panel ved-workspace">
                    <aside class="ved-source">
                        <div class="ved-section-label"><span>Origen</span><strong><?= editor_h($source['title']) ?></strong></div>
                        <video src="<?= editor_h($source['previewUrl']) ?>" controls playsinline preload="metadata"></video>
                        <div class="ved-source-meta"><strong><?= editor_h($source['projectTitle']) ?></strong><span><?= editor_h(editor_duration((float)$source['durationSeconds'])) ?> · <?= editor_h($source['aspectRatio']) ?></span></div>
                    </aside>
                    <form class="ved-form" data-video-editor-form enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= editor_h($csrf) ?>">
                        <input type="hidden" name="sourceType" value="<?= editor_h($sourceType) ?>">
                        <input type="hidden" name="sourceId" value="<?= $sourceId ?>">
                        <label class="ved-prompt"><span>¿Qué quieres cambiar?</span><textarea name="prompt" required placeholder="Describe solo el cambio. Omni conservará lo demás."></textarea></label>
                        <details class="ved-references">
                            <summary><span>Imágenes de referencia <small>Opcional · hasta 10</small></span><b>+</b></summary>
                            <div>
                                <label class="ved-file"><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-editor-images><span>＋ Añadir desde ordenador</span><small>Podrás citarlas como Imagen 1, Imagen 2… según su orden.</small></label>
                                <div class="ved-image-list" data-editor-image-list></div>
                            </div>
                        </details>
                        <?php if (!$editable): ?><p class="ved-warning">Este video dura más de 10 segundos. Omni no permite editarlo completo; divide el video en clips cortos.</p><?php endif; ?>
                        <p class="ved-error" data-editor-error role="alert" hidden></p>
                        <footer><span data-editor-state><?= $editable ? 'Listo para editar' : 'Edición no disponible' ?></span><button type="submit"<?= $editable ? '' : ' disabled' ?>>Crear nueva versión</button></footer>
                    </form>
                </section>
                <section class="catalog-panel ved-result" data-editor-result hidden>
                    <div><span>Nueva versión</span><h2>Resultado editado</h2></div>
                    <video controls playsinline data-editor-result-video></video>
                    <footer><a class="button-link secondary" data-editor-download>Descargar MP4</a><a class="button-link" href="videos.php">Ver en Videos</a></footer>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($source): ?><script src="video_editor.js?v=1"></script><?php endif; ?>
</body>
</html>
