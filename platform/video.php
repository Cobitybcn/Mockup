<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::VIDEO_MANAGE, 'Video Lab');
$userId = (int)$user['id'];
$repository = new VideoStudioRepository(Database::connection());
$service = new VideoStudioService($repository);
$projects = $service->listProjects($userId);
$requestedProjectId = (int)($_GET['project'] ?? 0);
$projectId = $requestedProjectId > 0 ? $requestedProjectId : (int)($projects[0]['id'] ?? 0);
$studio = null;
if ($projectId > 0) {
    try {
        $studio = $service->studioPayload($userId, $projectId);
    } catch (OutOfBoundsException) {
        http_response_code(404);
        exit('Video project not found.');
    }
} else {
    $studio = $service->createProject($userId, [
        'aspectRatio' => '9:16',
        'targetDurationSeconds' => 24,
        'projectType' => 'social_clip',
    ]);
    $projectId = (int)$studio['project']['id'];
    $projects = $service->listProjects($userId);
}

$assets = $service->library($userId);
$requestedArtworkId = max(0, (int)($_GET['artwork_id'] ?? 0));
$availableArtworkFilters = [];
foreach (array_merge((array)($assets['rootArtworks'] ?? []), (array)($assets['mockups'] ?? [])) as $asset) {
    $assetArtworkId = max(0, (int)($asset['artworkId'] ?? 0));
    $assetGroupId = max(0, (int)($asset['artworkGroupId'] ?? 0));
    $assetCanonicalId = max(0, (int)($asset['canonicalArtworkId'] ?? 0));
    $filterKey = $assetGroupId > 0 ? 'group:' . $assetGroupId : ($assetArtworkId > 0 ? 'artwork:' . $assetArtworkId : '');
    if ($assetArtworkId > 0 && $filterKey !== '') $availableArtworkFilters[$assetArtworkId] = $filterKey;
    if ($assetCanonicalId > 0 && $filterKey !== '') $availableArtworkFilters[$assetCanonicalId] = $filterKey;
}
$initialArtworkId = $requestedArtworkId > 0 && isset($availableArtworkFilters[$requestedArtworkId])
    ? $requestedArtworkId
    : max(0, (int)($studio['project']['artworkId'] ?? 0));
$initialArtworkFilter = (string)($availableArtworkFilters[$initialArtworkId] ?? '');

$payload = [
    'csrf' => VideoHttp::csrfToken(),
    'projects' => $projects,
    'studio' => $studio,
    'assets' => $assets,
    'initialArtworkId' => $initialArtworkId,
    'initialArtworkFilter' => $initialArtworkFilter,
    'capabilities' => $service->capabilities(),
    'endpoints' => [
        'api' => 'video_api.php',
        'generationStart' => 'video_generation_start.php',
        'generationStatus' => 'video_generation_status.php',
        'referenceUpload' => 'video_reference_upload.php',
        'exportStart' => 'video_export_start.php',
        'exportStatus' => 'video_export_status.php',
        'exportApprove' => 'video_export_approve.php',
        'musicUpload' => 'video_music_upload.php',
        'musicUpdate' => 'video_music_update.php',
        'timelineUpdate' => 'video_timeline_update.php',
    ],
];

