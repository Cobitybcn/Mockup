<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    $user=Auth::requireUser();Auth::start();$uid=(int)$user['id'];
    if(!hash_equals((string)($_SESSION['pinterest_batch_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('Review session expired. Reload the batch.');
    $batchId=max(0,(int)($_POST['id']??0));$draftId=max(0,(int)($_POST['draft_id']??0));
    $pdo=Database::connection();$drafts=new MockupPinterestDraftService($pdo);$batches=new PinterestBatchService($pdo,$drafts);$pinterest=new PinterestIntegrationService($pdo);
    $batch=$batches->batch($batchId,$uid);$items=$batches->items($batchId,$uid);
    if(!in_array($draftId,array_map(static fn($row)=>(int)$row['id'],$items),true))throw new RuntimeException('Draft does not belong to this batch.');
    $draft=$drafts->draft($draftId,$uid);$action=(string)($_POST['action']??'');
    if($action==='save_destination')$drafts->updateDestination($draftId,$uid,trim((string)($_POST['destination_url']??'')));
    elseif($action==='save_content')$drafts->updateContent($draftId,$uid,(string)($_POST['title']??''),(string)($_POST['description']??''),(string)($_POST['alt_text']??''));
    elseif($action==='save_crop')$drafts->saveCrop($draftId,$uid,(float)($_POST['crop_x']??.5),(float)($_POST['crop_y']??.5),(float)($_POST['crop_zoom']??1));
    elseif($action==='save_boards')$batches->selectBoards($draft,$uid,(array)($_POST['board_ids']??[]),$pinterest->boards($uid,(string)$batch['purpose']));
    else throw new RuntimeException('Unknown batch action.');
    echo json_encode(['ok'=>true]);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
