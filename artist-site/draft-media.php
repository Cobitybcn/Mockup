<?php
declare(strict_types=1);

$remote=(string)($_SERVER['REMOTE_ADDR']??'');
if(PHP_SAPI!=='cli'&&!in_array($remote,['127.0.0.1','::1'],true)){http_response_code(403);exit;}
$draftId=trim((string)($_GET['draft']??''));$assetId=trim((string)($_GET['asset']??''));
$draftFile=__DIR__.'/data/drafts/artwork-sync-drafts.json';$drafts=is_file($draftFile)?json_decode((string)file_get_contents($draftFile),true):[];$draft=is_array($drafts)?($drafts[$draftId]??null):null;
if(!is_array($draft)){http_response_code(404);exit;}
$assets=(array)($draft['source_payload']['assets']??[]);$candidates=[];$root=(array)($assets['artwork']??[]);if(($root['asset_id']??'')===$assetId)$candidates[]=$root;foreach((array)($assets['mockups']??[]) as $asset)if(is_array($asset)&&($asset['asset_id']??'')===$assetId)$candidates[]=$asset;
$asset=$candidates[0]??null;$fileName=is_array($asset)?basename((string)($asset['source_file']??'')):'';$sourceRoot=dirname(__DIR__).'/mockups/results';$path=$fileName!==''?$sourceRoot.DIRECTORY_SEPARATOR.$fileName:'';
if($path===''||!is_file($path)||!hash_equals((string)($asset['sha256']??''),(string)hash_file('sha256',$path))){http_response_code(404);exit;}
$mime=(string)(mime_content_type($path)?:'application/octet-stream');header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('Cache-Control: private, max-age=60');readfile($path);
