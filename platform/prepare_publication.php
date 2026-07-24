<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$isAdmin = Auth::isAdmin($user);
$pdo = Database::connection();
$service = new PublicationService($pdo);
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));
$sheetId = max(0, (int)($_GET['sheet_id'] ?? 0));

try {
    if ($id <= 0 && $sheetId > 0) {
        $id = $service->createForSheet($sheetId, $userId);
        header('Location: prepare_publication.php?id=' . $id);
        exit;
    }
    if ($id <= 0) throw new RuntimeException('The publication is missing.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? 'save');
        if (in_array($action, ['website_dry_run','website_publish'], true) && !$isAdmin) {
            throw new RuntimeException('This private website integration is available to administrators only.');
        }
        $service->save($id, $userId, [
            'title' => $_POST['title'] ?? '', 'description' => $_POST['description'] ?? '',
            'short_description' => $_POST['short_description'] ?? '', 'language' => 'en',
            'objective' => $_POST['objective'] ?? 'portfolio', 'cta_label' => $_POST['cta_label'] ?? '',
            'cta_url' => $_POST['cta_url'] ?? '', 'visibility' => $_POST['visibility'] ?? 'private',
            'publish' => $action === 'publish', 'unpublish' => $action === 'unpublish',
        ], (array)($_POST['mockup_ids'] ?? []));
        if ($action === 'website_dry_run') {
            $sync = new ArtworkWebsiteDryRunService($pdo);
            $result = $sync->send($sync->buildPayload($id, $userId));
            $_SESSION['publication_notice'] = 'Website draft validated. Draft ID: ' . (string)$result['draft_id'];
            header('Location: prepare_publication.php?id=' . $id);
            exit;
        }
        if ($action === 'website_publish') {
            $sync = new ArtworkWebsiteDryRunService($pdo);
            $validated = $sync->send($sync->buildPayload($id, $userId));
            $published = $sync->publishValidatedDraft((string)$validated['draft_id']);
            $_SESSION['publication_notice'] = 'Published on mauriziovalch.com: ' . (string)$published['website_url'];
            header('Location: prepare_publication.php?id=' . $id);
            exit;
        }
        $_SESSION['publication_notice'] = $action === 'publish' ? 'Landing page published.' : ($action === 'unpublish' ? 'Landing page unpublished.' : 'Draft saved.');
        header('Location: prepare_publication.php?id=' . $id);
        exit;
    }
    $publication = $service->get($id, $userId);
    $stmt = $pdo->prepare('SELECT * FROM mockup_sheets WHERE user_id=? AND artwork_sheet_id=? ORDER BY id');
    $stmt->execute([$userId, (int)$publication['artwork_sheet_id']]);
    $mockups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { http_response_code(400); exit(htmlspecialchars($e->getMessage())); }

$selected = array_column($publication['items'], 'mockup_sheet_id');
$requestedMockupId = max(0, (int)($_GET['mockup_id'] ?? 0));
if ($requestedMockupId > 0) {
    foreach ($mockups as $candidate) {
        if ((int)$candidate['id'] === $requestedMockupId) {
            $selected[] = $requestedMockupId;
            break;
        }
    }
}
$notice = (string)($_SESSION['publication_notice'] ?? ''); unset($_SESSION['publication_notice']);
function hp($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Publish artwork</title><link rel="stylesheet" href="style.css"><style>
.publish-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:24px}.publish-card{border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);padding:18px}.publish-card label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;color:var(--muted);margin:12px 0 4px}.publish-card input,.publish-card textarea,.publish-card select{width:100%;box-sizing:border-box;padding:8px;border:1px solid var(--line);border-radius:6px;background:var(--surface-soft)}.publish-card textarea{min-height:90px}.mockup-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px}.mockup-choice{border:1px solid var(--line);border-radius:8px;overflow:hidden;background:var(--surface);position:relative}.mockup-choice:has(input:checked){outline:3px solid var(--accent)}.mockup-choice img{width:100%;aspect-ratio:4/5;object-fit:cover}.mockup-choice input{position:absolute;top:8px;left:8px;width:20px;height:20px}.mockup-copy{padding:8px;font-size:11px}.channels{display:grid;gap:8px}.channel{padding:10px;border:1px solid var(--line);border-radius:6px}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}@media(max-width:900px){.publish-grid{grid-template-columns:1fr}}
</style></head><body><div class="app-shell"><?php include __DIR__.'/sidebar.php'; ?><main class="main-area"><header class="app-header"><a class="user-chip" href="account.php"><?=hp($user['email'])?></a></header><div class="workspace">
<div class="workspace-header"><div><h1>Publish artwork</h1><p>Use the existing artwork analysis and select the mockups that will support its presentation.</p></div><a class="button-link secondary" href="root_album.php">← Artworks</a></div>
<?php if($notice):?><div class="notice success"><?=hp($notice)?></div><?php endif;?>
<form method="post"><input type="hidden" name="id" value="<?=$id?>"><div class="publish-grid"><section>
<div class="publish-card"><h2>Artwork Editorial Core</h2><h3><?=hp($publication['title'] ?: 'Untitled artwork')?></h3><p><?=nl2br(hp($publication['description'] ?: $publication['short_description']))?></p><small>This content comes from the artist profile and the existing artwork analysis.</small><input type="hidden" name="title" value="<?=hp($publication['title'])?>"><input type="hidden" name="description" value="<?=hp($publication['description'])?>"><input type="hidden" name="short_description" value="<?=hp($publication['short_description'])?>"><input type="hidden" name="language" value="<?=hp($publication['language'])?>"><input type="hidden" name="objective" value="<?=hp($publication['objective'])?>"></div>
<h2>Selected mockups</h2><p>Selection order is preserved by ID in this first version. The artwork can have as many views as needed.</p><div class="mockup-picker"><?php foreach($mockups as $m):?><label class="mockup-choice"><input type="checkbox" name="mockup_ids[]" value="<?=(int)$m['id']?>" <?=in_array((int)$m['id'],array_map('intval',$selected),true)?'checked':''?>><img src="media.php?file=<?=rawurlencode(basename((string)$m['mockup_file']))?>" alt="<?=hp($m['alt_text'] ?: $m['title'])?>"><div class="mockup-copy"><strong><?=hp($m['title'] ?: 'Mockup #'.$m['id'])?></strong><br><?=hp(mb_substr((string)$m['description'],0,90))?></div></label><?php endforeach;?></div>
</section><aside><div class="publish-card"><h2>Website</h2><label>CTA</label><input name="cta_label" value="<?=hp($publication['cta_label'])?>"><label>URL del CTA</label><input type="url" name="cta_url" value="<?=hp($publication['cta_url'])?>"><div class="actions"><?php if($isAdmin):?><button class="button-link primary" name="action" value="website_publish">Publish to mauriziovalch.com</button><?php else:?><button class="button-link primary" name="action" value="publish">Publish artwork</button><?php endif;?></div><small>One action validates the artwork, copies its selected images and creates or updates its catalogue page. content.json is not modified.</small><input type="hidden" name="visibility" value="public"></div>
<div class="publish-card" style="margin-top:14px"><h2>Prepared variants</h2><div class="channels"><?php foreach($publication['variants'] as $v):?><div class="channel"><strong><?=hp(ucwords(str_replace('_',' / ',$v['channel'])))?></strong><br><small><?=hp($v['format'])?> · <?=hp($v['status'])?></small><?php if($v['channel']==='pinterest'):?><p style="font-size:11px">Choose a board and confirm each Pin before publishing.</p><?php if($publication['status']==='published'&&!empty($publication['items'])):?><a class="button-link secondary" href="pinterest_publish.php?id=<?=$id?>">Prepare Pin</a><?php else:?><small>First publish the landing page and select at least one mockup.</small><?php endif;?><?php endif;?></div><?php endforeach;?></div></div></aside></div></form>
</div></main></div></body></html>
