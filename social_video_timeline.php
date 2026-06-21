<?php
declare(strict_types=1);

$user = Auth::requireUser();
$artworkId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$pdo = Database::connection();
$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id=:id AND user_id=:user_id LIMIT 1');
$stmt->execute(['id'=>$artworkId,'user_id'=>(int)$user['id']]);
$artwork = $stmt->fetch();
if (!$artwork) { http_response_code(404); exit('Artwork not found.'); }

function svt_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function svt_json(string $v): array { $r=json_decode($v,true); return is_array($r)?$r:[]; }
function svt_encode(array $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}'; }
function svt_default_timeline(string $origin=''): array {
    $roles=['Principio','Desarrollo','Desarrollo','Desarrollo','Fin']; $items=[];
    foreach ($roles as $i=>$role) $items[]=['position'=>$i+1,'role'=>$role,'image'=>$i===0?$origin:'','narrative_text'=>'','reference_image'=>'','reference_text'=>''];
    return ['duration_seconds'=>25,'tone_value'=>50,'milestones'=>$items];
}
function svt_save(PDO $pdo,int $userId,int $artworkId,array $timeline,string $status='timeline_edited',string $concept=''): void {
    $find=$pdo->prepare('SELECT id,final_concept_json FROM social_video_workflows WHERE artwork_id=:id LIMIT 1'); $find->execute(['id'=>$artworkId]); $old=$find->fetch(); $now=date('c');
    $data=['setup_suggestion_json'=>svt_encode(['timeline'=>$timeline]),'setup_edited_json'=>svt_encode(['timeline'=>$timeline]),'final_concept_json'=>$concept!==''?$concept:(string)($old['final_concept_json']??''),'status'=>$status,'video_status'=>'not_started','video_url'=>'','error'=>'','updated_at'=>$now];
    if ($old) { $data['id']=$old['id']; $pdo->prepare('UPDATE social_video_workflows SET setup_suggestion_json=:setup_suggestion_json,setup_edited_json=:setup_edited_json,final_concept_json=:final_concept_json,status=:status,video_status=:video_status,video_url=:video_url,error=:error,updated_at=:updated_at WHERE id=:id')->execute($data); return; }
    $data += ['user_id'=>$userId,'artwork_id'=>$artworkId,'created_at'=>$now];
    $pdo->prepare('INSERT INTO social_video_workflows (user_id,artwork_id,setup_suggestion_json,setup_edited_json,final_concept_json,status,video_status,video_url,error,created_at,updated_at) VALUES (:user_id,:artwork_id,:setup_suggestion_json,:setup_edited_json,:final_concept_json,:status,:video_status,:video_url,:error,:created_at,:updated_at)')->execute($data);
}
function svt_upload(array $file,string $kind,int $userId,int $artworkId): string {
    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) return '';
    if (($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new RuntimeException('No se pudo subir un archivo de la timeline.');
    if ((int)($file['size']??0)>20*1024*1024) throw new RuntimeException('Cada archivo de la timeline tiene un máximo de 20 MB.');
    $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION)); $allowed=$kind==='text'?['txt','pdf']:['jpg','jpeg','png','webp'];
    if (!in_array($ext,$allowed,true)) throw new RuntimeException('El tipo de archivo no está permitido para este campo.');
    $dir=RESULTS_DIR.DIRECTORY_SEPARATOR.'social-video-timeline'; if (!is_dir($dir)) mkdir($dir,0775,true);
    $name=$kind.'_u'.$userId.'_a'.$artworkId.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
    if (!move_uploaded_file((string)$file['tmp_name'],$dir.DIRECTORY_SEPARATOR.$name)) throw new RuntimeException('No se pudo guardar el archivo.');
    return 'social-video-timeline/'.$name;
}
function svt_file_at(array $files, string $name, int $index): array {
    return ['name'=>$files[$name]['name'][$index]??'','type'=>$files[$name]['type'][$index]??'','tmp_name'=>$files[$name]['tmp_name'][$index]??'','error'=>$files[$name]['error'][$index]??UPLOAD_ERR_NO_FILE,'size'=>$files[$name]['size'][$index]??0];
}

