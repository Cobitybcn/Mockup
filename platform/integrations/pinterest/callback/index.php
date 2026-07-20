<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/app/bootstrap.php';
Auth::start(); $user=Auth::user(); $message=''; $ok=false;
try{
    if(!$user)throw new RuntimeException('Your Artwork Mockups session expired. Sign in and connect again.');
    if(!FeatureAccess::allows($user,FeatureAccess::SOCIAL_MANAGE))throw new RuntimeException('Social Media requires Artist Pro.');
    if(isset($_GET['error']))throw new RuntimeException('Pinterest authorization was cancelled.');
    (new PinterestIntegrationService(Database::connection()))->completeAuthorization((int)$user['id'],trim((string)($_GET['code']??'')),trim((string)($_GET['state']??'')));
    $message='La cuenta de Pinterest quedó conectada y lista para trabajar.'; $ok=true;
}catch(Throwable $e){$message=$e->getMessage();}
if($ok)$_SESSION['connections_notice']=$message;else $_SESSION['connections_error']=$message;
$_SESSION['connections_open']='pinterest';
header('X-Robots-Tag: noindex, nofollow',true);
header('Location: '.PublicPage::path('connections.php?open=pinterest'));
exit;
