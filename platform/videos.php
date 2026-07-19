<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::VIDEO_MANAGE, 'Videos');
$userId = (int)$user['id'];
$repository = new VideoStudioRepository(Database::connection());
$library = $repository->library($userId);
$videos = is_array($library['generatedClips'] ?? null) ? $library['generatedClips'] : [];
$finals = $repository->finalVideos($userId);
$projects = $repository->listProjects($userId);
$csrf = VideoHttp::csrfToken();
$artworkOptions = [];
$finalArtworkOptions = [];
$seriesOptions = [];
foreach ($videos as $video) {
    $artworkId = (int)($video['artworkId'] ?? 0);
    $seriesId = (int)($video['seriesId'] ?? 0);
    if ($artworkId > 0) $artworkOptions[$artworkId] = trim((string)($video['artworkTitle'] ?? '')) ?: 'Artwork #' . $artworkId;
    if ($seriesId > 0) $seriesOptions[$seriesId] = trim((string)($video['seriesTitle'] ?? '')) ?: 'Serie #' . $seriesId;
}
foreach ((array)($library['rootArtworks'] ?? []) as $artwork) {
    $artworkId = (int)($artwork['canonicalArtworkId'] ?? $artwork['artworkId'] ?? 0);
    $artworkTitle = trim((string)($artwork['artworkTitle'] ?? ''));
    if ($artworkId > 0 && $artworkTitle !== '') $finalArtworkOptions[$artworkId] = $artworkTitle;
}
natcasesort($artworkOptions);
natcasesort($finalArtworkOptions);
natcasesort($seriesOptions);

function videos_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function videos_scene_label(string $label): string
{
    $label = trim($label);
    if ($label === '') return 'Video generado';
    return (string)(preg_replace('/^Sequence\s+/i', 'Secuencia ', $label) ?: $label);
}

function videos_duration(float $seconds): string
{
    if ($seconds <= 0) return '—';
    $rounded = round($seconds, 1);
    return rtrim(rtrim(number_format($rounded, 1, ',', ''), '0'), ',') . ' s';
}

