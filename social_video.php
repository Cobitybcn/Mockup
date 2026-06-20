<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

ob_start();
register_shutdown_function(function (): void {
    $html = ob_get_clean();
    global $artworkId, $conceptJson, $workflow;
    if (!is_string($html) || !isset($artworkId)) { return; }
    $enabled = ProviderSettings::socialVideoVeoEnabled();
    $disabled = (!$enabled || trim((string)($conceptJson ?? '')) === '') ? ' disabled' : '';
    $button = '<div class="field-block"><form method="post" action="social_video_run.php"><input type="hidden" name="id" value="' . (int)$artworkId . '"><button type="submit"' . $disabled . '>Generate Full Video</button></form></div>';
    $videoUrl = (string)($workflow['video_url'] ?? '');
    if ($videoUrl !== '') {
        $button .= '<div class="field-block"><label>Generated video</label><video controls preload="metadata" style="width:100%;max-height:500px" src="media.php?file=' . rawurlencode($videoUrl) . '"></video></div>';
    }
    echo str_replace('<aside class="social-panel">', '<aside class="social-panel">' . $button, $html);
});

$user = Auth::requireUser();
$artworkId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$pdo = Database::connection();
$artworkStmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id AND user_id = :user_id LIMIT 1');
$artworkStmt->execute(['id' => $artworkId, 'user_id' => (int)$user['id']]);
$artwork = $artworkStmt->fetch();
if (!$artwork) { http_response_code(404); exit('Artwork not found.'); }

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function decode_social_json(string $value): array { $data = json_decode($value, true); return is_array($data) ? $data : []; }
function encode_social_json(array $value): string { return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'; }
function workflow_save(PDO $pdo, int $userId, int $artworkId, array $values): void {
    $find = $pdo->prepare('SELECT id FROM social_video_workflows WHERE artwork_id = :artwork_id LIMIT 1'); $find->execute(['artwork_id' => $artworkId]); $id = (int)$find->fetchColumn(); $now = date('c');
    $base = ['setup_suggestion_json' => (string)($values['setup_suggestion_json'] ?? ''), 'setup_edited_json' => (string)($values['setup_edited_json'] ?? ''), 'final_concept_json' => (string)($values['final_concept_json'] ?? ''), 'status' => (string)($values['status'] ?? 'not_started'), 'video_status' => (string)($values['video_status'] ?? 'not_started'), 'video_url' => (string)($values['video_url'] ?? ''), 'error' => (string)($values['error'] ?? ''), 'updated_at' => $now];
    if ($id) { $base['id'] = $id; $pdo->prepare('UPDATE social_video_workflows SET setup_suggestion_json=:setup_suggestion_json, setup_edited_json=:setup_edited_json, final_concept_json=:final_concept_json, status=:status, video_status=:video_status, video_url=:video_url, error=:error, updated_at=:updated_at WHERE id=:id')->execute($base); return; }
    $base += ['user_id' => $userId, 'artwork_id' => $artworkId, 'created_at' => $now];
    $pdo->prepare('INSERT INTO social_video_workflows (user_id,artwork_id,setup_suggestion_json,setup_edited_json,final_concept_json,status,video_status,video_url,error,created_at,updated_at) VALUES (:user_id,:artwork_id,:setup_suggestion_json,:setup_edited_json,:final_concept_json,:status,:video_status,:video_url,:error,:created_at,:updated_at)')->execute($base);
}
function setup_from_request(array $post, array $fallback): array {
    $references = decode_social_json((string)($post['scene_references_json'] ?? '[]'));
    $newReferences = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($post['new_image_references'] ?? '')) ?: [])));
    return [
        'primary_artwork' => ['id' => (string)($fallback['primary_artwork']['id'] ?? ''), 'file' => trim((string)($post['primary_artwork'] ?? $fallback['primary_artwork']['file'] ?? '')), 'role' => 'Primary visual source', 'usage_notes' => trim((string)($post['primary_usage_notes'] ?? $fallback['primary_artwork']['usage_notes'] ?? ''))],
        'scene_references' => is_array($references) ? array_values($references) : [],
        'narrative_thread' => ['tone' => trim((string)($post['narrative_tone'] ?? '')), 'selected_title_index' => (int)($post['selected_title_index'] ?? 0), 'selected_title' => trim((string)($post['selected_title'] ?? '')), 'selected_description' => trim((string)($post['selected_description'] ?? '')), 'concept' => trim((string)($post['narrative_concept'] ?? ''))],
        'selection_justification' => trim((string)($post['selection_justification'] ?? '')),
        'new_image_references' => $newReferences,
    ];
}

