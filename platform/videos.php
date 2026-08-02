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
    if ($artworkId > 0) $artworkOptions[$artworkId] = trim((string)($video['artworkTitle'] ?? '')) ?: t('Artwork #', 'Obra #') . $artworkId;
    if ($seriesId > 0) $seriesOptions[$seriesId] = trim((string)($video['seriesTitle'] ?? '')) ?: t('Series #', 'Serie #') . $seriesId;
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
    if ($label === '') return t('Generated video', 'Video generado');
    return (string)(preg_replace('/^Sequence\s+/i', 'Sequence ', $label) ?: $label);
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

function videos_artist_site_url(string $slug): string
{
    if ($slug === '') return '';
    $catalog = rtrim(app_env('ARTIST_WEBSITE_CATALOG_URL', 'https://mauriziovalch.com/artworks'), '/');
    return $catalog . '/' . rawurlencode($slug) . '/video/';
}
?>
<!doctype html>
<html lang="<?= videos_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= videos_h(t('Videos - Artwork Mockups', 'Videos - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="videos.css?v=16">
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= videos_h($user['email']) ?></a>
        </header>

        <div class="alert-strip"><?= videos_h(t('Private archive of clips created in Video Lab.', 'Archivo privado de clips creados en Video Lab.')) ?></div>

        <div class="videos-catalog">
            <header class="catalog-heading videos-heading">
                <div>
                    <span class="videos-kicker"><?= videos_h(t('Library', 'Biblioteca')) ?></span>
                    <h1><?= videos_h(t('Videos', 'Videos')) ?></h1>
                    <p class="videos-page-desc">
                        <span class="videos-desc-kicker"><?= count($videos) ?> <?= count($videos) === 1 ? videos_h(t('generated video', 'video generado')) : videos_h(t('generated videos', 'videos generados')) ?> <?= videos_h(t('in your private library.', 'en tu biblioteca privada.')) ?></span>
                        <span class="videos-desc-instructions"><?= videos_h(t('Use Video Lab to create motion studies from your mockups, then upload completed edits and keep every video linked to its artwork and project.', 'Usá Video Lab para crear estudios de movimiento a partir de tus mockups, después subí las ediciones terminadas y mantené cada video vinculado a su obra y proyecto.')) ?></span>
                    </p>
                </div>
                <div class="videos-primary-actions">
                    <a class="videos-decision-block videos-decision-block--primary" href="video.php"><?= videos_h(t('Open Video Lab', 'Abrir Video Lab')) ?></a>
                    <a class="videos-decision-block videos-decision-block--secondary" href="#upload-final-video" role="button" data-open-final-upload><?= videos_h(t('Upload Final Video', 'Subir video final')) ?></a>
                </div>
            </header>

            <section class="catalog-panel videos-panel videos-final-panel" aria-labelledby="videos-final-title">
                <div class="videos-panel-heading">
                    <div>
                        <span><?= videos_h(t('Full video', 'Video completo')) ?></span>
                        <div class="videos-panel-title-row">
                            <h2 id="videos-final-title"><?= videos_h(t('Final videos', 'Videos finales')) ?></h2>
                            <span class="videos-count"><?= count($finals) ?> <?= count($finals) === 1 ? videos_h(t('video', 'video')) : videos_h(t('videos', 'videos')) ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!$finals): ?>
                    <div class="videos-final-empty">
                        <p><?= videos_h(t('Store the complete cut exported from your desktop editor here.', 'Guardá acá el corte completo exportado desde tu editor de escritorio.')) ?></p>
                    </div>
                <?php else: ?>
                    <div class="videos-final-grid">
                        <?php foreach ($finals as $index => $final): ?>
                            <?php
                            $previewUrl = (string)$final['previewUrl'];
                            $thumbnailUrl = (string)($final['thumbnailUrl'] ?? '');
                            $projectTitle = trim((string)($final['displayTitle'] ?? '')) ?: t('Final video', 'Video final');
                            $associatedArtwork = trim((string)($final['artworkTitle'] ?? ''));
                            $sourceLabel = ($final['source'] ?? '') === 'desktop' ? t('Uploaded from computer', 'Subido desde la computadora') : t('Created in Video Lab', 'Creado en Video Lab');
                            $sitePublished = !empty($final['sitePublished']);
                            $siteVideoUrl = videos_artist_site_url((string)($final['siteSlug'] ?? ''));
                            ?>
                            <article class="videos-final-card">
                                <div class="videos-final-media-shell">
                                    <button class="videos-final-media" type="button" data-video-preview="<?= videos_h($previewUrl) ?>" data-video-title="<?= videos_h($projectTitle) ?>" data-video-project="<?= videos_h(t('Final video', 'Video final')) ?>">
                                        <?php if ($thumbnailUrl !== ''): ?><img src="<?= videos_h($thumbnailUrl) ?>" alt="<?= videos_h(t('Frame from', 'Fotograma de')) ?> <?= videos_h($projectTitle) ?>" loading="<?= $index < 3 ? 'eager' : 'lazy' ?>"><?php endif; ?>
                                        <span class="videos-play media-play-control" aria-hidden="true"><i></i></span>
                                    </button>
                                    <div class="media-thumb-action-cluster" aria-label="<?= videos_h(t('Video actions', 'Acciones del video')) ?>">
                                        <a class="media-icon-button" href="video_editor.php?export_id=<?= (int)$final['id'] ?>" aria-label="<?= videos_h(t('Edit video', 'Editar video')) ?>" title="<?= videos_h(t('Edit video', 'Editar video')) ?>"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a>
                                        <a class="media-icon-button" href="<?= videos_h($previewUrl) ?>&download=1" aria-label="<?= videos_h(t('Download video', 'Descargar video')) ?>" title="<?= videos_h(t('Download video', 'Descargar video')) ?>"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M5 19h14"/></svg></a>
                                    </div>
                                </div>
                                <div class="videos-final-copy">
                                    <span><?= videos_h($sourceLabel) ?></span>
                                    <h3><?= videos_h($projectTitle) ?></h3>
                                    <p><?= videos_h(videos_duration((float)$final['durationSeconds'])) ?> · <?= videos_h((string)$final['aspectRatio']) ?></p>
                                    <details class="videos-final-assignment">
                                        <summary><?= videos_h($associatedArtwork !== '' ? t('Artwork: ', 'Obra: ') . $associatedArtwork : t('Assign artwork', 'Asignar obra')) ?></summary>
                                        <form data-final-artwork-form>
                                            <input type="hidden" name="csrf" value="<?= videos_h($csrf) ?>">
                                            <input type="hidden" name="exportId" value="<?= (int)$final['id'] ?>">
                                            <select name="artworkId" required aria-label="<?= videos_h(t('Associated artwork', 'Obra asociada')) ?>">
                                                <option value=""><?= videos_h(t('Select artwork', 'Seleccionar obra')) ?></option>
                                                <?php foreach ($finalArtworkOptions as $artworkId => $artworkTitle): ?>
                                                    <option value="<?= (int)$artworkId ?>" <?= (int)($final['canonicalArtworkId'] ?? 0) === (int)$artworkId ? 'selected' : '' ?>><?= videos_h($artworkTitle) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit"><?= videos_h(t('Save', 'Guardar')) ?></button>
                                            <small data-final-artwork-error hidden></small>
                                        </form>
                                    </details>
                                    <div class="videos-final-publish videos-final-site-state">
                                        <?php if ($sitePublished): ?>
                                            <span><?= videos_h(t('ON THE ARTWORK PAGE', 'EN LA PÁGINA DE LA OBRA')) ?></span>
                                            <?php if ($siteVideoUrl !== ''): ?>
                                                <a href="<?= videos_h($siteVideoUrl) ?>" target="_blank" rel="noopener"><?= videos_h(t('VIEW →', 'VER →')) ?></a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span><?= videos_h(t('Publishes to the site from', 'Se publica al sitio desde')) ?> <a href="publication.php<?= (int)($final['canonicalArtworkId'] ?? 0) > 0 ? '?id=' . (int)$final['canonicalArtworkId'] : '' ?>"><?= videos_h(t('Publication', 'Publicación')) ?></a></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="catalog-panel videos-panel" aria-labelledby="videos-generated-title">
                <div class="videos-panel-heading">
                    <div>
                        <span><?= videos_h(t('Generation file', 'Archivo de generación')) ?></span>
                        <h2 id="videos-generated-title"><?= videos_h(t('Generated videos', 'Videos generados')) ?></h2>
                    </div>
                    <span class="videos-count" data-video-visible-count><?= count($videos) ?></span>
                </div>

                <?php if (!$videos): ?>
                    <div class="videos-empty">
                        <span aria-hidden="true">▶</span>
                        <h2><?= videos_h(t('No videos yet', 'Todavía no hay videos')) ?></h2>
                        <p><?= videos_h(t('Successfully generated clips will appear here.', 'Los clips generados con éxito van a aparecer acá.')) ?></p>
                        <a class="button-link secondary" href="video.php"><?= videos_h(t('Create in Video Lab', 'Crear en Video Lab')) ?></a>
                    </div>
                <?php else: ?>
                    <div class="videos-filters" aria-label="<?= videos_h(t('Video filters', 'Filtros de video')) ?>">
                        <label>
                            <span><?= videos_h(t('Artwork', 'Obra')) ?></span>
                            <select data-video-filter-artwork>
                                <option value=""><?= videos_h(t('All artworks', 'Todas las obras')) ?></option>
                                <?php foreach ($artworkOptions as $artworkId => $artworkTitle): ?>
                                    <option value="<?= (int)$artworkId ?>"><?= videos_h($artworkTitle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= videos_h(t('Series', 'Series')) ?></span>
                            <select data-video-filter-series>
                                <option value=""><?= videos_h(t('All series', 'Todas las series')) ?></option>
                                <?php foreach ($seriesOptions as $seriesId => $seriesTitle): ?>
                                    <option value="<?= (int)$seriesId ?>"><?= videos_h($seriesTitle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="videos-carousel-shell" data-video-carousel-shell>
                        <button class="videos-carousel-arrow videos-carousel-arrow--previous" type="button" data-video-carousel-arrow="-1" aria-label="<?= videos_h(t('Previous videos', 'Videos anteriores')) ?>">‹</button>
                        <div class="videos-carousel" data-video-carousel tabindex="0" aria-label="<?= videos_h(t('Generated videos', 'Videos generados')) ?>">
                            <?php foreach ($videos as $index => $video): ?>
                                <?php
                                $id = (int)$video['id'];
                                $sceneTitle = videos_scene_label((string)$video['label']);
                                $projectTitle = trim((string)($video['projectTitle'] ?? '')) ?: t('Video project', 'Proyecto de video');
                                $generationVersion = max(1, (int)($video['generationVersion'] ?? 1));
                                $clipIdentity = $sceneTitle . ' · ' . t('Version', 'Versión') . ' ' . $generationVersion;
                                $previewUrl = 'video_media.php?generation_id=' . $id;
                                $thumbnailUrl = $previewUrl . '&thumbnail=1';
                                $downloadUrl = $previewUrl . '&download=1';
                                $createdAt = videos_date((string)($video['createdAt'] ?? ''));
                                $aspect = (string)($video['aspectRatio'] ?? '9:16');
                                $artworkId = (int)($video['artworkId'] ?? 0);
                                $artworkTitle = trim((string)($video['artworkTitle'] ?? '')) ?: t('Unassigned artwork', 'Obra sin asignar');
                                $seriesId = (int)($video['seriesId'] ?? 0);
                                $seriesTitle = trim((string)($video['seriesTitle'] ?? ''));
                                ?>
                                <article class="videos-card <?= $aspect === '16:9' ? 'is-landscape' : 'is-portrait' ?>" data-video-card data-artwork-id="<?= $artworkId ?>" data-series-id="<?= $seriesId ?>" data-generation-id="<?= $id ?>">
                                    <div class="videos-card-media-shell">
                                        <button
                                            class="videos-card-media"
                                            type="button"
                                            data-video-preview="<?= videos_h($previewUrl) ?>"
                                            data-video-title="<?= videos_h($projectTitle) ?>"
                                            data-video-project="<?= videos_h($clipIdentity) ?>"
                                            aria-label="<?= videos_h(t('Play', 'Reproducir')) ?> <?= videos_h($projectTitle . ' · ' . $clipIdentity) ?>"
                                        >
                                            <img src="<?= videos_h($thumbnailUrl) ?>" alt="<?= videos_h(t('Frame from', 'Fotograma de')) ?> <?= videos_h($projectTitle) ?>" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async">
                                            <span class="videos-play media-play-control" aria-hidden="true"><i></i></span>
                                            <?php if (!empty($video['active'])): ?><em class="videos-current"><?= videos_h(t('Current', 'Actual')) ?></em><?php endif; ?>
                                        </button>
                                        <div class="media-thumb-action-cluster" aria-label="<?= videos_h(t('Video actions', 'Acciones del video')) ?>">
                                            <a class="media-icon-button" href="video_editor.php?generation_id=<?= $id ?>" aria-label="<?= videos_h(t('Edit video', 'Editar video')) ?>" title="<?= videos_h(t('Edit video', 'Editar video')) ?>"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg></a>
                                            <a class="media-icon-button" href="<?= videos_h($downloadUrl) ?>" aria-label="<?= videos_h(t('Download video', 'Descargar video')) ?>" title="<?= videos_h(t('Download video', 'Descargar video')) ?>"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5M5 19h14"/></svg></a>
                                            <button class="media-icon-button videos-delete-generation" type="button" data-delete-generation="<?= $id ?>" data-generation-active="<?= !empty($video['active']) ? '1' : '0' ?>" data-generation-label="<?= videos_h($projectTitle . ' · ' . $clipIdentity) ?>" aria-label="<?= videos_h(t('Delete video permanently', 'Eliminar video definitivamente')) ?>" title="<?= videos_h(t('Delete permanently', 'Eliminar definitivamente')) ?>"><svg class="media-action-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5"/></svg></button>
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
                        <button class="videos-carousel-arrow videos-carousel-arrow--next" type="button" data-video-carousel-arrow="1" aria-label="<?= videos_h(t('Next videos', 'Videos siguientes')) ?>">›</button>
                    </div>
                    <div class="videos-no-results" data-video-no-results hidden>
                        <strong><?= videos_h(t('No videos match these filters.', 'Ningún video coincide con estos filtros.')) ?></strong>
                        <span><?= videos_h(t('Try another artwork or series.', 'Probá con otra obra o serie.')) ?></span>
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
                        <h2 id="videos-modal-title" data-video-modal-title><?= videos_h(t('Video', 'Video')) ?></h2>
                    </div>
                    <button type="button" data-video-modal-close aria-label="<?= videos_h(t('Close', 'Cerrar')) ?>">×</button>
                </header>
                <video controls playsinline preload="metadata" data-video-modal-player></video>
            </section>
        </div>

        <div class="videos-modal videos-upload-modal" data-final-upload-modal hidden>
            <div class="videos-modal-backdrop" data-close-final-upload></div>
            <section class="videos-upload-dialog" role="dialog" aria-modal="true" aria-labelledby="videos-upload-title">
                <header>
                    <div><span><?= videos_h(t('Full video', 'Video completo')) ?></span><h2 id="videos-upload-title"><?= videos_h(t('Upload final video', 'Subir video final')) ?></h2></div>
                    <button type="button" data-close-final-upload aria-label="<?= videos_h(t('Close', 'Cerrar')) ?>">×</button>
                </header>
                <form data-final-upload-form enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= videos_h($csrf) ?>">
                    <label><span><?= videos_h(t('Artwork', 'Obra')) ?></span><select name="artworkId" required>
                        <option value=""><?= videos_h(t('Select artwork', 'Seleccionar obra')) ?></option>
                        <?php foreach ($finalArtworkOptions as $artworkId => $artworkTitle): ?><option value="<?= (int)$artworkId ?>"><?= videos_h($artworkTitle) ?></option><?php endforeach; ?>
                    </select></label>
                    <label><span><?= videos_h(t('Project', 'Proyecto')) ?></span><select name="projectId" required>
                        <option value=""><?= videos_h(t('Select project', 'Seleccionar proyecto')) ?></option>
                        <?php foreach ($projects as $project): ?><option value="<?= (int)$project['id'] ?>"><?= videos_h($project['title']) ?></option><?php endforeach; ?>
                    </select></label>
                    <label class="videos-upload-file"><span><?= videos_h(t('File', 'Archivo')) ?></span><input type="file" name="video" accept="video/mp4,video/quicktime,video/webm" required><small><?= videos_h(t('MP4, MOV, or WebM · up to 500 MB · uploaded straight to storage', 'MP4, MOV o WebM · hasta 500 MB · se sube directo al almacenamiento')) ?></small></label>
                    <p data-final-upload-error role="alert" hidden></p>
                    <footer><button type="button" class="button-link secondary" data-close-final-upload><?= videos_h(t('Cancel', 'Cancelar')) ?></button><button type="submit" class="button-link"><?= videos_h(t('Upload video', 'Subir video')) ?></button></footer>
                </form>
            </section>
        </div>
    </main>
</div>
<script src="videos.js?v=14"></script>
</body>
</html>
