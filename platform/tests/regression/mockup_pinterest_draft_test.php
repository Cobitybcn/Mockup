<?php
declare(strict_types=1);

define('ANALYSIS_DIR',sys_get_temp_dir().DIRECTORY_SEPARATOR.'no-real-analysis');
define('RESULTS_DIR',sys_get_temp_dir().DIRECTORY_SEPARATOR.'pinterest-draft-fixture');
final class Auth{public static function isAdmin(?array $user=null):bool{return (int)($user['is_admin']??0)===1;}}
final class ArtistProfile{public static function findForUser(int $id):array{return ['recurring_themes'=>'territory; silence','visual_language'=>'structural abstraction'];}}
final class Display{public static function contextTitle(string $id):string{return 'Collector Loft';}}
require_once __DIR__.'/../../app/Services/MockupEditorialContent.php';
require_once __DIR__.'/../../app/Services/MockupPinterestDraftService.php';
require_once __DIR__.'/../../app/Services/PinterestPublisher.php';
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE artworks(id INTEGER PRIMARY KEY,user_id INTEGER,root_file TEXT,main_file TEXT,final_title TEXT,subtitle TEXT)');
$pdo->exec('CREATE TABLE mockups(id INTEGER PRIMARY KEY,user_id INTEGER,source_artwork_id INTEGER,artwork_file TEXT,mockup_file TEXT,context_id TEXT)');
$pdo->exec('CREATE TABLE artwork_analysis(id INTEGER PRIMARY KEY,artwork_id INTEGER,analysis_json TEXT)');
$pdo->exec("CREATE TABLE mockup_sheets(id INTEGER PRIMARY KEY,user_id INTEGER,mockup_file TEXT,title TEXT DEFAULT '',description TEXT DEFAULT '',keywords TEXT DEFAULT '',tags TEXT DEFAULT '',alt_text TEXT DEFAULT '',caption TEXT DEFAULT '',generated_json TEXT DEFAULT '')");
$pdo->exec("CREATE TABLE pinterest_pin_drafts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,mockup_id INTEGER,purpose TEXT,board_suggestion TEXT,board_id TEXT NOT NULL DEFAULT '',board_name TEXT NOT NULL DEFAULT '',board_section_id TEXT NOT NULL DEFAULT '',board_section_name TEXT NOT NULL DEFAULT '',title TEXT,description TEXT,alt_text TEXT,keywords TEXT,hashtags TEXT,destination_url TEXT,status TEXT,payload_json TEXT,media_token TEXT NOT NULL DEFAULT '',external_id TEXT NOT NULL DEFAULT '',external_url TEXT NOT NULL DEFAULT '',error TEXT NOT NULL DEFAULT '',variant_file TEXT NOT NULL DEFAULT '',variant_width INTEGER NOT NULL DEFAULT 0,variant_height INTEGER NOT NULL DEFAULT 0,created_at TEXT,updated_at TEXT)");
$pdo->exec("INSERT INTO artworks VALUES(10,1,'root.jpg','root.jpg','Silent Territory','A Structural Field')");$pdo->exec("INSERT INTO mockups VALUES(20,1,10,'root.jpg','mockup.jpg','collector_loft')");$analysis=json_encode(['artwork_profile'=>['style_tags'=>['structural','abstract'],'mood_tags'=>['contemplative'],'palette'=>['sober tones']]]);$stmt=$pdo->prepare('INSERT INTO artwork_analysis VALUES(1,10,?)');$stmt->execute([$analysis]);
$service=new MockupPinterestDraftService($pdo);$draft=$service->create(20,['id'=>1,'is_admin'=>1],'platform','https://artworkmockups.com/examples/silent-territory');$row=$pdo->query('SELECT * FROM pinterest_pin_drafts')->fetch(PDO::FETCH_ASSOC);$boards=[['id'=>'b1','name'=>'Art for Interiors'],['id'=>'b2','name'=>'Contemporary Abstract Art']];$recommended=$service->recommendBoard($row,$boards);$selected=$service->selectBoard((int)$draft['id'],1,'b2',$boards);$selected=$service->selectSection((int)$draft['id'],1,'s1',[['id'=>'s1','name'=>'Wall Art']]);
$blocked=false;try{$service->create(20,['id'=>1,'is_admin'=>1],'platform','http://localhost/private');}catch(InvalidArgumentException){$blocked=true;}
$apiPayload=(new PinterestPublisher())->imagePinPayload(['title'=>$selected['title'],'description'=>$selected['description']],$selected,'b2',$selected['destination_url'],'https://artworkmockups.com/pinterest_draft_media.php?token='.$selected['media_token']);
$checks=[(int)$draft['id']===1,$row['purpose']==='platform',$row['title']==='Silent Territory — Collector Loft',$row['destination_url']==='https://artworkmockups.com/examples/silent-territory',$row['status']==='draft',strlen($row['media_token'])===64,($recommended['id']??'')==='b2',$selected['board_id']==='b2',$selected['board_section_id']==='s1',$selected['status']==='board_selected',($apiPayload['board_section_id']??'')==='s1',$blocked];
if(in_array(false,$checks,true)){fwrite(STDERR,"FAIL: Pinterest mockup draft checks ".json_encode(array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok))).".\n");exit(1);}echo "PASS: exact viewer content is stored as a platform draft and coordinated with a reviewed board; no Pinterest call is made.\n";
