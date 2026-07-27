<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/bootstrap.php';
Auth::start(); $user=Auth::user(); $message=''; $ok=false; $purpose='artist';

if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && (string)($_SESSION['pinterest_oauth']['api_environment'] ?? '') === 'sandbox'
    && !isset($_GET['error'])) {
    $code=trim((string)($_GET['code']??''));
    $state=trim((string)($_GET['state']??''));
    if(!$user||$code===''||$state===''){
        $_SESSION['connections_error']='Pinterest did not return a valid Sandbox authorization code.';
        header('Location: '.PublicPage::path('connections.php?open=pinterestplatform'));
        exit;
    }
    $_SESSION['pinterest_callback_pending']=[
        'code'=>$code,
        'state'=>$state,
        'csrf'=>bin2hex(random_bytes(24)),
        'expires_at'=>time()+600,
    ];
    PublicPage::start('Pinterest OAuth callback | Artwork Mockups','Pinterest returned an authorization code to Artwork Mockups.','integrations/pinterest/callback');
    ?>
    <span class="eyebrow">Sandbox OAuth</span>
    <h1>Authorization code received</h1>
    <p class="lede">Pinterest redirected to the registered Artwork Mockups callback. The authorization code is visible in the browser address bar and is ready for a secure server-side exchange.</p>
    <div class="info-card">
        <h2>Exchange authorization code</h2>
        <p><strong>App:</strong> <?=htmlspecialchars((string)($_SESSION['pinterest_oauth']['app_id']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p>
        <p><strong>Code:</strong> <?=htmlspecialchars(substr($code,0,12).'…',ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></p>
        <p>The next request sends the code and registered redirect URI to <code>https://api-sandbox.pinterest.com/v5/oauth/token</code>. The app secret remains on the server.</p>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars((string)$_SESSION['pinterest_callback_pending']['csrf'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?>">
            <button class="button-link primary" type="submit">Exchange code for access token</button>
        </form>
    </div>
    <?php
    PublicPage::end();
    exit;
}

try{
    if(!$user)throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    if(!FeatureAccess::allows($user,FeatureAccess::SOCIAL_MANAGE))throw new RuntimeException('Social Media requires Artist Pro.');
    if(isset($_GET['error']))throw new RuntimeException('Pinterest authorization was cancelled.');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $pending=$_SESSION['pinterest_callback_pending']??null;
        unset($_SESSION['pinterest_callback_pending']);
        if(!is_array($pending)||(int)($pending['expires_at']??0)<time()
            ||!hash_equals((string)($pending['csrf']??''),(string)($_POST['csrf']??''))){
            throw new RuntimeException('The Pinterest Sandbox callback expired. Connect again.');
        }
        $code=(string)($pending['code']??'');
        $state=(string)($pending['state']??'');
    }else{
        $code=trim((string)($_GET['code']??''));
        $state=trim((string)($_GET['state']??''));
    }
    $purpose=(new PinterestIntegrationService(Database::connection()))->completeAuthorization((int)$user['id'],$code,$state);
    $message='The Pinterest account is connected and ready to use.'; $ok=true;
}catch(Throwable $e){$message=$e->getMessage();}
if($ok)$_SESSION['connections_notice']=$message;else $_SESSION['connections_error']=$message;
$_SESSION['connections_open']=($purpose??'artist')==='platform'?'pinterestplatform':'pinterest';
header('X-Robots-Tag: noindex, nofollow',true);
header('Location: '.PublicPage::path('connections.php?open='.rawurlencode((string)$_SESSION['connections_open'])));
exit;
