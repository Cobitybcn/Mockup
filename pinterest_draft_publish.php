<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
$user=Auth::requireUser();Auth::start();$userId=(int)$user['id'];$id=max(0,(int)($_POST['id']??0));$drafts=new MockupPinterestDraftService(Database::connection());
try{
    if(app_env('PINTEREST_LIVE_PUBLISH_ENABLED','false')!=='true'||app_env('PINTEREST_DRAFT_PUBLIC_MEDIA_ENABLED','false')!=='true')throw new RuntimeException('Live Pinterest publication is disabled.');
    $csrf=(string)($_SESSION['pinterest_live_csrf']??'');unset($_SESSION['pinterest_live_csrf']);if(!hash_equals($csrf,(string)($_POST['csrf']??''))||($_POST['confirm']??'')!=='yes')throw new RuntimeException('Explicit publication confirmation is required.');
    $draft=$drafts->draft($id,$userId);if(($draft['status']??'')==='published')throw new RuntimeException('This draft was already published.');if(($draft['board_id']??'')==='')throw new RuntimeException('Select a Pinterest board first.');
    $base=rtrim(app_env('APP_PUBLIC_URL',''),'/');if(!str_starts_with($base,'https://'))throw new RuntimeException('A public HTTPS APP_PUBLIC_URL is required.');$image=$base.'/pinterest_draft_media.php?token='.rawurlencode((string)$draft['media_token']);
    $payload=(new PinterestPublisher())->imagePinPayload(['title'=>$draft['title'],'description'=>$draft['description']],$draft,(string)$draft['board_id'],(string)$draft['destination_url'],$image);
    $pinterest=new PinterestIntegrationService(Database::connection());$result=$pinterest->createPin($userId,$payload,(string)$draft['purpose']);$pinId=(string)($result['id']??'');if($pinId==='')throw new RuntimeException('Pinterest did not return a Pin ID.');$url='https://www.pinterest.com/pin/'.rawurlencode($pinId).'/';$drafts->markPublished($id,$userId,$pinId,$url,$payload);$_SESSION['pinterest_publish_notice']='One Pin was published successfully.';
}catch(Throwable $e){if($id>0){try{$drafts->markFailed($id,$userId,$e->getMessage());}catch(Throwable){}}$_SESSION['pinterest_publish_error']=$e->getMessage();}
header('Location: pinterest_draft_review.php?id='.$id);exit;
