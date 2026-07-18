<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/bootstrap.php';
Auth::start(); $user=Auth::user(); $message=''; $ok=false;
try{
    if(!$user)throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    if(!FeatureAccess::allows($user,FeatureAccess::SOCIAL_MANAGE))throw new RuntimeException('Social Media requires Artist Pro.');
    if(isset($_GET['error']))throw new RuntimeException('Pinterest authorization was cancelled.');
    (new PinterestIntegrationService(Database::connection()))->completeAuthorization((int)$user['id'],trim((string)($_GET['code']??'')),trim((string)($_GET['state']??'')));
    $message='Pinterest account connected successfully.'; $ok=true;
}catch(Throwable $e){$message=$e->getMessage();}
PublicPage::start('Pinterest connection | Artwork Mockups','Secure Pinterest connection response.','integrations/pinterest/callback/',true);
?>
<span class="eyebrow">Pinterest connection</span><h1><?=$ok?'Connection complete':'Connection failed'?></h1><div class="info-card"><p><?=PublicPage::h($message)?></p></div><p><a href="<?=PublicPage::h(PublicPage::path('integrations/pinterest/'))?>">Return to Pinterest connections</a></p>
<?php PublicPage::end(); ?>
