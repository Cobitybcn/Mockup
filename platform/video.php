<?php
declare(strict_types=1);

require_once __DIR__ . '/app/Video/bootstrap.php';

$user = Auth::requireUser();
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
}

$payload = [
    'csrf' => VideoHttp::csrfToken(),
    'projects' => $projects,
    'studio' => $studio,
    'assets' => $service->library($userId),
    'capabilities' => $service->capabilities(),
    'endpoints' => [
        'api' => 'video_api.php',
        'generationStart' => 'video_generation_start.php',
        'generationStatus' => 'video_generation_status.php',
        'referenceUpload' => 'video_reference_upload.php',
    ],
];

function vds_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Video Studio - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="video_studio.css?v=11">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= vds_h($user['email']) ?></a></header>
        <div class="vds-page" data-video-studio data-project-id="<?= $projectId ?>">
            <section class="vds-empty-project" data-empty-project <?= $studio ? 'hidden' : '' ?>>
                <span class="vds-empty-icon" aria-hidden="true">▶</span>
                <h1>Crea tu primer proyecto de video</h1>
                <p>Organiza escenas cortas, añade referencias visuales y enlaza cada resultado con el anterior.</p>
                <button type="button" data-new-project>Crear proyecto</button>
            </section>

            <div data-editor <?= $studio ? '' : 'hidden' ?>>
                <section class="vds-catalog" aria-labelledby="vds-catalog-title">
                    <div class="vds-catalog-head">
                        <div class="vds-catalog-heading">
                            <h1 id="vds-catalog-title">Catálogo de referencias</h1>
                            <div class="vds-filters">
                                <label>
                                    <span class="vds-sr-only">Filtrar por obra</span>
                                    <select data-artwork-filter><option value="">Filtrar por obra</option></select>
                                </label>
                                <label>
                                    <span class="vds-sr-only">Filtrar por serie</span>
                                    <select data-series-filter><option value="">Filtrar por serie</option></select>
                                </label>
                            </div>
                        </div>
                        <div class="vds-project-controls">
                            <label class="vds-project-title">
                                <span>Nombre del video</span>
                                <input type="text" maxlength="255" data-project-title aria-label="Nombre del video actual">
                            </label>
                            <label class="vds-project-picker">
                                <span>Proyecto</span>
                                <select data-project-picker></select>
                            </label>
                            <div class="vds-project-actions">
                                <span class="vds-save-state" data-save-state>Guardado</span>
                                <button class="vds-secondary" type="button" data-save-project>Guardar</button>
                                <button class="vds-secondary" type="button" data-new-project>Nuevo proyecto</button>
                                <button class="vds-danger" type="button" data-delete-project>Eliminar</button>
                            </div>
                        </div>
                    </div>

                    <div class="vds-catalog-rail-wrap">
                        <button class="vds-rail-arrow vds-rail-arrow--left" type="button" data-scroll-catalog="-1" aria-label="Ver referencias anteriores">‹</button>
                        <div class="vds-catalog-rail" data-catalog-rail aria-label="Referencias disponibles"></div>
                        <button class="vds-rail-arrow vds-rail-arrow--right" type="button" data-scroll-catalog="1" aria-label="Ver más referencias">›</button>
                    </div>
                    <p class="vds-catalog-help" data-catalog-help>Arrastra una imagen o video hacia Start Frame o End Frame. También puedes cargar desde tu ordenador.</p>
                </section>

                <section class="vds-sequences" aria-labelledby="vds-sequences-title">
                    <header class="vds-sequences-head">
                        <div>
                            <span>Clips cortos ordenables</span>
                            <h2 id="vds-sequences-title">Secuencias</h2>
                            <p>Cada tablero genera un clip independiente y puede continuar el resultado anterior.</p>
                        </div>
                        <button type="button" data-add-sequence><span aria-hidden="true">＋</span> Agregar secuencia</button>
                    </header>
                    <div class="vds-board-grid" data-sequence-boards></div>
                </section>
            </div>

            <div class="vds-modal-backdrop" data-project-modal hidden>
                <section class="vds-modal" role="dialog" aria-modal="true" aria-labelledby="vds-new-project-title">
                    <span class="vds-modal-kicker">Nuevo video</span>
                    <h2 id="vds-new-project-title">Crear proyecto</h2>
                    <form data-create-project-form>
                        <label>
                            <span>Nombre del video</span>
                            <input name="title" maxlength="255" placeholder="Opcional: se generará desde la obra">
                            <small>Si no eliges una obra se guardará como “Video 01”. Podrás asociarla después.</small>
                        </label>
                        <label><span>Obra (opcional)</span><select name="artworkId"><option value="">Sin obra por ahora</option></select></label>
                        <label><span>Formato</span><select name="aspectRatio"><option value="9:16">9:16 · Vertical</option><option value="16:9">16:9 · Horizontal</option></select></label>
                        <div class="vds-modal-actions">
                            <button class="vds-secondary" type="button" data-close-project-modal>Cancelar</button>
                            <button type="submit">Crear con 3 secuencias</button>
                        </div>
                    </form>
                </section>
            </div>

            <div class="vds-modal-backdrop" data-generation-modal hidden>
                <section class="vds-modal" role="dialog" aria-modal="true" aria-labelledby="vds-generation-title">
                    <span class="vds-modal-kicker">Generación externa</span>
                    <h2 id="vds-generation-title">¿Generar esta secuencia?</h2>
                    <div data-generation-summary></div>
                    <p class="vds-modal-warning">El proveedor generará un clip nuevo con el prompt y las referencias compatibles. Un video colocado en Start Frame aporta su último fotograma; si Start Frame está vacío, puede usarse automáticamente el resultado anterior.</p>
                    <div class="vds-modal-actions">
                        <button class="vds-secondary" type="button" data-cancel-generation>Cancelar</button>
                        <button type="button" data-confirm-generation>Generar secuencia</button>
                    </div>
                </section>
            </div>

            <div class="vds-toast" data-video-toast role="status" aria-live="polite"></div>
        </div>
    </main>
</div>
<script type="application/json" id="video-studio-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="video_studio.js?v=14"></script>
</body>
</html>
