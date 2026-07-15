<?php
declare(strict_types=1);
require_once __DIR__.'/../../app/Services/MockupPinterestDraftService.php';
require_once __DIR__.'/../../app/Services/PinterestBatchService.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE pinterest_pin_destinations(id INTEGER PRIMARY KEY AUTOINCREMENT,draft_id INTEGER,user_id INTEGER,mockup_id INTEGER,purpose TEXT,board_id TEXT,board_name TEXT,status TEXT,external_id TEXT DEFAULT '',external_url TEXT,error TEXT,created_at TEXT,updated_at TEXT,UNIQUE(draft_id,board_id))");
$service=new PinterestBatchService($pdo,new MockupPinterestDraftService($pdo));$draft=['id'=>7,'mockup_id'=>42,'purpose'=>'platform'];$boards=[['id'=>'a','name'=>'Abstract Painting'],['id'=>'b','name'=>'Wall Art'],['id'=>'c','name'=>'Studio'],['id'=>'d','name'=>'Collectors']];
$service->selectBoards($draft,1,['a','b','c'],$boards);if(count($service->destinations(7))!==3)throw new RuntimeException('Expected three selected boards.');
$failed=false;try{$service->selectBoards($draft,1,['a','b','c','d'],$boards);}catch(InvalidArgumentException){$failed=true;}if(!$failed)throw new RuntimeException('Four boards must be rejected.');
$service->markDestination((int)$service->destinations(7)[0]['id'],'published','pin-1','https://pinterest.com/pin/1/');if(count($service->publishedBoardIds(1,42,'platform'))!==1)throw new RuntimeException('Published board history missing.');
echo "PASS: Pinterest batches allow up to three boards and preserve publication history.\n";