function videos_date(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y · H:i', $timestamp) : '';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Videos - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="videos.css?v=8">
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= videos_h($user['email']) ?></a>
        </header>

        <div class="alert-strip">Archivo privado de clips creados en Video Lab.</div>

        <div class="videos-catalog">
            <header class="catalog-heading videos-heading">
                <div>
                    <span class="videos-kicker">Biblioteca</span>
                    <h1>Videos</h1>
                    <p><?= count($videos) ?> <?= count($videos) === 1 ? 'video generado' : 'videos generados' ?>.</p>
                </div>
                <a class="button-link" href="video.php">Abrir Video Lab</a>
            </header>

            <section class="catalog-panel videos-panel videos-final-panel" aria-labelledby="videos-final-title">
                <div class="videos-panel-heading">
                    <div>
                        <span>Video completo</span>
                        <h2 id="videos-final-title">Videos finales</h2>
                    </div>
                    <div class="videos-panel-actions">
                        <span class="videos-count"><?= count($finals) ?></span>
                        <button type="button" class="button-link" data-open-final-upload>Subir video final</button>
                    </div>
                </div>

                <?php if (!$finals): ?>
                    <div class="videos-final-empty">
                        <p>Guarda aquí el montaje completo exportado desde tu editor de escritorio.</p>
                    </div>
                <?php else: ?>
                    <div class="videos-final-grid">
                        <?php foreach ($finals as $index => $final): ?>
                            <?php
                            $previewUrl = (string)$final['previewUrl'];
                            $thumbnailUrl = (string)($final['thumbnailUrl'] ?? '');
                            $projectTitle = trim((string)($final['displayTitle'] ?? '')) ?: 'Video final';
                            $associatedArtwork = trim((string)($final['artworkTitle'] ?? ''));
                            $sourceLabel = ($final['source'] ?? '') === 'desktop' ? 'Subido desde ordenador' : 'Creado en Video Lab';
                            ?>
                            <article class="videos-final-card">
                                <div class="videos-final-media-shell">
                                    <button class="videos-final-media" type="button" data-video-preview="<?= videos_h($previewUrl) ?>" data-video-title="<?= videos_h($projectTitle) ?>" data-video-project="Video final">
                                        <?php if ($thumbnailUrl !== ''): ?><img src="<?= videos_h($thumbnailUrl) ?>" alt="Fotograma de <?= videos_h($projectTitle) ?>" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>"><?php endif; ?>
                                        <span class="videos-play media-play-control" aria-hidden="true"><i></i></span>
                                    </button>
                                    <div class="media-thumb-action-cluster" aria-label="Acciones del video">
                                        <a class="media-icon-button" href="video_editor.php?export_id=<?= (int)$final['id'] ?>" aria-label="Editar video" title="Editar video"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a>
                                        <a class="media-icon-button" href="<?= videos_h($previewUrl) ?>&download=1" aria-label="Descargar video" title="Descargar video"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M5 19h14"/></svg></a>
                                    </div>
                                </div>
                                <div class="videos-final-copy">
                                    <span><?= videos_h($sourceLabel) ?></span>
                                    <h3><?= videos_h($projectTitle) ?></h3>
                                    <p><?= videos_h(videos_duration((float)$final['durationSeconds'])) ?> · <?= videos_h((string)$final['aspectRatio']) ?></p>
                                    <details class="videos-final-assignment">
                                        <summary><?= videos_h($associatedArtwork !== '' ? 'Obra: ' . $associatedArtwork : 'Asignar obra') ?></summary>
                                        <form data-final-artwork-form>
                                            <input type="hidden" name="csrf" value="<?= videos_h($csrf) ?>">
                                            <input type="hidden" name="exportId" value="<?= (int)$final['id'] ?>">
                                            <select name="artworkId" required aria-label="Obra asociada">
                                                <option value="">Seleccionar obra</option>
                                                <?php foreach ($finalArtworkOptions as $artworkId => $artworkTitle): ?>
                                                    <option value="<?= (int)$artworkId ?>" <?= (int)($final['canonicalArtworkId'] ?? 0) === (int)$artworkId ? 'selected' : '' ?>><?= videos_h($artworkTitle) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit">Guardar</button>
                                            <small data-final-artwork-error hidden></small>
                                        </form>
                                    </details>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="catalog-panel videos-panel" aria-labelledby="videos-generated-title">
                <div class="videos-panel-heading">
                    <div>
                        <span>Archivo de generación</span>
                        <h2 id="videos-generated-title">Videos generados</h2>
                    </div>
                    <span class="videos-count" data-video-visible-count><?= count($videos) ?></span>
                </div>

                <?php if (!$videos): ?>
                    <div class="videos-empty">
                        <span aria-hidden="true">▶</span>
                        <h2>Aún no hay videos</h2>
                        <p>Los clips generados correctamente aparecerán aquí.</p>
                        <a class="button-link secondary" href="video.php">Crear en Video Lab</a>
                    </div>
                <?php else: ?>
                    <div class="videos-filters" aria-label="Filtros de videos">
                        <label>
                            <span>Obra</span>
                            <select data-video-filter-artwork>
                                <option value="">Todas las obras</option>
                                <?php foreach ($artworkOptions as $artworkId => $artworkTitle): ?>
                                    <option value="<?= (int)$artworkId ?>"><?= videos_h($artworkTitle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Serie</span>
                            <select data-video-filter-series>
                                <option value="">Todas las series</option>
                                <?php foreach ($seriesOptions as $seriesId => $seriesTitle): ?>
                                    <option value="<?= (int)$seriesId ?>"><?= videos_h($seriesTitle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="videos-carousel-shell" data-video-carousel-shell>
                        <button class="videos-carousel-arrow videos-carousel-arrow--previous" type="button" data-video-carousel-arrow="-1" aria-label="Videos anteriores">‹</button>
                        <div class="videos-carousel" data-video-carousel tabindex="0" aria-label="Videos generados">
                            <?php foreach ($videos as $index => $video): ?>
                                <?php
                                $id = (int)$video['id'];
                                $sceneTitle = videos_scene_label((string)$video['label']);
                                $projectTitle = trim((string)($video['projectTitle'] ?? '')) ?: 'Proyecto de video';
                                $generationVersion = max(1, (int)($video['generationVersion'] ?? 1));
                                $clipIdentity = $sceneTitle . ' · Versión ' . $generationVersion;
                                $previewUrl = 'video_media.php?generation_id=' . $id;
                                $thumbnailUrl = $previewUrl . '&thumbnail=1';
                                $downloadUrl = $previewUrl . '&download=1';
                                $createdAt = videos_date((string)($video['createdAt'] ?? ''));
                                $aspect = (string)($video['aspectRatio'] ?? '9:16');
                                $artworkId = (int)($video['artworkId'] ?? 0);
                                $artworkTitle = trim((string)($video['artworkTitle'] ?? '')) ?: 'Obra sin asociación';
                                $seriesId = (int)($video['seriesId'] ?? 0);
                                $seriesTitle = trim((string)($video['seriesTitle'] ?? ''));
                                ?>
                                <article class="videos-card <?= $aspect === '16:9' ? 'is-landscape' : 'is-portrait' ?>" data-video-card data-artwork-id="<?= $artworkId ?>" data-series-id="<?= $seriesId ?>">
                                    <div class="videos-card-media-shell">
                                        <button
                                            class="videos-card-media"
                                            type="button"
                                            data-video-preview="<?= videos_h($previewUrl) ?>"
                                            data-video-title="<?= videos_h($projectTitle) ?>"
                                            data-video-project="<?= videos_h($clipIdentity) ?>"
                                            aria-label="Reproducir <?= videos_h($projectTitle . ' · ' . $clipIdentity) ?>"
                                        >
                                            <img src="<?= videos_h($thumbnailUrl) ?>" alt="Fotograma de <?= videos_h($projectTitle) ?>" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async">
                                            <span class="videos-play media-play-control" aria-hidden="true"><i></i></span>
                                            <?php if (!empty($video['active'])): ?><em class="videos-current">Actual</em><?php endif; ?>
                                        </button>
                                        <div class="media-thumb-action-cluster" aria-label="Acciones del video">
                                            <a class="media-icon-button" href="video_editor.php?generation_id=<?= $id ?>" aria-label="Editar video" title="Editar video"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a>
                                            <a class="media-icon-button" href="<?= videos_h($downloadUrl) ?>" aria-label="Descargar video" title="Descargar video"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M5 19h14"/></svg></a>
                                        </div>
                                    </div>
                                    <div class="videos-card-body">
                                        <span class="videos-card-project"><?= videos_h($clipIdentity) ?></span>
                                        <h3><?= videos_h($projectTitle) ?></h3>
                                        <div class="videos-card-association">
                                            <strong><?= videos_h($artworkTitle) ?></strong>
                                            <?php if ($seriesTitle !== ''): ?><span><?= videos_h($seriesTitle) ?></span><?php endif; ?>
                                        </div>
                                        <p>
                                            <span><?= videos_h(videos_duration((float)($video['durationSeconds'] ?? 0))) ?></span>
                                            <span><?= videos_h($aspect) ?></span>
                                            <?php if ($createdAt !== ''): ?><time><?= videos_h($createdAt) ?></time><?php endif; ?>
                                        </p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <button class="videos-carousel-arrow videos-carousel-arrow--next" type="button" data-video-carousel-arrow="1" aria-label="Videos siguientes">›</button>
                    </div>
                    <div class="videos-no-results" data-video-no-results hidden>
                        <strong>No hay videos con estos filtros.</strong>
                        <span>Prueba con otra obra o serie.</span>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <div class="videos-modal" data-video-modal hidden>
            <div class="videos-modal-backdrop" data-video-modal-close></div>
            <section class="videos-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="videos-modal-title">
                <header>
                    <div>
                        <span data-video-modal-project></span>
                        <h2 id="videos-modal-title" data-video-modal-title>Video</h2>
                    </div>
                    <button type="button" data-video-modal-close aria-label="Cerrar">×</button>
                </header>
                <video controls playsinline preload="metadata" data-video-modal-player></video>
            </section>
        </div>

        <div class="videos-modal videos-upload-modal" data-final-upload-modal hidden>
            <div class="videos-modal-backdrop" data-close-final-upload></div>
            <section class="videos-upload-dialog" role="dialog" aria-modal="true" aria-labelledby="videos-upload-title">
                <header>
                    <div><span>Video completo</span><h2 id="videos-upload-title">Subir video final</h2></div>
                    <button type="button" data-close-final-upload aria-label="Cerrar">×</button>
                </header>
                <form data-final-upload-form enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= videos_h($csrf) ?>">
                    <label><span>Obra</span><select name="artworkId" required>
                        <option value="">Seleccionar obra</option>
                        <?php foreach ($finalArtworkOptions as $artworkId => $artworkTitle): ?><option value="<?= (int)$artworkId ?>"><?= videos_h($artworkTitle) ?></option><?php endforeach; ?>
                    </select></label>
                    <label><span>Proyecto</span><select name="projectId" required>
                        <option value="">Seleccionar proyecto</option>
                        <?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= videos_h($project['title']) ?></option><?php endforeach; ?>
                    </select></label>
                    <label class="videos-upload-file"><span>Archivo</span><input type="file" name="video" accept="video/mp4,video/quicktime,video/webm" required><small>MP4, MOV o WebM · máximo 500 MB</small></label>
                    <p data-final-upload-error role="alert" hidden></p>
                    <footer><button type="button" class="button-link secondary" data-close-final-upload>Cancelar</button><button type="submit" class="button-link">Subir video</button></footer>
                </form>
            </section>
        </div>
    </main>
</div>
<script src="videos.js?v=6"></script>
</body>
</html>