$mockupStmt=$pdo->prepare('SELECT id,mockup_file FROM mockups WHERE user_id=:user_id AND artwork_file=:file AND mockup_file != "" ORDER BY id DESC');
$mockupStmt->execute(['user_id'=>(int)$user['id'],'file'=>(string)$artwork['root_file']]); $mockups=$mockupStmt->fetchAll();
$wf=$pdo->prepare('SELECT * FROM social_video_workflows WHERE artwork_id=:id LIMIT 1'); $wf->execute(['id'=>$artworkId]); $workflow=$wf->fetch()?:[];
$saved=svt_json((string)($workflow['setup_edited_json']??'')); $timeline=is_array($saved['timeline']??null)?$saved['timeline']:svt_default_timeline(basename((string)($_GET['mockup']??'')));
$timeline['milestones']=array_values($timeline['milestones']??[]); if (count($timeline['milestones'])!==5) $timeline=svt_default_timeline(basename((string)($_GET['mockup']??'')));
$notice=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') try {
    $timeline=['duration_seconds'=>max(16,min(60,(int)($_POST['duration_seconds']??25))),'tone_value'=>max(0,min(100,(int)($_POST['tone_value']??50))),'milestones'=>[]];
    foreach (range(0,4) as $i) { $old=(array)($saved['timeline']['milestones'][$i]??[]); $image=trim((string)($_POST['milestone_image'][$i]??$old['image']??'')); $own=svt_upload(svt_file_at($_FILES,'milestone_image_upload',$i),'image',(int)$user['id'],$artworkId); if($own!=='')$image=$own; $ref=svt_upload(svt_file_at($_FILES,'reference_image',$i),'image',(int)$user['id'],$artworkId); $doc=svt_upload(svt_file_at($_FILES,'reference_text',$i),'text',(int)$user['id'],$artworkId); $timeline['milestones'][]=['position'=>$i+1,'role'=>$i===0?'Principio':($i===4?'Fin':'Desarrollo'),'image'=>$image,'narrative_text'=>trim((string)($_POST['narrative_text'][$i]??'')),'reference_image'=>$ref?:($old['reference_image']??''),'reference_text'=>$doc?:($old['reference_text']??'')]; }
    $real=array_filter($timeline['milestones'],fn($m)=>$m['image']!==''); $edges=array_filter([$timeline['milestones'][0]['image'],$timeline['milestones'][4]['image']]);
    if (($_POST['action']??'')==='concept') { if(count($real)<2||$edges===[]) throw new RuntimeException('Añade al menos dos imágenes ancla, incluyendo Principio o Fin.'); $analysisStmt=$pdo->prepare('SELECT analysis_json FROM artwork_analysis WHERE artwork_id=:id ORDER BY id DESC LIMIT 1');$analysisStmt->execute(['id'=>$artworkId]);$analysis=svt_json((string)$analysisStmt->fetchColumn()); $concept=(new SocialVideoService())->conceptFromTimeline($timeline,$artwork,$analysis,ArtistProfile::findForUser((int)$user['id'])); svt_save($pdo,(int)$user['id'],$artworkId,$timeline,'timeline_concept',svt_encode($concept));$notice='Concepto de timeline generado.'; } else { svt_save($pdo,(int)$user['id'],$artworkId,$timeline);$notice='Timeline guardada.'; }
} catch(Throwable $e) {$error=$e->getMessage();}
$realCount=count(array_filter($timeline['milestones'],fn($m)=>trim((string)($m['image']??''))!=='')); $edgeCount=count(array_filter([$timeline['milestones'][0]['image']??'',$timeline['milestones'][4]['image']??'']));
$wf->execute(['id'=>$artworkId]); $workflow=$wf->fetch()?:[];
ob_start();
register_shutdown_function(function (): void {
    global $artworkId, $workflow;
    $html = ob_get_clean();
    if (!is_string($html)) { return; }
    $conceptReady = trim((string)($workflow['final_concept_json'] ?? '')) !== '';
    $videoUrl = trim((string)($workflow['video_url'] ?? ''));
    $videoPanel = '';
    if ($conceptReady) {
        $status = htmlspecialchars(str_replace('_', ' ', (string)($workflow['video_status'] ?? 'concept ready')), ENT_QUOTES, 'UTF-8');
        $videoPanel = '<section class="svt-help" style="margin:0 0 24px"><h2>Video final</h2><p><strong>Estado:</strong> ' . $status . '</p>';
        if ($videoUrl === '') { $videoPanel .= '<p>El concepto está listo. Esta acción ejecuta Veo y puede tardar varios minutos.</p><form method="post" action="social_video_run.php"><input type="hidden" name="id" value="' . (int)$artworkId . '"><button type="submit">Generar video final</button></form>'; }
        if ($videoUrl !== '') { $videoPanel .= '<h3>Video generado</h3><video controls preload="metadata" style="width:100%;max-height:580px" src="media.php?file=' . rawurlencode($videoUrl) . '"></video>'; }
        $videoPanel .= '</section>';
    }
    $script = <<<'HTML'
<style>
.svt-library { padding:16px; gap:14px; }
.svt-library img { width:210px; height:150px; object-fit:cover; border-width:2px; }
.svt-slot { min-height:210px; }
.svt-slot img { max-height:200px; }
.svt-remove-image { width:100%; margin-top:7px; font-size:11px; }
.svt-preview { position:fixed; inset:0; z-index:1000; background:rgba(0,0,0,.78); display:flex; align-items:center; justify-content:center; padding:30px; cursor:zoom-out; }
.svt-preview img { max-width:min(92vw,1100px); max-height:88vh; object-fit:contain; background:#fff; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const button = document.querySelector('button[name="action"][value="concept"]');
  const message = button?.nextElementSibling;
  const update = () => {
    const cards = [...document.querySelectorAll('.svt-card')];
    const hasImage = card => Boolean(card.querySelector('.milestone-image')?.value) || Boolean(card.querySelector('input[type="file"][name^="milestone_image_upload"]')?.files.length);
    const count = cards.filter(hasImage).length;
    const edge = hasImage(cards[0]) || hasImage(cards[4]);
    const valid = count >= 2 && edge;
    if (button) button.disabled = !valid;
    if (message) message.textContent = valid ? 'Timeline lista para convertir en concepto.' : 'Falta: al menos dos imágenes ancla, incluyendo Principio o Fin.';
  };
  document.querySelectorAll('input[type="file"][name^="milestone_image_upload"]').forEach(input => input.addEventListener('change', update));
  const emptySlot = slot => { slot.classList.remove('has-image'); slot.textContent = 'Arrastra un mockup o sube una imagen'; };
  const makeMovable = slot => { const image = slot.querySelector('img'); if (!image) return; image.draggable = true; image.addEventListener('dragstart', e => { window.svtTimelineSource = slot.closest('.svt-card').dataset.index; e.dataTransfer.setData('text/plain', slot.closest('.svt-card').querySelector('.milestone-image').value); }); };
  document.querySelectorAll('.svt-card').forEach(card => {
    const field = card.querySelector('.milestone-image'), slot = card.querySelector('.svt-slot');
    const remove = document.createElement('button');
    remove.type = 'button'; remove.className = 'secondary svt-remove-image'; remove.innerHTML = '&times; Quitar imagen';
    remove.style.display = field.value ? 'block' : 'none';
    remove.addEventListener('click', () => { field.value = ''; const file = card.querySelector('input[type="file"][name^="milestone_image_upload"]'); if (file) file.value = ''; emptySlot(slot); remove.style.display = 'none'; update(); });
    slot.after(remove); makeMovable(slot);
    slot.addEventListener('drop', () => setTimeout(() => { const source = window.svtTimelineSource; if (source !== undefined && source !== null && source !== card.dataset.index) { const old = document.querySelector('.svt-card[data-index="' + source + '"]'); if (old) { old.querySelector('.milestone-image').value = ''; emptySlot(old.querySelector('.svt-slot')); old.querySelector('.svt-remove-image').style.display = 'none'; } } window.svtTimelineSource = null; makeMovable(slot); remove.style.display = field.value ? 'block' : 'none'; update(); }, 0));
  });
  document.querySelectorAll('.svt-library img').forEach(image => image.addEventListener('click', function () {
    const preview = document.createElement('div'); preview.className = 'svt-preview';
    preview.innerHTML = '<img alt="Vista previa del mockup" src="' + image.src + '">';
    preview.addEventListener('click', () => preview.remove()); document.body.appendChild(preview);
  }));
  update();
});
</script>
HTML;
    $html = str_replace('<div class="workspace svt">', '<div class="workspace svt">' . $videoPanel, $html);
    echo str_replace('</body>', $script . '</body>', $html);
});
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Social Video Timeline</title><link rel="stylesheet" href="style.css"><style>.svt{max-width:1500px;margin:0 auto}.svt-head{display:flex;justify-content:space-between;gap:20px;align-items:end}.svt-controls{display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin:22px 0}.svt-controls input{width:150px}.svt-library{display:flex;gap:10px;overflow:auto;padding:12px;background:var(--surface-soft);border:1px solid var(--line)}.svt-library img{width:110px;height:80px;object-fit:cover;cursor:grab;border:2px solid transparent}.svt-library img:hover{border-color:var(--accent)}.svt-line{display:grid;grid-template-columns:repeat(5,minmax(210px,1fr));gap:14px;position:relative}.svt-card{border:1px solid var(--line);background:var(--surface);padding:14px;border-radius:var(--radius)}.svt-card h3{margin:0 0 8px}.svt-slot{min-height:135px;border:1px dashed var(--line);display:flex;align-items:center;justify-content:center;background:var(--surface-soft);margin-bottom:10px;text-align:center;padding:8px}.svt-slot.has-image{border-style:solid}.svt-slot img{max-width:100%;max-height:130px}.svt-card textarea{width:100%;min-height:95px;box-sizing:border-box}.svt-card input{width:100%;box-sizing:border-box;margin:7px 0}.svt-help{padding:12px;border-left:3px solid var(--accent);background:var(--surface-soft)}@media(max-width:1050px){.svt-line{grid-template-columns:1fr}.svt-library{flex-wrap:wrap}}</style></head><body><div class="app-shell"><?php include __DIR__.'/sidebar.php';?><main class="main-area"><div class="workspace svt"><div class="svt-head"><div><h1>Social Video (beta)</h1><p>Dirige el recorrido: coloca cinco momentos y escribe qué sucede entre ellos.</p></div><a class="button-link secondary" href="artwork.php?id=<?= $artworkId ?>">Volver a la obra</a></div><?php if($notice):?><div class="notice"><?=svt_h($notice)?></div><?php endif;?><?php if($error):?><div class="notice error"><?=svt_h($error)?></div><?php endif;?><div class="svt-help">Necesitas <strong>dos imágenes ancla</strong> como mínimo, y una debe estar en <strong>Principio o Fin</strong>. Los hitos sin imagen pueden describirse con texto; su imagen intermedia se prototipa antes de generar el video final.</div><h2>Mockups disponibles</h2><div class="svt-library"><?php foreach($mockups as $m):$f=basename((string)$m['mockup_file']);?><img draggable="true" data-file="<?=svt_h($f)?>" src="media.php?file=<?=rawurlencode($f)?>" title="Arrastra a un hito"><?php endforeach;?></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="id" value="<?=$artworkId?>"><div class="svt-controls"><label>Duración total <input name="duration_seconds" type="number" min="16" max="60" value="<?= (int)$timeline['duration_seconds']?>"> s</label><label>Tono: Documental ←→ Artístico <input name="tone_value" type="range" min="0" max="100" value="<?= (int)$timeline['tone_value']?>"></label><output><?= (int)$timeline['tone_value']?></output></div><div class="svt-line"><?php foreach($timeline['milestones'] as $i=>$m):$img=trim((string)$m['image']);?><section class="svt-card" data-index="<?=$i?>"><h3><?=svt_h($m['role'])?> <small><?= $i+1 ?>/5</small></h3><div class="svt-slot <?=$img!==''?'has-image':''?>" data-slot="<?=$i?>"><?php if($img!=='' && !str_contains($img,'/')):?><img src="media.php?file=<?=rawurlencode($img)?>"><?php else:?>Arrastra un mockup o sube una imagen<?php endif;?></div><input class="milestone-image" type="hidden" name="milestone_image[<?=$i?>]" value="<?=svt_h($img)?>"><label>Imagen propia<input type="file" name="milestone_image_upload[<?=$i?>]" accept="image/jpeg,image/png,image/webp"></label><label>Qué sucede en este momento<textarea name="narrative_text[<?=$i?>]" autocomplete="off"><?=svt_h($m['narrative_text'])?></textarea></label><label>Imagen de referencia<input type="file" name="reference_image[<?=$i?>]" accept="image/jpeg,image/png,image/webp"></label><label>Texto o PDF de referencia<input type="file" name="reference_text[<?=$i?>]" accept=".txt,.pdf"></label></section><?php endforeach;?></div><div class="actions"><button class="secondary" name="action" value="save">Guardar timeline</button><button name="action" value="concept" <?=($realCount<2||$edgeCount===0)?'disabled':''?>>Generar concepto de video</button><span><?= $realCount<2||$edgeCount===0 ? 'Falta: al menos dos imágenes ancla, incluyendo Principio o Fin.' : 'Timeline lista para convertir en concepto.' ?></span></div></form></div></main></div><script>document.querySelectorAll('.svt-library img').forEach(i=>i.addEventListener('dragstart',e=>e.dataTransfer.setData('text/plain',i.dataset.file)));document.querySelectorAll('.svt-slot').forEach(slot=>{slot.addEventListener('dragover',e=>e.preventDefault());slot.addEventListener('drop',e=>{e.preventDefault();let f=e.dataTransfer.getData('text/plain'),n=slot.dataset.slot,card=slot.closest('.svt-card');card.querySelector('.milestone-image').value=f;slot.classList.add('has-image');slot.innerHTML='<img src="media.php?file='+encodeURIComponent(f)+'">';});});</script></body></html>
