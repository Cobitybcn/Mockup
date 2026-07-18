<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$user=Auth::requireUser();
FeatureAccess::requirePage($user,FeatureAccess::SOCIAL_MANAGE,'Social Media');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('Method not allowed.');}
$mockupId=max(0,(int)($_POST['mockup_id']??0));$channel=(string)($_POST['channel']??'');$destination=trim((string)($_POST['destination_url']??''));
try{
    Auth::start();
    if(!hash_equals((string)($_SESSION['meta_draft_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('The Meta draft form expired. Reload the mockup.');
    $id=(new MetaSocialDraftService(Database::connection()))->create($mockupId,$user,$channel,$destination,'artist');
    $_SESSION['meta_draft_notice']=ucfirst($channel).' draft #'.$id.' saved. Nothing was published.';
}catch(Throwable $e){$_SESSION['meta_draft_error']=$e->getMessage();}
header('Location: viewer.php?id='.$mockupId);exit;