function vds_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="<?= vds_h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= vds_h(t('Video Lab - Artwork Mockups', 'Video Lab - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="video_studio.css?v=28">
    <link rel="stylesheet" href="media-controls.css?v=2">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= vds_h($user['email']) ?></a></header>
        <div class="vds-page" data-video-studio data-project-id="<?= $projectId ?>">
            <div data-editor>
                <section class="vds-catalog" aria-labelledby="vds-catalog-title">
                    <div class="vds-catalog-head">
                        <div class="vds-catalog-heading">
                            <span class="vds-catalog-kicker"><?= vds_h(t('Reference Catalog', 'Catálogo de referencias')) ?></span>
                            <h1 id="vds-catalog-title"><?= vds_h(t('Video Lab', 'Video Lab')) ?></h1>
                            <div class="vds-filters">
                                <label>
                                    <span class="vds-sr-only"><?= vds_h(t('Filter by artwork', 'Filtrar por obra')) ?></span>
                                    <select data-artwork-filter><option value=""><?= vds_h(t('Filter by artwork', 'Filtrar por obra')) ?></option></select>
                                </label>
                                <label>
                                    <span class="vds-sr-only"><?= vds_h(t('Filter by series', 'Filtrar por serie')) ?></span>
                                    <select data-series-filter><option value=""><?= vds_h(t('Filter by series', 'Filtrar por serie')) ?></option></select>
                                </label>
                            </div>
                        </div>
                        <div class="vds-project-controls">
                            <label class="vds-project-title">
                                <span><?= vds_h(t('Project name', 'Nombre del proyecto')) ?></span>
                                <input type="text" maxlength="255" data-project-title aria-label="<?= vds_h(t('Current project name', 'Nombre del proyecto actual')) ?>">
                            </label>
                            <fieldset class="vds-aspect-picker">
                                <legend><?= vds_h(t('Format', 'Formato')) ?></legend>
                                <div class="vds-aspect-options" aria-label="<?= vds_h(t('Video format', 'Formato de video')) ?>">
                                    <button type="button" data-project-aspect-ratio="9:16" aria-pressed="false" aria-label="<?= vds_h(t('Vertical format 9:16', 'Formato vertical 9:16')) ?>" title="<?= vds_h(t('Vertical · 9:16', 'Vertical · 9:16')) ?>">
                                        <span class="vds-aspect-icon vds-aspect-icon--vertical" aria-hidden="true"></span>
                                        <span>9:16</span>
                                    </button>
                                    <button type="button" data-project-aspect-ratio="16:9" aria-pressed="false" aria-label="<?= vds_h(t('Horizontal format 16:9', 'Formato horizontal 16:9')) ?>" title="<?= vds_h(t('Horizontal · 16:9', 'Horizontal · 16:9')) ?>">
                                        <span class="vds-aspect-icon vds-aspect-icon--horizontal" aria-hidden="true"></span>
                                        <span>16:9</span>
                                    </button>
                                </div>
                            </fieldset>
                            <div class="vds-project-actions">
                                <span class="vds-save-state" data-save-state><?= vds_h(t('Saved', 'Guardado')) ?></span>
                                <button class="vds-project-action vds-project-action--save" type="button" data-save-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h12l2 2v14H5zM8 4v6h8V4M8 20v-6h8v6"/></svg>
                                    <span><?= vds_h(t('Save', 'Guardar')) ?></span>
                                </button>
                                <button class="vds-project-action vds-project-action--new" type="button" data-new-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                    <span><?= vds_h(t('New', 'Nuevo')) ?></span>
                                </button>
                                <button class="vds-project-action vds-project-action--delete" type="button" data-delete-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/></svg>
                                    <span><?= vds_h(t('Delete', 'Eliminar')) ?></span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="vds-catalog-rail-wrap">
                        <button class="vds-rail-arrow vds-rail-arrow--left" type="button" data-scroll-catalog="-1" aria-label="<?= vds_h(t('View previous references', 'Ver referencias anteriores')) ?>">‹</button>
                        <div class="vds-catalog-rail" data-catalog-rail aria-label="<?= vds_h(t('Available references', 'Referencias disponibles')) ?>"></div>
                        <button class="vds-rail-arrow vds-rail-arrow--right" type="button" data-scroll-catalog="1" aria-label="<?= vds_h(t('View more references', 'Ver más referencias')) ?>">›</button>
                    </div>
                    <p class="vds-catalog-help" data-catalog-help><?= vds_h(t('Drag an image or video to Start Frame or End Frame. You can also upload from your computer.', 'Arrastrá una imagen o video a Fotograma inicial o Fotograma final. También podés subir desde tu computadora.')) ?></p>
                </section>

                <section class="vds-sequences" aria-labelledby="vds-sequences-title">
                    <header class="vds-sequences-head">
                        <div>
                            <span><?= vds_h(t('Sortable short clips', 'Clips cortos ordenables')) ?></span>
                            <h2 id="vds-sequences-title"><?= vds_h(t('Sequences', 'Secuencias')) ?></h2>
                            <p><?= vds_h(t('Each board generates an independent clip and can continue the previous result.', 'Cada tablero genera un clip independiente y puede continuar el resultado anterior.')) ?></p>
                        </div>
                        <button type="button" data-add-sequence><span aria-hidden="true">＋</span> <?= vds_h(t('Add sequence', 'Agregar secuencia')) ?></button>
                    </header>
                    <div class="vds-board-grid" data-sequence-boards></div>
                    <section class="vds-montage" aria-labelledby="vds-montage-title" data-export-panel></section>
                </section>
            </div>

            <div class="vds-modal-backdrop" data-generation-modal hidden>
                <section class="vds-modal" role="dialog" aria-modal="true" aria-labelledby="vds-generation-title">
                    <span class="vds-modal-kicker"><?= vds_h(t('External generation', 'Generación externa')) ?></span>
                    <h2 id="vds-generation-title"><?= vds_h(t('Generate this sequence?', '¿Generar esta secuencia?')) ?></h2>
                    <div data-generation-summary></div>
                    <p class="vds-modal-warning"><?= vds_h(t('The provider will generate a new clip from the prompt and compatible references. A video placed in Start Frame contributes its final frame; when Start Frame is empty, the previous result may be used automatically.', 'El proveedor va a generar un clip nuevo a partir del prompt y las referencias compatibles. Un video ubicado en Fotograma inicial aporta su último fotograma; cuando Fotograma inicial está vacío, el resultado anterior puede usarse automáticamente.')) ?></p>
                    <div class="vds-modal-actions">
                        <button class="vds-secondary" type="button" data-cancel-generation><?= vds_h(t('Cancel', 'Cancelar')) ?></button>
                        <button type="button" data-confirm-generation><?= vds_h(t('Generate sequence', 'Generar secuencia')) ?></button>
                    </div>
                </section>
            </div>

            <div class="vds-toast" data-video-toast role="status" aria-live="polite"></div>
        </div>
    </main>
</div>
<script type="application/json" id="video-studio-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="video_studio.js?v=35"></script>
</body>
</html>
