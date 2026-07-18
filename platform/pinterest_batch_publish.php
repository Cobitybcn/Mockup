<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
$user=Auth::requireUser();FeatureAccess::requirePage($user,FeatureAccess::SOCIAL_MANAGE,'Social Media');Auth::start();$uid=(int)$user['id'];$id=max(0,(int)($_POST['id']??0));$pdo=Database::connection();$drafts=new MockupPinterestDraftService($pdo);$batches=new PinterestBatchService($pdo,$drafts);
try{
    if(app_env('PINTEREST_LIVE_PUBLISH_ENABLED','false')!=='true'||app_env('PINTEREST_DRAFT_PUBLIC_MEDIA_ENABLED','false')!=='true')throw new RuntimeException('Live Pinterest publication is disabled.');
    $csrf=(string)($_SESSION['pinterest_batch_publish_csrf']??'');unset($_SESSION['pinterest_batch_publish_csrf']);
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))||($_POST['confirm']??'')!=='yes')throw new RuntimeException('Explicit batch confirmation is required.');
    $batch=$batches->batch($id,$uid);$pinterest=new PinterestIntegrationService($pdo);$published=0;$failed=0;$base=rtrim(app_env('APP_PUBLIC_URL',''),'/');
    foreach($batches->items($id,$uid) as $draft){$validDestination=filter_var((string)$draft['destination_url'],FILTER_VALIDATE_URL)&&strtolower((string)parse_url((string)$draft['destination_url'],PHP_URL_SCHEME))==='https';if(($draft['variant_file']??'')===''||!$validDestination)continue;foreach($batches->destinations((int)$draft['id']) as $destination){if(($destination['status']??'')==='published')continue;try{$image=$base.'/pinterest_draft_media.php?token='.rawurlencode((string)$draft['media_token']);$payload=(new PinterestPublisher())->imagePinPayload(['title'=>$draft['title'],'description'=>$draft['description']],$draft,(string)$destination['board_id'],(string)$draft['destination_url'],$image);$result=$pinterest->createPin($uid,$payload,(string)$draft['purpose']);$pinId=(string)($result['id']??'');if($pinId==='')throw new RuntimeException('Pinterest did not return a Pin ID.');$batches->markDestination((int)$destination['id'],'published',$pinId,'https://www.pinterest.com/pin/'.rawurlencode($pinId).'/');$published++;}catch(Throwable $e){$batches->markDestination((int)$destination['id'],'failed','','',$e->getMessage());$failed++;}}}
    if($published===0&&$failed===0)throw new RuntimeException('No card is ready. Each card needs a valid destination, at least one board and a vertical crop.');
    $needsAttention=$failed>0;
    foreach($batches->items($id,$uid) as $reviewedDraft){
        $reviewedDestinations=$batches->destinations((int)$reviewedDraft['id']);
        $validDestination=filter_var((string)$reviewedDraft['destination_url'],FILTER_VALIDATE_URL)
            &&strtolower((string)parse_url((string)$reviewedDraft['destination_url'],PHP_URL_SCHEME))==='https';
        if(($reviewedDraft['variant_file']??'')===''||!$validDestination||!$reviewedDestinations){$needsAttention=true;continue;}
        foreach($reviewedDestinations as $reviewedDestination)if(($reviewedDestination['status']??'')!=='published')$needsAttention=true;
    }
    $batchStatus=$needsAttention?'needs_attention':'published';
    $pdo->prepare('UPDATE pinterest_batches SET status=?,updated_at=? WHERE id=? AND user_id=?')->execute([$batchStatus,date('c'),$id,$uid]);
    (new SocialCampaignPinterestBridge($pdo))->markBatchOutcome($id,$uid,$batchStatus);
    $_SESSION['pinterest_batch_notice']=$published.' Pins published; '.$failed.' failed.'.($needsAttention?' Review the remaining cards.':' Campaign complete.');
}catch(Throwable $e){$_SESSION['pinterest_batch_error']=$e->getMessage();}
header('Location: pinterest_batch_review.php?id='.$id);exit;
