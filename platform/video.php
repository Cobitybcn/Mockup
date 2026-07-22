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
    ],
];

function vds_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Video Lab - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="video_studio.css?v=17">
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
                            <span class="vds-catalog-kicker">Reference Catalog</span>
                            <h1 id="vds-catalog-title">Video Lab</h1>
                            <div class="vds-filters">
                                <label>
                                    <span class="vds-sr-only">Filter by artwork</span>
                                    <select data-artwork-filter><option value="">Filter by artwork</option></select>
                                </label>
                                <label>
                                    <span class="vds-sr-only">Filter by series</span>
                                    <select data-series-filter><option value="">Filter by series</option></select>
                                </label>
                            </div>
                        </div>
                        <div class="vds-project-controls">
                            <label class="vds-project-title">
                                <span>Project name</span>
                                <input type="text" maxlength="255" data-project-title aria-label="Current project name">
                            </label>
                            <fieldset class="vds-aspect-picker">
                                <legend>Format</legend>
                                <div class="vds-aspect-options" aria-label="Video format">
                                    <button type="button" data-project-aspect-ratio="9:16" aria-pressed="false" aria-label="Vertical format 9:16" title="Vertical · 9:16">
                                        <span class="vds-aspect-icon vds-aspect-icon--vertical" aria-hidden="true"></span>
                                        <span>9:16</span>
                                    </button>
                                    <button type="button" data-project-aspect-ratio="16:9" aria-pressed="false" aria-label="Horizontal format 16:9" title="Horizontal · 16:9">
                                        <span class="vds-aspect-icon vds-aspect-icon--horizontal" aria-hidden="true"></span>
                                        <span>16:9</span>
                                    </button>
                                </div>
                            </fieldset>
                            <div class="vds-project-actions">
                                <span class="vds-save-state" data-save-state>Saved</span>
                                <button class="vds-project-action vds-project-action--save" type="button" data-save-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h12l2 2v14H5zM8 4v6h8V4M8 20v-6h8v6"/></svg>
                                    <span>Save</span>
                                </button>
                                <button class="vds-project-action vds-project-action--new" type="button" data-new-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                    <span>New</span>
                                </button>
                                <button class="vds-project-action vds-project-action--delete" type="button" data-delete-project>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5"/></svg>
                                    <span>Delete</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="vds-catalog-rail-wrap">
                        <button class="vds-rail-arrow vds-rail-arrow--left" type="button" data-scroll-catalog="-1" aria-label="View previous references">‹</button>
                        <div class="vds-catalog-rail" data-catalog-rail aria-label="Available references"></div>
                        <button class="vds-rail-arrow vds-rail-arrow--right" type="button" data-scroll-catalog="1" aria-label="View more references">›</button>
                    </div>
                    <p class="vds-catalog-help" data-catalog-help>Drag an image or video to Start Frame or End Frame. You can also upload from your computer.</p>
                </section>

                <section class="vds-sequences" aria-labelledby="vds-sequences-title">
                    <header class="vds-sequences-head">
                        <div>
                            <span>Clips cortos ordenables</span>
                            <h2 id="vds-sequences-title">Secuencias</h2>
                            <p>Each board generates an independent clip and can continue the previous result.</p>
                        </div>
                        <button type="button" data-add-sequence><span aria-hidden="true">＋</span> Add sequence</button>
                    </header>
                    <div class="vds-board-grid" data-sequence-boards></div>
                </section>
            </div>

            <div class="vds-modal-backdrop" data-generation-modal hidden>
                <section class="vds-modal" role="dialog" aria-modal="true" aria-labelledby="vds-generation-title">
                    <span class="vds-modal-kicker">External generation</span>
                    <h2 id="vds-generation-title">Generate this sequence?</h2>
                    <div data-generation-summary></div>
                    <p class="vds-modal-warning">The provider will generate a new clip from the prompt and compatible references. A video placed in Start Frame contributes its final frame; when Start Frame is empty, the previous result may be used automatically.</p>
                    <div class="vds-modal-actions">
                        <button class="vds-secondary" type="button" data-cancel-generation>Cancel</button>
                        <button type="button" data-confirm-generation>Generate sequence</button>
                    </div>
                </section>
            </div>

            <div class="vds-toast" data-video-toast role="status" aria-live="polite"></div>
        </div>
    </main>
</div>
<script type="application/json" id="video-studio-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="video_studio.js?v=21"></script>
</body>
</html>
