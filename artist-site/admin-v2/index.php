<?php
declare(strict_types=1);

if (getenv('K_SERVICE') !== false || strtolower((string)(getenv('APP_ENV') ?: '')) === 'production') {
    $adminBase = rtrim((string)(getenv('ARTIST_ADMIN_URL') ?: getenv('ARTWORKMOCKUPS_PUBLIC_URL') ?: ''), '/');
    if ($adminBase === '') {
        http_response_code(404);
        exit;
    }
    if (!str_ends_with($adminBase, '/site-admin')) $adminBase .= '/site-admin';
    header('Location: ' . $adminBase . '/', true, 302);
    exit;
}

require_once dirname(__DIR__).'/inc/ArtistCatalogV2Repository.php';
session_set_cookie_params(['path'=>'/','httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'samesite'=>'Lax']);
session_start();if(empty($_SESSION['admin_v2_csrf']))$_SESSION['admin_v2_csrf']=bin2hex(random_bytes(32));
$passwordFile=dirname(__DIR__).'/data/admin-password.json';$repo=new ArtistCatalogV2Repository(dirname(__DIR__).'/data/catalog-v2');
function av2h(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function av2auth():bool{return !empty($_SESSION['admin_ok']);}
function av2csrf():void{if(!hash_equals((string)($_SESSION['admin_v2_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Invalid form session.');}
$notice='';$error='';
try{
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        av2csrf();$action=(string)($_POST['action']??'');
        if($action==='login'){$config=is_file($passwordFile)?json_decode((string)file_get_contents($passwordFile),true):[];if(!empty($config['hash'])&&password_verify((string)($_POST['password']??''),(string)$config['hash'])){session_regenerate_id(true);$_SESSION['admin_ok']=true;}else throw new RuntimeException('Invalid password.');}
        elseif($action==='logout'){unset($_SESSION['admin_ok']);session_regenerate_id(true);}
        elseif($action==='save_commerce'){
            if(!av2auth())throw new RuntimeException('Authentication required.');
            $showMap=!empty($_POST['show_on_map']);$location=['show_on_map'=>$showMap,'type'=>in_array($_POST['location_type']??'', ['real_sale','assigned','studio','none'],true)?$_POST['location_type']:'none','country'=>trim((string)($_POST['country']??'')),'city'=>trim((string)($_POST['city']??''))];
            $repo->saveCommerce((string)($_POST['source_artwork_id']??''),['status'=>(string)($_POST['status']??'available'),'visibility'=>(string)($_POST['visibility']??'private'),'price'=>trim((string)($_POST['price']??'')),'currency'=>(string)($_POST['currency']??'EUR'),'sale_mode'=>(string)($_POST['sale_mode']??'inquiry'),'sort_order'=>(int)($_POST['sort_order']??0),'pinned'=>!empty($_POST['pinned']),'location'=>$location]);$notice='Commercial information saved.';
        }
    }
}catch(Throwable $e){$error=$e->getMessage();}
$items=av2auth()?$repo->allCombined():[];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Artist Catalogue · Admin V2</title><link rel="stylesheet" href="admin-v2.css"></head><body>
<header><div><span class="eyebrow">Maurizio Valch</span><h1>Artist Catalogue</h1></div><?php if(av2auth()):?><form method="post"><input type="hidden" name="csrf" value="<?=av2h($_SESSION['admin_v2_csrf'])?>"><button name="action" value="logout" class="quiet">Log out</button></form><?php endif;?></header><main>
<?php if($error):?><p class="message error"><?=av2h($error)?></p><?php endif;?><?php if($notice):?><p class="message"><?=av2h($notice)?></p><?php endif;?>
<?php if(!av2auth()):?><section class="login"><span class="eyebrow">Private administration</span><h2>Sign in</h2><?php if(!is_file($passwordFile)):?><p>The existing website administrator must be configured first.</p><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=av2h($_SESSION['admin_v2_csrf'])?>"><label>Password<input type="password" name="password" required autofocus></label><button name="action" value="login">Enter catalogue</button></form><?php endif;?></section>
<?php else:?><div class="summary"><strong><?=count($items)?></strong> V2 artworks received from Artwork Mockups</div><?php if(!$items):?><section class="empty"><h2>The parallel catalogue is ready</h2><p>No V2 artwork has been synchronized yet. The current public catalogue remains unchanged.</p></section><?php endif;?>
<div class="catalog"><?php foreach($items as $item):$facts=(array)$item['artwork_facts'];$editorial=(array)$item['editorial'];$commerce=(array)$item['commerce'];$location=(array)($commerce['location']??[]);?><article class="card"><div class="card-head"><div><span class="source"><?=av2h($item['source_artwork_id'])?></span><h2><?=av2h($facts['title']??'Untitled')?></h2><p><?=av2h($facts['year']??'')?> · <?=av2h($facts['medium']??'')?> · <?=av2h($facts['series']??'')?></p></div><span class="status"><?=av2h($commerce['status']??'not configured')?></span></div><p class="editorial"><?=av2h($editorial['summary']??'')?></p>
<form method="post" class="commerce"><input type="hidden" name="csrf" value="<?=av2h($_SESSION['admin_v2_csrf'])?>"><input type="hidden" name="source_artwork_id" value="<?=av2h($item['source_artwork_id'])?>"><div class="grid">
<label>Status<select name="status"><?php foreach(['available','reserved','sold','not_for_sale','archived'] as $v):?><option <?=$v===($commerce['status']??'available')?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label>Visibility<select name="visibility"><?php foreach(['private','public','unlisted'] as $v):?><option <?=$v===($commerce['visibility']??'private')?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label>Price<input name="price" inputmode="decimal" value="<?=av2h($commerce['price']??'')?>"></label><label>Currency<select name="currency"><?php foreach(['EUR','USD'] as $v):?><option <?=$v===($commerce['currency']??'EUR')?'selected':''?>><?=$v?></option><?php endforeach;?></select></label>
<label>Sale mode<select name="sale_mode"><?php foreach(['inquiry','direct_purchase','hidden'] as $v):?><option <?=$v===($commerce['sale_mode']??'inquiry')?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Order<input type="number" name="sort_order" value="<?=av2h($commerce['sort_order']??0)?>"></label>
<label>Location type<select name="location_type"><?php foreach(['none','real_sale','assigned','studio'] as $v):?><option <?=$v===($location['type']??'none')?'selected':''?>><?=$v?></option><?php endforeach;?></select></label><label>Country<input name="country" value="<?=av2h($location['country']??'')?>"></label><label>City / region<input name="city" value="<?=av2h($location['city']??'')?>"></label>
</div><div class="checks"><label><input type="checkbox" name="show_on_map" <?=$location['show_on_map']??false?'checked':''?>> Show on map</label><label><input type="checkbox" name="pinned" <?=$commerce['pinned']??false?'checked':''?>> Featured</label></div><button name="action" value="save_commerce">Save commercial information</button></form></article><?php endforeach;?></div><?php endif;?></main></body></html>
