<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/bootstrap.php';
Auth::start();$user=Auth::user();$service=new MetaIntegrationService(Database::connection());
$oauthEnabled=app_env('META_OAUTH_ENABLED','false')==='true';
$isAdmin=$user?Auth::isAdmin($user):false;
$purposes=$isAdmin?['artist'=>'Artist account','platform'=>'Artwork Mockups platform account']:['artist'=>'Artist account'];
$error='';$pages=[];$selecting='';
if($user&&$_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals((string)($_SESSION['meta_csrf']??''),(string)($_POST['csrf']??'')))$error='La sesión expiró. Recarga la página.';
    else try{
        $purpose=(string)($_POST['purpose']??'artist');if(!isset($purposes[$purpose]))throw new RuntimeException('Esta identidad de Meta no está disponible.');
        $action=(string)($_POST['action']??'');
        if($action==='connect'){header('Location: '.$service->authorizationUrl((int)$user['id'],$purpose));exit;}
        if($action==='disconnect'){$service->disconnect((int)$user['id'],$purpose);header('Location: ./');exit;}
        if($action==='select_page'){$service->selectPage((int)$user['id'],$purpose,(string)($_POST['page_id']??''));header('Location: ./');exit;}
    }catch(Throwable $e){$error=$e->getMessage();}
}
$connections=[];if($user)foreach($purposes as $purpose=>$label){
    $connections[$purpose]=$service->connection((int)$user['id'],$purpose);
    if(($connections[$purpose]['status']??'')==='awaiting_page')try{$pages[$purpose]=$service->pages((int)$user['id'],$purpose);}catch(Throwable $e){$error=$e->getMessage();}
}
$_SESSION['meta_csrf']=bin2hex(random_bytes(24));
PublicPage::start('Meta Connections | Artwork Mockups','Connect a Facebook Page safely. Instagram is connected separately.','integrations/meta/');
?>
<span class="eyebrow">Optional integration</span><h1>Meta Connections</h1>
<p class="lede">Connect one Facebook Page for controlled publishing. Instagram uses its own direct professional connection. Connecting never publishes content.</p>
<p><a class="button-link secondary" href="<?=PublicPage::h(PublicPage::path('integrations/instagram/'))?>">Manage Instagram connection</a></p>
<?php if($error):?><div class="info-card"><p><?=PublicPage::h($error)?></p></div><?php endif;?>
<section><h2>Connections</h2>
<?php if(!$user):?><p>Sign in before connecting Meta.</p><a class="button-link primary" href="<?=PublicPage::h(PublicPage::path('login.php'))?>">Sign in</a>
<?php else:foreach($purposes as $purpose=>$label):$connection=$connections[$purpose]??null;?>
<div class="info-card"><h3><?=PublicPage::h($label)?></h3>
<?php if(($connection['status']??'')==='connected'):?>
<p><strong>Facebook Page:</strong> <?=PublicPage::h((string)$connection['page_name'])?></p>
<p><strong>Instagram:</strong> <?=($connection['instagram_account_id']??'')!==''?'@'.PublicPage::h((string)$connection['instagram_username']):'Managed through a separate connection.'?></p>
<p><strong>Granted permissions:</strong> <?=PublicPage::h((string)$connection['scopes'])?></p>
<p><strong>Authorization expires:</strong> <?=PublicPage::h((string)$connection['token_expires_at'])?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['meta_csrf'])?>"><input type="hidden" name="purpose" value="<?=PublicPage::h($purpose)?>"><button class="button-link secondary" name="action" value="disconnect">Disconnect</button></form>
<?php elseif(($connection['status']??'')==='awaiting_page'):?>
<p>Meta authorized. Choose the Facebook Page used by this identity:</p>
<form method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['meta_csrf'])?>"><input type="hidden" name="purpose" value="<?=PublicPage::h($purpose)?>">
<?php foreach((array)($pages[$purpose]??[]) as $page):?><label style="display:block;margin:.6rem 0"><input type="radio" name="page_id" value="<?=PublicPage::h((string)$page['id'])?>" required> <?=PublicPage::h((string)($page['name']??'Facebook Page'))?></label><?php endforeach;?>
<button class="button-link primary" name="action" value="select_page">Use selected Page</button></form>
<?php else:?><p><?=$purpose==='artist'?'For the Facebook Page you manage.':'For Artwork Mockups platform channels only.'?></p>
<?php if(!$oauthEnabled):?><p><strong>OAuth is safely disabled in this environment.</strong> Enable it only after the exact public HTTPS callback is active.</p><?php endif;?>
<form method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['meta_csrf'])?>"><input type="hidden" name="purpose" value="<?=PublicPage::h($purpose)?>"><button class="button-link primary" name="action" value="connect" <?=$oauthEnabled?'':'disabled'?>>Connect <?=PublicPage::h($label)?></button></form><?php endif;?></div>
<?php endforeach;endif;?></section>
<section><h2>Current safety</h2><ul><li>No Facebook post is created from this screen.</li><li>Tokens are encrypted before storage.</li><li>Artist and Artwork Mockups identities never share credentials.</li></ul></section>
<?php PublicPage::end(); ?>
