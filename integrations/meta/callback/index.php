<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/bootstrap.php';
Auth::start();$user=Auth::user();$message='';$ok=false;$purpose=(string)($_GET['purpose']??'artist');
try{
    if(!$user)throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    if(isset($_GET['error']))throw new RuntimeException('Meta authorization was cancelled.');
    (new MetaIntegrationService(Database::connection()))->completeAuthorization((int)$user['id'],$purpose,trim((string)($_GET['code']??'')),trim((string)($_GET['state']??'')));
    $message='Meta account authorized. Now select the Facebook Page you want to use.';$ok=true;
}catch(Throwable $e){$message=$e->getMessage();}
PublicPage::start('Meta connection | Artwork Mockups','Secure Meta connection response.','integrations/meta/callback/',true);
?>
<span class="eyebrow">Meta connection</span><h1><?=$ok?'Authorization complete':'Connection failed'?></h1><div class="info-card"><p><?=PublicPage::h($message)?></p></div><p><a href="<?=PublicPage::h(PublicPage::path('integrations/meta/'))?>">Return to Meta connections</a></p>
<?php PublicPage::end(); ?>
