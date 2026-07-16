<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/bootstrap.php';
Auth::start();
$user=Auth::user();
$service=new PinterestIntegrationService(Database::connection());
$isAdmin=$user?Auth::isAdmin($user):false;
$purposes=$isAdmin?['artist'=>'Artist account','platform'=>'Artwork Mockups platform account']:['artist'=>'Artist account'];
$connections=[];if($user)foreach($purposes as $purpose=>$label)$connections[$purpose]=$service->connection((int)$user['id'],$purpose);
$error='';
if($user && $_SERVER['REQUEST_METHOD']==='POST'){
    $csrf=(string)($_POST['csrf']??'');
    if(!hash_equals((string)($_SESSION['pinterest_csrf']??''),$csrf)){$error='La sesión expiró. Recarga la página.';}
    else{
        try{
            $purpose=(string)($_POST['purpose']??'artist');if(!isset($purposes[$purpose]))throw new RuntimeException('Pinterest account type is not available.');
            if(($_POST['action']??'')==='connect'){header('Location: '.$service->authorizationUrl((int)$user['id'],$purpose));exit;}
            if(($_POST['action']??'')==='disconnect'){$service->disconnect((int)$user['id'],$purpose);header('Location: ./');exit;}
        }catch(Throwable $e){$error=$e->getMessage();}
    }
}
$_SESSION['pinterest_csrf']=bin2hex(random_bytes(24));
PublicPage::start('Pinterest Integration | Artwork Mockups','Connect Pinterest and publish selected artwork mockups with explicit approval.','integrations/pinterest/');
?>
<style>
    .public-main{--public-max:1080px}
    .pinterest-console{display:grid;gap:24px}
    .pinterest-hero{display:grid;grid-template-columns:minmax(0,1fr) 280px;gap:30px;align-items:end;padding:34px 0 26px;border-bottom:1px solid var(--line)}
    .pinterest-hero h1{max-width:690px;margin:10px 0 16px;font-size:clamp(40px,5vw,58px)}
    .pinterest-hero .lede{max-width:650px;margin:0}
    .pinterest-status-card{border:1px solid var(--line);background:var(--surface);padding:18px;border-radius:6px;box-shadow:var(--shadow)}
    .pinterest-status-card span{display:block;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
    .pinterest-status-card strong{display:block;margin-top:7px;font-family:var(--font-serif);font-size:27px;font-weight:500;color:var(--ink)}
    .pinterest-status-card small{display:block;margin-top:5px;color:var(--muted);font-size:12px;line-height:1.5}
    .pinterest-alert{border:1px solid #ead2ce;background:#fff6f4;color:#8b3329;padding:13px 15px;border-radius:6px}
    .pinterest-alert p{margin:0;color:inherit}
    .connection-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px}
    .connection-card{display:grid;gap:18px;align-content:start;border:1px solid var(--line);background:var(--surface);padding:22px;border-radius:6px;box-shadow:var(--shadow)}
    .connection-card__top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}
    .connection-card h3{margin:0;font-size:25px}
    .connection-card p{margin:0}
    .connection-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid var(--line);border-radius:999px;background:var(--surface-soft);color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase;white-space:nowrap}
    .connection-badge::before{content:"";width:7px;height:7px;border-radius:999px;background:#b9b5aa}
    .connection-badge.is-connected{border-color:#cddfce;background:#edf6ee;color:#27643a}
    .connection-badge.is-connected::before{background:#4f9a62}
    .connection-meta{display:grid;gap:6px;padding-top:2px}
    .connection-meta span{color:var(--muted);font-size:12px}
    .pinterest-controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .pinterest-rules{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:4px}
    .rule-card{border:1px solid var(--line);background:var(--surface);padding:16px;border-radius:6px}
    .rule-card span{display:block;margin-bottom:8px;color:var(--accent);font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
    .rule-card p{margin:0;font-size:13px;line-height:1.55}
    .section-title{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-top:10px}
    .section-title h2{margin:0;font-size:31px}
    .section-title p{margin:0;max-width:470px;text-align:right}
    @media(max-width:820px){.pinterest-hero{grid-template-columns:1fr}.section-title{align-items:flex-start;flex-direction:column}.section-title p{text-align:left}.pinterest-rules{grid-template-columns:1fr 1fr}}
    @media(max-width:560px){.connection-card__top{flex-direction:column}.pinterest-rules{grid-template-columns:1fr}.pinterest-controls .button-link{width:100%;justify-content:center}}
</style>
<div class="pinterest-console">
    <section class="pinterest-hero">
        <div>
            <span class="eyebrow">Advanced publishing</span>
            <h1>Pinterest</h1>
            <p class="lede">Connect the artist account and prepare selected mockups for Pinterest. Nothing is published without explicit approval.</p>
        </div>
        <aside class="pinterest-status-card">
            <span>API environment</span>
            <strong><?=PublicPage::h(app_env('PINTEREST_API_ENVIRONMENT','production'))?></strong>
            <small>Connection only. Publication remains manual and confirmed.</small>
        </aside>
    </section>

    <?php if($error):?><div class="pinterest-alert"><p><?=PublicPage::h($error)?></p></div><?php endif;?>

    <section>
        <div class="section-title">
            <h2>Connections</h2>
            <p>Use the artist identity for artwork traffic. Platform identity is only available to admin users.</p>
        </div>
        <?php if(!$user):?>
            <div class="connection-card">
                <div class="connection-card__top"><h3>Sign in required</h3><span class="connection-badge">Pending</span></div>
                <p>Sign in to Artwork Mockups before connecting Pinterest.</p>
                <div class="pinterest-controls"><a class="button-link primary" href="<?=PublicPage::h(PublicPage::path('login.php'))?>">Sign in</a></div>
            </div>
        <?php else:?>
            <div class="connection-grid">
                <?php foreach($purposes as $purpose=>$label):$connection=$connections[$purpose]??null;$connected=(($connection['status']??'')==='connected');?>
                    <article class="connection-card">
                        <div class="connection-card__top">
                            <h3><?=PublicPage::h($label)?></h3>
                            <span class="connection-badge <?=$connected?'is-connected':''?>"><?=$connected?'Connected':'Pending'?></span>
                        </div>
                        <?php if($connected):?>
                            <div class="connection-meta">
                                <span>Pinterest account</span>
                                <p><strong><?=PublicPage::h($connection['pinterest_account_id']??'Pinterest')?></strong></p>
                            </div>
                            <form class="pinterest-controls" method="post">
                                <input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['pinterest_csrf'])?>">
                                <input type="hidden" name="purpose" value="<?=PublicPage::h($purpose)?>">
                                <button class="button-link primary" name="action" value="connect">Reconnect</button>
                                <button class="button-link secondary" name="action" value="disconnect">Disconnect</button>
                            </form>
                        <?php else:?>
                            <p><?=PublicPage::h($purpose==='artist'?'Used for artwork mockups and traffic to the artist website or marketplaces.':'Used only for promotion of the Artwork Mockups platform.')?></p>
                            <form class="pinterest-controls" method="post"><input type="hidden" name="csrf" value="<?=PublicPage::h($_SESSION['pinterest_csrf'])?>"><input type="hidden" name="purpose" value="<?=PublicPage::h($purpose)?>"><button class="button-link primary" name="action" value="connect">Connect <?=PublicPage::h($label)?></button></form>
                        <?php endif;?>
                    </article>
                <?php endforeach;?>
            </div>
        <?php endif;?>
    </section>

    <section>
        <div class="section-title">
            <h2>User control</h2>
            <p>The artist decides what leaves the app.</p>
        </div>
        <div class="pinterest-rules">
            <article class="rule-card"><span>01</span><p>Artwork Mockups does not publish automatically.</p></article>
            <article class="rule-card"><span>02</span><p>Every Pin requires explicit confirmation.</p></article>
            <article class="rule-card"><span>03</span><p>The artist chooses image, board and destination link.</p></article>
            <article class="rule-card"><span>04</span><p>The connection can be disconnected at any time.</p></article>
        </div>
    </section>
</div>
<?php PublicPage::end(); ?>
