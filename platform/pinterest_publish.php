<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
$user=Auth::requireUser(); FeatureAccess::requirePage($user,FeatureAccess::SOCIAL_MANAGE,'Social Media'); Auth::start(); $userId=(int)$user['id']; $pdo=Database::connection();
$publicationId=max(0,(int)($_GET['id']??$_POST['id']??0)); $service=new PublicationService($pdo); $pinterest=new PinterestIntegrationService($pdo);
$error=''; $notice='';
try{
    $publication=$service->get($publicationId,$userId);
    if(($publication['status']??'')!=='published'||($publication['visibility']??'')==='private') throw new RuntimeException('Publica primero la landing como pública o no listada para que Pinterest pueda leer la imagen y el enlace.');
    $boards=$pinterest->boards($userId);
    $itemId=max(0,(int)($_POST['item_id']??$_GET['item_id']??($publication['items'][0]['mockup_sheet_id']??0)));
    $selectedItem=null; foreach($publication['items'] as $item)if((int)$item['mockup_sheet_id']===$itemId){$selectedItem=$item;break;}
    if(!$selectedItem)throw new RuntimeException('Selecciona un mockup válido.');
    $variant=null; foreach($publication['variants'] as $candidate)if($candidate['channel']==='pinterest'){$variant=$candidate;break;}
    if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['action']??'')==='publish'){
        if(!hash_equals((string)($_SESSION['pinterest_publish_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('La sesión de confirmación expiró.');
        if(($_POST['confirm']??'')!=='yes')throw new RuntimeException('Debes confirmar expresamente la publicación.');
        $boardId=trim((string)($_POST['board_id']??'')); $allowed=false; foreach($boards as $board)if((string)($board['id']??'')===$boardId){$allowed=true;break;}
        if(!$allowed)throw new RuntimeException('El tablero seleccionado ya no está disponible.');
        $base=rtrim(app_env('APP_PUBLIC_URL'),'/');
        $landing=$base.'/public_artwork.php?slug='.rawurlencode((string)$publication['slug']);
        $image=$base.'/publication_media.php?slug='.rawurlencode((string)$publication['slug']).'&file='.rawurlencode(basename((string)$selectedItem['mockup_file']));
        $payload=(new PinterestPublisher())->imagePinPayload([
            'title'=>trim((string)($_POST['title']??'')),
            'description'=>trim((string)($_POST['description']??'')),
        ],$selectedItem,$boardId,$landing,$image);
        $jobId=$service->savePinterestDraft($publicationId,$userId,$itemId,$boardId,$landing);
        $result=$pinterest->createPin($userId,$payload); $pinId=(string)($result['id']??'');
        $pinUrl=$pinId!==''?'https://www.pinterest.com/pin/'.rawurlencode($pinId).'/':'';
        $pdo->prepare("UPDATE distribution_jobs SET status='published',external_id=?,external_url=?,payload_json=?,error='',updated_at=? WHERE id=?")->execute([$pinId,$pinUrl,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),date('c'),$jobId]);
        $_SESSION['pinterest_publish_notice']='Pin publicado correctamente'.($pinUrl!==''?'.':''); header('Location: pinterest_publish.php?id='.$publicationId.'&item_id='.$itemId); exit;
    }
}catch(Throwable $e){$error=$e->getMessage(); $boards=$boards??[]; $publication=$publication??['items'=>[],'variants'=>[]];}
$_SESSION['pinterest_publish_csrf']=bin2hex(random_bytes(24)); $notice=(string)($_SESSION['pinterest_publish_notice']??'');unset($_SESSION['pinterest_publish_notice']);
function pph($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Publicar en Pinterest</title><link rel="stylesheet" href="style.css"><style>.pin-grid{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:24px}.pin-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--radius);padding:20px}.pin-card img{width:100%;max-height:70vh;object-fit:contain;background:#111}.pin-card label{display:block;font-weight:700;margin:14px 0 5px}.pin-card input,.pin-card textarea,.pin-card select{width:100%;box-sizing:border-box;padding:10px}.pin-card textarea{min-height:120px}.confirm{display:flex!important;gap:8px;align-items:flex-start;font-weight:400!important}.confirm input{width:auto}@media(max-width:850px){.pin-grid{grid-template-columns:1fr}}</style></head><body><div class="app-shell"><?php include __DIR__.'/sidebar.php';?><main class="main-area"><div class="workspace"><div class="workspace-header"><div><h1>Publicar en Pinterest</h1><p>Revisa todo antes de crear el Pin.</p></div><a class="button-link secondary" href="prepare_publication.php?id=<?=$publicationId?>">← Publicación</a></div>
<?php if($error):?><div class="notice error"><?=pph($error)?></div><?php endif;?><?php if($notice):?><div class="notice success"><?=pph($notice)?></div><?php endif;?>
<?php if(!$error&&$selectedItem):?><form method="post" class="pin-grid"><input type="hidden" name="id" value="<?=$publicationId?>"><input type="hidden" name="csrf" value="<?=pph($_SESSION['pinterest_publish_csrf'])?>"><section class="pin-card"><img src="publication_media.php?slug=<?=rawurlencode((string)$publication['slug'])?>&file=<?=rawurlencode(basename((string)$selectedItem['mockup_file']))?>" alt="<?=pph($selectedItem['alt_text'])?>"></section><aside class="pin-card">
<label>Mockup</label><select name="item_id" onchange="location='pinterest_publish.php?id=<?=$publicationId?>&item_id='+this.value"><?php foreach($publication['items'] as $item):?><option value="<?=(int)$item['mockup_sheet_id']?>" <?=(int)$item['mockup_sheet_id']===$itemId?'selected':''?>><?=pph($item['title']?:'Mockup #'.$item['mockup_sheet_id'])?></option><?php endforeach;?></select>
<label>Tablero</label><select name="board_id" required><option value="">Seleccionar…</option><?php foreach($boards as $board):?><option value="<?=pph($board['id']??'')?>"><?=pph($board['name']??'Untitled')?></option><?php endforeach;?></select>
<label>Título</label><input name="title" maxlength="100" value="<?=pph($variant['title']??$selectedItem['title'])?>" required><label>Descripción</label><textarea name="description" maxlength="500"><?=pph($variant['description']??$selectedItem['description'])?></textarea>
<p><strong>Destino:</strong><br><?=pph(rtrim(app_env('APP_PUBLIC_URL'),'/').'/public_artwork.php?slug='.$publication['slug'])?></p>
<label class="confirm"><input type="checkbox" name="confirm" value="yes" required><span>Confirmo que quiero publicar esta imagen ahora en el tablero seleccionado.</span></label><button class="button-link primary" name="action" value="publish">Publicar Pin</button>
</aside></form><?php elseif(str_contains($error,'Conecta')):?><p><a class="button-link primary" href="integrations/pinterest/">Conectar Pinterest</a></p><?php endif;?></div></main></div></body></html>
