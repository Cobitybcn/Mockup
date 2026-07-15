<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$repository = new VideoStudioRepository(Database::connection());
$library = $repository->library($userId);
$videos = is_array($library['generatedClips'] ?? null) ? $library['generatedClips'] : [];
$artworkOptions = [];
$seriesOptions = [];
foreach ($videos as $video) {
    $artworkId = (int)($video['artworkId'] ?? 0);
    $seriesId = (int)($video['seriesId'] ?? 0);
    if ($artworkId > 0) $artworkOptions[$artworkId] = trim((string)($video['artworkTitle'] ?? '')) ?: 'Artwork #' . $artworkId;
    if ($seriesId > 0) $seriesOptions[$seriesId] = trim((string)($video['seriesTitle'] ?? '')) ?: 'Serie #' . $seriesId;
}
natcasesort($artworkOptions);
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
    <link rel="stylesheet" href="videos.css?v=3">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= videos_h($user['email']) ?></a>
        </header>

        <div class="alert-strip">Archivo privado de clips creados en Video Studio.</div>

        <div class="videos-catalog">
            <header class="catalog-heading videos-heading">
                <div>
                    <span class="videos-kicker">Biblioteca</span>
                    <h1>Videos</h1>
                    <p><?= count($videos) ?> <?= count($videos) === 1 ? 'video generado' : 'videos generados' ?>.</p>
                </div>
                <a class="button-link" href="video.php">Abrir Video Studio</a>
            </header>

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
                        <a class="button-link secondary" href="video.php">Crear en Video Studio</a>
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
                                    <button
                                        class="videos-card-media"
                                        type="button"
                                        data-video-preview="<?= videos_h($previewUrl) ?>"
                                        data-video-title="<?= videos_h($projectTitle) ?>"
                                        data-video-project="<?= videos_h($clipIdentity) ?>"
                                        aria-label="Reproducir <?= videos_h($projectTitle . ' · ' . $clipIdentity) ?>"
                                    >
                                        <img src="<?= videos_h($thumbnailUrl) ?>" alt="Fotograma de <?= videos_h($projectTitle) ?>" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async">
                                        <span class="videos-play" aria-hidden="true"><i></i></span>
                                        <?php if (!empty($video['active'])): ?><em class="videos-current">Actual</em><?php endif; ?>
                                    </button>
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
                                    <footer class="videos-card-actions">
                                        <button type="button" data-video-preview="<?= videos_h($previewUrl) ?>" data-video-title="<?= videos_h($projectTitle) ?>" data-video-project="<?= videos_h($clipIdentity) ?>">Ver video</button>
                                        <a href="<?= videos_h($downloadUrl) ?>">Descargar MP4</a>
                                    </footer>
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
    </main>
</div>
<script src="videos.js?v=3"></script>
</body>
</html>
