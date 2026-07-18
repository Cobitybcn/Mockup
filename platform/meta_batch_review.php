<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
FeatureAccess::requirePage($user, FeatureAccess::SOCIAL_MANAGE, 'Social Media');
Auth::start();
$userId = (int)$user['id'];
$batchId = max(0, (int)($_GET['id'] ?? 0));
$pdo = Database::connection();
$drafts = new MetaSocialDraftService($pdo);
$batches = new MetaBatchService($pdo, $drafts);
$metaIntegration = new MetaIntegrationService($pdo);
$instagramIntegration = new InstagramIntegrationService($pdo);
$error = (string)($_SESSION['meta_batch_error'] ?? '');
$notice = (string)($_SESSION['meta_batch_notice'] ?? '');
unset($_SESSION['meta_batch_error'], $_SESSION['meta_batch_notice']);
$batch = null;
$items = [];
$metaConnection = null;
$instagramConnection = null;
$blockers = [];
$linkedCampaign = (new SocialCampaignMetaBridge($pdo))->linkedCampaign($batchId, $userId);
$backHref = $linkedCampaign ? 'social_media_catalog.php?draft=' . (int)$linkedCampaign['id'] : 'mockups.php';
$backLabel = $linkedCampaign ? '← Social campaign' : '← Mockup Album';

