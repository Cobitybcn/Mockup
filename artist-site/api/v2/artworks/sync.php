<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/inc/LocalEnv.php';
require_once dirname(__DIR__,3).'/inc/ArtworkSyncV2Authenticator.php';
require_once dirname(__DIR__,3).'/inc/ArtistCatalogV2Repository.php';
load_local_env(dirname(__DIR__,3).'/.env');header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);exit;}
$raw=(string)file_get_contents('php://input');if($raw===''||strlen($raw)>2_000_000){http_response_code(413);echo json_encode(['ok'=>false,'error'=>'invalid_payload_size']);exit;}
try{
    (new ArtworkSyncV2Authenticator((string)(getenv('ARTWORK_SYNC_SHARED_SECRET')?:'')))->verify($raw,(string)($_SERVER['HTTP_X_AMW_TIMESTAMP']??''),(string)($_SERVER['HTTP_X_AMW_SIGNATURE']??''));
    $document=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($document))throw new InvalidArgumentException('JSON object required.');
    $catalogRoot=(string)(getenv('ARTIST_CATALOG_V2_ROOT')?:dirname(__DIR__,3).'/data/catalog-v2');
    $result=(new ArtistCatalogV2Repository($catalogRoot))->saveEditorial($document);
    echo json_encode(['ok'=>true,'status'=>$result['changed']?'updated':'unchanged']+$result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(JsonException){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'invalid_json']);}
catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
