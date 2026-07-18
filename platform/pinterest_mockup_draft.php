<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
$user=Auth::requireUser();FeatureAccess::requirePage($user,FeatureAccess::SOCIAL_MANAGE,'Social Media');Auth::start();$mockupId=max(0,(int)($_POST['mockup_id']??0));
try{
    if(!hash_equals((string)($_SESSION['pinterest_draft_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('The draft session expired. Reload the viewer.');
    $draft=(new MockupPinterestDraftService(Database::connection()))->create($mockupId,$user,(string)($_POST['purpose']??'artist'),trim((string)($_POST['destination_url']??'')));
    header('Location: pinterest_draft_review.php?id='.(int)$draft['id']);exit;
}catch(Throwable $e){$_SESSION['pinterest_draft_error']=$e->getMessage();}
header('Location: viewer.php?id='.$mockupId);exit;