try {
    $batch = $batches->batch($batchId, $userId);
    $items = $batches->items($batchId, $userId);
    $channels = $batches->channels($batchId, $userId);
    $hasFacebook = in_array('facebook', $channels, true);
    $hasInstagram = in_array('instagram', $channels, true);
    $metaConnection = $metaIntegration->connection($userId, (string)$batch['purpose']);
    $instagramConnection = $instagramIntegration->connection($userId, (string)$batch['purpose']);
    if ($hasFacebook) {
        try {
            $metaIntegration->assertPublishingReady($userId, (string)$batch['purpose'], ['facebook']);
        } catch (Throwable $e) {
            $blockers[] = $e->getMessage();
        }
        if (app_env('META_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
            $blockers[] = 'Live Facebook publishing is disabled by environment policy.';
        }
        if (app_env('META_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true') {
            $blockers[] = 'The public Facebook media route is disabled.';
        }
    }
    if ($hasInstagram) {
        try {
            $instagramIntegration->publishingContext($userId, (string)$batch['purpose']);
        } catch (Throwable $e) {
            $blockers[] = $e->getMessage();
        }
        if (app_env('INSTAGRAM_LIVE_PUBLISH_ENABLED', 'false') !== 'true') {
            $blockers[] = 'Live Instagram publishing is disabled by environment policy.';
        }
        if (app_env('INSTAGRAM_DRAFT_PUBLIC_MEDIA_ENABLED', 'false') !== 'true') {
            $blockers[] = 'The public Instagram media route is disabled.';
        }
    }
    $publicBase = rtrim(app_env('APP_PUBLIC_URL', ''), '/');
    if (!str_starts_with(strtolower($publicBase), 'https://')) {
        $blockers[] = 'APP_PUBLIC_URL must be a public HTTPS address.';
    }
    foreach ($items as $item) {
        foreach ($drafts->readiness($item) as $itemBlocker) {
            $blockers[] = ucfirst((string)$item['channel']) . ' draft #' . (int)$item['id'] . ': ' . $itemBlocker;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
$blockers = array_values(array_unique(array_filter($blockers)));
$_SESSION['meta_batch_csrf'] = bin2hex(random_bytes(24));
$_SESSION['meta_batch_publish_csrf'] = bin2hex(random_bytes(24));

function mbh(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Meta Batch Review</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .meta-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin:12px 0}.meta-summary article,.meta-card,.publish-panel{border:1px solid var(--line);background:var(--surface);border-radius:10px;padding:12px}.meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,460px));gap:14px;align-items:start}.meta-card canvas{width:100%;aspect-ratio:4/5;background:#111;display:block;cursor:grab;touch-action:none}.meta-card input[type=text],.meta-card input[type=url],.meta-card textarea{box-sizing:border-box;width:100%;padding:8px;font-size:12px}.meta-card textarea{resize:vertical;line-height:1.4}.meta-channel{display:inline-block;margin-bottom:8px;padding:4px 8px;border-radius:999px;background:var(--surface-soft);font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.meta-fields{display:grid;gap:8px;margin-top:9px}.meta-fields label>span{display:block;margin-bottom:3px;font-size:10px;font-weight:700;letter-spacing:.05em;text-transform:uppercase}.meta-status{display:flex;justify-content:space-between;gap:8px;margin-top:8px;font-size:11px}.meta-error{padding:8px;background:#fff1ef;color:#8a3028;font-size:11px}.meta-publish-success{display:flex;align-items:center;flex-wrap:wrap;gap:10px;margin:14px 0;padding:14px 16px;border:1px solid #9fc5a5;border-radius:10px;background:#eaf6ec;color:#244f2d;box-shadow:0 8px 24px rgba(36,79,45,.08)}.meta-publish-success strong{font-size:14px}.meta-publish-success a{margin-left:auto;color:#244f2d;font-weight:700;text-decoration:underline}.blocker-list{margin:8px 0;padding-left:20px}.published-card{opacity:.82}.publish-panel{margin-top:18px}.confirm-row{display:grid;gap:8px;max-width:520px}.confirm-row input[type=text]{padding:9px}.external-link{word-break:break-all}@media(max-width:760px){.meta-grid{grid-template-columns:1fr}.meta-publish-success a{margin-left:0;width:100%}}
    </style>
</head>
<body>
<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-area"><div class="workspace">
    <div class="workspace-header"><div><h1>Meta batch #<?= $batchId ?></h1><p><?= count($items) ?> Facebook / Instagram drafts<?php if ($linkedCampaign): ?> · <?= mbh($linkedCampaign['title']) ?><?php endif; ?></p></div><a class="button-link secondary" href="<?= mbh($backHref) ?>"><?= mbh($backLabel) ?></a></div>
    <?php if ($error !== ''): ?><div class="notice error"><?= mbh($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="notice success"><?= mbh($notice) ?></div><?php endif; ?>
    <?php if ($batch && (string)$batch['status'] === 'published'): ?>
        <div class="meta-publish-success" role="status" aria-live="polite">
            <strong>Published successfully on Meta.</strong>
            <span>The approved Facebook and Instagram publications are now live.</span>
            <?php foreach ($items as $publishedItem): if (trim((string)$publishedItem['external_url']) !== ''): ?>
                <a href="<?= mbh($publishedItem['external_url']) ?>" target="_blank" rel="noopener">Open publication</a>
            <?php break; endif; endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($batch): ?>
        <section class="meta-summary">
            <article><strong>Identity</strong><p><?= mbh($batch['purpose']) ?></p></article>
            <article><strong>Facebook Page</strong><p><?= mbh($metaConnection['page_name'] ?? 'Not connected') ?></p></article>
            <article><strong>Instagram</strong><p><?= ($instagramConnection['username'] ?? '') !== '' ? '@' . mbh($instagramConnection['username']) : 'Not connected' ?></p></article>
            <article><strong>Batch status</strong><p><?= mbh($batch['status']) ?></p></article>
        </section>
        <p><a class="button-link secondary" href="integrations/meta/">Manage Facebook connection</a> <a class="button-link secondary" href="integrations/instagram/">Manage Instagram connection</a></p>
        <div class="meta-grid">
        <?php foreach ($items as $item):
            $published = (string)$item['status'] === 'published';
            $hashtags = json_decode((string)$item['hashtags'], true);
            $hashtags = is_array($hashtags) ? implode(' ', $hashtags) : '';
        ?>
            <article class="meta-card <?= $published ? 'published-card' : '' ?>">
                <span class="meta-channel"><?= mbh($item['channel']) ?></span>
                <form class="meta-crop autosave">
                    <input type="hidden" name="id" value="<?= $batchId ?>">
                    <input type="hidden" name="draft_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= mbh($_SESSION['meta_batch_csrf']) ?>">
                    <input type="hidden" name="action" value="save_crop">
                    <input type="hidden" name="crop_x" value="<?= mbh($item['crop_x']) ?>">
                    <input type="hidden" name="crop_y" value="<?= mbh($item['crop_y']) ?>">
                    <canvas width="432" height="540" data-source="media.php?file=<?= rawurlencode(basename((string)$item['mockup_file'])) ?>"></canvas>
                    <label>Zoom <input name="crop_zoom" type="range" min="1" max="3" step=".01" value="<?= mbh($item['crop_zoom']) ?>" <?= $published ? 'disabled' : '' ?>></label>
                </form>
                <form class="meta-fields autosave">
                    <input type="hidden" name="id" value="<?= $batchId ?>">
                    <input type="hidden" name="draft_id" value="<?= (int)$item['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= mbh($_SESSION['meta_batch_csrf']) ?>">
                    <input type="hidden" name="action" value="save_content">
                    <label><span><?= (string)$item['channel'] === 'facebook' ? 'Headline' : 'Hook' ?></span><input type="text" name="title" maxlength="255" value="<?= mbh($item['title']) ?>" <?= $published ? 'disabled' : '' ?>></label>
                    <label><span>Publication copy</span><textarea name="description" rows="6" maxlength="5000" required <?= $published ? 'disabled' : '' ?>><?= mbh($item['description']) ?></textarea></label>
                    <label><span>Hashtags</span><textarea name="hashtags" rows="2" <?= $published ? 'disabled' : '' ?>><?= mbh($hashtags) ?></textarea></label>
                    <label><span>Alt text</span><textarea name="alt_text" rows="3" maxlength="1000" <?= $published ? 'disabled' : '' ?>><?= mbh($item['alt_text']) ?></textarea></label>
                    <label><span>Destination HTTPS · optional</span><input type="url" name="destination_url" value="<?= mbh($item['destination_url']) ?>" <?= $published ? 'disabled' : '' ?>></label>
                </form>
                <div class="meta-status"><span>Status: <?= mbh($item['status']) ?></span><span data-save-status><?= $published ? 'Published' : 'Saved automatically' ?></span></div>
                <?php if ((string)$item['external_url'] !== ''): ?><p class="external-link"><a href="<?= mbh($item['external_url']) ?>" target="_blank" rel="noopener">Open publication</a></p><?php endif; ?>
                <?php if ((string)$item['error'] !== ''): ?><p class="meta-error"><?= mbh($item['error']) ?></p><?php endif; ?>
                <?php if ((string)$item['status'] === 'needs_verification'): ?>
                    <form method="post" action="meta_batch_resolve.php" class="meta-fields">
                        <input type="hidden" name="id" value="<?= $batchId ?>">
                        <input type="hidden" name="draft_id" value="<?= (int)$item['id'] ?>">
                        <input type="hidden" name="csrf" value="<?= mbh($_SESSION['meta_batch_csrf']) ?>">
                        <label><span>Manual result</span><select name="decision"><option value="retry">No post exists · allow retry</option><option value="published">A post exists · record it</option></select></label>
                        <label><span>External ID · required if published</span><input type="text" name="external_id"></label>
                        <label><span>External URL · optional</span><input type="url" name="external_url"></label>
                        <label><span>Type VERIFICADO</span><input type="text" name="confirmation_text" autocomplete="off" required></label>
                        <button class="button-link secondary">Record manual verification</button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
        <section class="publish-panel">
            <h2>Final approval</h2>
            <?php if ($blockers): ?>
                <p>This batch cannot publish yet:</p><ul class="blocker-list"><?php foreach ($blockers as $blocker): ?><li><?= mbh($blocker) ?></li><?php endforeach; ?></ul>
            <?php else: ?><p>All technical checks passed. Publishing creates real public posts.</p><?php endif; ?>
            <form id="publish-meta-batch" method="post" action="meta_batch_publish.php" class="confirm-row">
                <input type="hidden" name="id" value="<?= $batchId ?>">
                <input type="hidden" name="csrf" value="<?= mbh($_SESSION['meta_batch_publish_csrf']) ?>">
                <label><input type="checkbox" name="confirm" value="yes" required> I reviewed every Facebook and Instagram item.</label>
                <label>Type <strong>PUBLICAR</strong><input type="text" name="confirmation_text" autocomplete="off" required></label>
                <button class="button-link primary" <?= $blockers ? 'disabled' : '' ?>>Publish approved Meta items</button>
            </form>
        </section>
    <?php endif; ?>
</div></main></div>
<script>
const metaPending=new Set();
function saveMeta(form){const status=form.closest('.meta-card')?.querySelector('[data-save-status]');if(status)status.textContent='Saving…';const request=fetch('meta_batch_autosave.php',{method:'POST',body:new FormData(form),credentials:'same-origin'}).then(async response=>{const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Could not save');if(status)status.textContent='Saved automatically'}).catch(error=>{if(status)status.textContent=error.message;throw error}).finally(()=>metaPending.delete(request));metaPending.add(request);return request}
document.querySelectorAll('.meta-fields input:not([type=hidden]),.meta-fields textarea').forEach(field=>field.addEventListener('change',()=>saveMeta(field.form)));
document.querySelectorAll('.meta-crop').forEach(form=>{const canvas=form.querySelector('canvas'),x=form.querySelector('[name=crop_x]'),y=form.querySelector('[name=crop_y]'),zoom=form.querySelector('[name=crop_zoom]'),ctx=canvas.getContext('2d'),img=new Image();let drag=false,lastX=0,lastY=0;const draw=()=>{if(!img.naturalWidth)return;const scale=Math.max(canvas.width/img.naturalWidth,canvas.height/img.naturalHeight)*(+zoom.value),cw=canvas.width/scale,ch=canvas.height/scale;ctx.drawImage(img,(img.naturalWidth-cw)*(+x.value),(img.naturalHeight-ch)*(+y.value),cw,ch,0,0,canvas.width,canvas.height)};img.onload=draw;img.src=canvas.dataset.source;if(zoom.disabled)return;zoom.oninput=draw;zoom.onchange=()=>saveMeta(form);canvas.onpointerdown=event=>{drag=true;lastX=event.clientX;lastY=event.clientY;canvas.setPointerCapture(event.pointerId)};canvas.onpointermove=event=>{if(!drag)return;x.value=Math.max(0,Math.min(1,+x.value-(event.clientX-lastX)/canvas.clientWidth));y.value=Math.max(0,Math.min(1,+y.value-(event.clientY-lastY)/canvas.clientHeight));lastX=event.clientX;lastY=event.clientY;draw()};canvas.onpointerup=()=>{if(drag){drag=false;saveMeta(form)}}});
document.getElementById('publish-meta-batch')?.addEventListener('submit',async event=>{if(metaPending.size){event.preventDefault();const form=event.currentTarget;try{await Promise.all([...metaPending]);form.submit()}catch(error){alert('Correct the fields marked with an error before publishing.')}}});
</script>
</body></html>
