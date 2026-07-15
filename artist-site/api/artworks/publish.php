<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/inc/ArtworkDraftPublisher.php';
header('Content-Type: application/json; charset=utf-8');
$remote=(string)($_SERVER['REMOTE_ADDR']??'');if(PHP_SAPI!=='cli'&&!in_array($remote,['127.0.0.1','::1'],true)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'local_only']);exit;}
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);exit;}
try{$input=json_decode((string)file_get_contents('php://input'),true,8,JSON_THROW_ON_ERROR);$id=trim((string)($input['draft_id']??''));if(!preg_match('/^[a-f0-9]{64}$/',$id))throw new InvalidArgumentException('Invalid draft ID.');
    $source=getenv('ARTWORK_SYNC_SOURCE_DIR')?:dirname(__DIR__,3).'/mockups/results';$draft=getenv('ARTWORK_SYNC_DRAFT_FILE')?:dirname(__DIR__,2).'/data/drafts/artwork-sync-drafts.json';
    echo json_encode((new ArtworkDraftPublisher($source,$draft,dirname(__DIR__,2)))->publish($id),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
