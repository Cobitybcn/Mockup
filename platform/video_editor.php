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
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Video Editor - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="ui-catalog.css">
    <link rel="stylesheet" href="video_editor.css?v=1">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= editor_h($user['email']) ?></a></header>
        <div class="alert-strip">Each edit creates a new version. The original video is preserved.</div>
        <div class="ved-page">
            <header class="catalog-heading ved-heading">
                <div><span>Standalone tool</span><h1>Video Editor</h1><p>Refine a clip with Gemini Omni.</p></div>
                <a class="button-link secondary" href="videos.php">Back to Videos</a>
            </header>

            <?php if (!$source): ?>
                <section class="catalog-panel ved-empty">
                    <span aria-hidden="true">▶</span><h2>Select a video</h2>
                    <p>In Videos, choose “Edit” on a generated clip or final video.</p>
                    <a class="button-link" href="videos.php">Choose video</a>
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
                        <label class="ved-prompt"><span>What would you like to change?</span><textarea name="prompt" required placeholder="Describe only the change. Omni will preserve everything else."></textarea></label>
                        <details class="ved-references">
                            <summary><span>Reference images <small>Optional · up to 10</small></span><b>+</b></summary>
                            <div>
                                <label class="ved-file"><input type="file" name="images[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple data-editor-images><span>＋ Add from computer</span><small>You can refer to them as Image 1, Image 2… in their displayed order.</small></label>
                                <div class="ved-image-list" data-editor-image-list></div>
                            </div>
                        </details>
                        <?php if (!$editable): ?><p class="ved-warning">This video is longer than 10 seconds. Omni cannot edit it in full; split it into shorter clips.</p><?php endif; ?>
                        <p class="ved-error" data-editor-error role="alert" hidden></p>
                        <footer><span data-editor-state><?= $editable ? 'Ready to edit' : 'Editing unavailable' ?></span><button type="submit"<?= $editable ? '' : ' disabled' ?>>Create new version</button></footer>
                    </form>
                </section>
                <section class="catalog-panel ved-result" data-editor-result hidden>
                    <div><span>New version</span><h2>Edited result</h2></div>
                    <video controls playsinline data-editor-result-video></video>
                    <footer><a class="button-link secondary" data-editor-download>Download MP4</a><a class="button-link" href="videos.php">View in Videos</a></footer>
                </section>
            <?php endif; ?>
        </div>
    </main>
</div>
<?php if ($source): ?><script src="video_editor.js?v=2"></script><?php endif; ?>
</body>
</html>