$analysisStmt = $pdo->prepare('SELECT analysis_json FROM artwork_analysis WHERE artwork_id=:artwork_id ORDER BY id DESC LIMIT 1'); $analysisStmt->execute(['artwork_id' => $artworkId]); $analysis = decode_social_json((string)$analysisStmt->fetchColumn());
$mockupStmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id=:user_id AND artwork_file=:artwork_file ORDER BY id DESC'); $mockupStmt->execute(['user_id' => (int)$user['id'], 'artwork_file' => (string)$artwork['root_file']]); $mockups = $mockupStmt->fetchAll();
if ($mockups === []) {
    $jobMockups = $pdo->prepare("SELECT mockup_id AS id, mockup_file, context_id, '' AS selector_state_json FROM mockup_generation_jobs WHERE user_id=:user_id AND artwork_id=:artwork_id AND status='completed' AND mockup_file IS NOT NULL AND mockup_file != '' ORDER BY id DESC");
    $jobMockups->execute(['user_id' => (int)$user['id'], 'artwork_id' => $artworkId]);
    $mockups = $jobMockups->fetchAll();
}
$profile = ArtistProfile::findForUser((int)$user['id']);
$workflowStmt = $pdo->prepare('SELECT * FROM social_video_workflows WHERE artwork_id=:artwork_id LIMIT 1'); $workflowStmt->execute(['artwork_id' => $artworkId]); $workflow = $workflowStmt->fetch() ?: [];
$notice = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $service = new SocialVideoService(); $action = (string)($_POST['action'] ?? '');
        if ($action === 'propose') { $setup = $service->propose($artwork, $analysis, $mockups, $profile); workflow_save($pdo, (int)$user['id'], $artworkId, ['setup_suggestion_json' => encode_social_json($setup), 'setup_edited_json' => encode_social_json($setup), 'status' => 'setup_proposed']); $notice = 'Setup proposal generated. Every suggestion remains editable.'; }
        if ($action === 'save_setup' || $action === 'concept') { $previous = decode_social_json((string)($workflow['setup_edited_json'] ?? $workflow['setup_suggestion_json'] ?? '')); $setup = setup_from_request($_POST, $previous); if ($action === 'concept') { $concept = $service->concept($setup, $artwork, $analysis, $profile); workflow_save($pdo, (int)$user['id'], $artworkId, ['setup_suggestion_json' => (string)($workflow['setup_suggestion_json'] ?? ''), 'setup_edited_json' => encode_social_json($setup), 'final_concept_json' => encode_social_json($concept), 'status' => 'concept_generated']); $notice = 'Final Video Concept JSON generated from the edited setup.'; } else { workflow_save($pdo, (int)$user['id'], $artworkId, ['setup_suggestion_json' => (string)($workflow['setup_suggestion_json'] ?? ''), 'setup_edited_json' => encode_social_json($setup), 'final_concept_json' => (string)($workflow['final_concept_json'] ?? ''), 'status' => 'user_edited']); $notice = 'Edited setup saved.'; } }
        if ($action === 'create_video_job') { $concept = (string)($workflow['final_concept_json'] ?? ''); if ($concept === '' || !ProviderSettings::socialVideoVeoEnabled()) { throw new RuntimeException('Generate a concept and enable Vertex/Veo before creating a video job.'); } $now = date('c'); $pdo->prepare('INSERT INTO social_video_jobs (user_id,artwork_id,workflow_id,provider,model,concept_json,status,created_at,updated_at) VALUES (:user_id,:artwork_id,:workflow_id,:provider,:model,:concept_json,:status,:created_at,:updated_at)')->execute(['user_id'=>(int)$user['id'],'artwork_id'=>$artworkId,'workflow_id'=>(int)$workflow['id'],'provider'=>'vertex_veo','model'=>ProviderSettings::socialVideoVeoModel(),'concept_json'=>$concept,'status'=>'created','created_at'=>$now,'updated_at'=>$now]); workflow_save($pdo, (int)$user['id'], $artworkId, ['setup_suggestion_json'=>(string)$workflow['setup_suggestion_json'],'setup_edited_json'=>(string)$workflow['setup_edited_json'],'final_concept_json'=>$concept,'status'=>'video_job_created','video_status'=>'created']); $notice = 'Video job created in the isolated Social Video queue.'; }
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $workflowStmt->execute(['artwork_id' => $artworkId]); $workflow = $workflowStmt->fetch() ?: [];
}
$setup = decode_social_json((string)($workflow['setup_edited_json'] ?? $workflow['setup_suggestion_json'] ?? '')); $primary = is_array($setup['primary_artwork'] ?? null) ? $setup['primary_artwork'] : []; $narrative = is_array($setup['narrative_thread'] ?? null) ? $setup['narrative_thread'] : []; $references = is_array($setup['scene_references'] ?? null) ? $setup['scene_references'] : []; $newReferences = is_array($setup['new_image_references'] ?? null) ? $setup['new_image_references'] : [];
$conceptJson = (string)($workflow['final_concept_json'] ?? ''); $status = (string)($workflow['status'] ?? 'not_started');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Social Video - The Artwork Curator</title><link rel="stylesheet" href="style.css"><style>.social-layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(320px,.7fr);gap:24px}.social-panel{padding:22px;border:1px solid var(--line);background:var(--surface);border-radius:var(--radius)}.social-panel h2{margin-top:0}.field-block{margin-top:16px}.field-block textarea{min-height:92px}.field-block input,.field-block textarea{width:100%;box-sizing:border-box}.status-pill{border:1px solid var(--accent);color:var(--accent);padding:5px 9px;text-transform:uppercase;font-size:12px}.json-output{max-height:540px;overflow:auto;white-space:pre-wrap;background:var(--surface-soft);padding:14px;font:12px/1.45 Consolas,monospace}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}@media(max-width:900px){.social-layout{grid-template-columns:1fr}}</style></head><body><div class="app-shell"><?php include __DIR__ . '/sidebar.php'; ?><main class="main-area"><header class="app-header"><a class="user-chip" href="account.php"><?= h($user['email']) ?></a></header><div class="alert-strip">Independent Social Video planning. It never uses mockup workers or their queue.</div><div class="workspace"><div class="workspace-header"><div><h1>Social Video (beta)</h1><p>AI suggests a sequence; you decide the final setup.</p></div><span class="status-pill"><?= h(str_replace('_', ' ', $status)) ?></span></div><?php if ($notice): ?><div class="notice"><?= h($notice) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?><div class="social-layout"><section class="social-panel"><h2>1. Setup Proposal</h2><p>Selector suggestions are editable and never lock your choices.</p><form method="post"><input type="hidden" name="id" value="<?= $artworkId ?>"><button name="action" value="propose">Generate or Regenerate Setup Proposal</button></form><form method="post"><input type="hidden" name="id" value="<?= $artworkId ?>"><div class="field-block"><label>Artwork as primary source</label><input name="primary_artwork" value="<?= h($primary['file'] ?? $artwork['root_file']) ?>"><textarea name="primary_usage_notes"><?= h($primary['usage_notes'] ?? '') ?></textarea></div><div class="field-block"><label>Scene references from selected mockups</label><small>Reorder, remove, or replace entries in this editable JSON list.</small><textarea name="scene_references_json"><?= h(encode_social_json($references)) ?></textarea></div><div class="field-block"><label>Narrative / text thread</label><input name="selected_title_index" type="number" min="0" value="<?= (int)($narrative['selected_title_index'] ?? 0) ?>"><input name="selected_title" value="<?= h($narrative['selected_title'] ?? '') ?>"><textarea name="selected_description"><?= h($narrative['selected_description'] ?? '') ?></textarea><input name="narrative_tone" value="<?= h($narrative['tone'] ?? '') ?>"><textarea name="narrative_concept"><?= h($narrative['concept'] ?? '') ?></textarea></div><div class="field-block"><label>Selection justification</label><textarea name="selection_justification"><?= h($setup['selection_justification'] ?? '') ?></textarea></div><div class="field-block"><label>New image references</label><small>Optional. One URL or asset reference per line.</small><textarea name="new_image_references"><?= h(implode("\n", $newReferences)) ?></textarea></div><div class="actions"><button class="secondary" name="action" value="save_setup">Save Edited Setup</button><button name="action" value="concept">Generate Final Video Concept JSON</button></div></form></section><aside class="social-panel"><h2>2. Video Concept / Generation</h2><p><strong>Veo:</strong> <?= ProviderSettings::socialVideoVeoEnabled() ? 'Enabled' : 'Disabled' ?></p><p>When disabled, the multi-segment concept remains fully available without interrupting this workflow.</p><?php if ($conceptJson !== ''): ?><h3>Final Video Concept JSON</h3><pre class="json-output"><?= h($conceptJson) ?></pre><?php endif; ?></aside></div></div></main></div></body></html>
