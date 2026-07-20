<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$slug=trim((string)($_GET['slug']??'')); $file=basename((string)($_GET['file']??''));
try { $p=(new PublicationService(Database::connection()))->publicBySlug($slug); } catch(Throwable $e){ http_response_code(404); exit; }
$allowed=[basename((string)$p['source_image_file'])]; foreach($p['items'] as $item){$allowed[]=basename((string)$item['mockup_file']);}
$viewStmt=Database::connection()->prepare('SELECT file_name FROM root_artwork_candidates WHERE artwork_id=(SELECT canonical_artwork_id FROM artwork_sheets WHERE id=? AND user_id=? LIMIT 1)');
$viewStmt->execute([(int)$p['artwork_sheet_id'],(int)$p['user_id']]);
foreach($viewStmt->fetchAll(PDO::FETCH_COLUMN) as $viewFile){$allowed[]=basename((string)$viewFile);}
$mockupStmt=Database::connection()->prepare('SELECT m.mockup_file
    FROM artwork_sheets sh
    INNER JOIN artworks a ON a.id=sh.canonical_artwork_id AND a.user_id=sh.user_id
    INNER JOIN mockup_sheets m ON m.user_id=sh.user_id AND (
        m.artwork_id=a.id OR m.artwork_sheet_id=sh.id OR (a.artwork_group_id>0 AND m.artwork_group_id=a.artwork_group_id)
    )
    WHERE sh.id=? AND sh.user_id=?');
$mockupStmt->execute([(int)$p['artwork_sheet_id'],(int)$p['user_id']]);
foreach($mockupStmt->fetchAll(PDO::FETCH_COLUMN) as $mockupFile){$allowed[]=basename((string)$mockupFile);}
if($file==='' || !in_array($file,$allowed,true)){http_response_code(403);exit;}
$path=RESULTS_DIR.DIRECTORY_SEPARATOR.$file;
if(!is_file($path)){ $upload=__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.$file; if(is_file($upload))$path=$upload; }
if(!is_file($path) && StorageService::isGcsActive()){StorageService::downloadFile('results/'.$file,$path);}
if(!is_file($path)){http_response_code(404);exit;}
$mime=@mime_content_type($path)?:'application/octet-stream'; header('Content-Type: '.$mime); header('Content-Length: '.filesize($path)); header('Cache-Control: public, max-age=86400'); header('X-Content-Type-Options: nosniff'); readfile($path);
