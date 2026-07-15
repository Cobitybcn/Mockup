<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/ArtworkDraftPublisher.php';
$root=sys_get_temp_dir().'/artwork-publisher-'.bin2hex(random_bytes(4));
mkdir($root.'/data/artworks',0777,true);mkdir($root.'/assets/uploads',0777,true);mkdir($root.'/source',0777,true);
$pixel=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
foreach(['root.png','mockup-1.png','mockup-2.png'] as $file)file_put_contents($root.'/source/'.$file,$pixel);
$asset=fn($file,$id)=>['asset_id'=>$id,'source_file'=>$file,'sha256'=>hash_file('sha256',$root.'/source/'.$file),'alt_text'=>$id];
$id=str_repeat('a',64);$drafts=[$id=>['draft_id'=>$id,'status'=>'validated_draft','source_payload'=>['artwork'=>['slug'=>'publisher-test','title'=>'Publisher Test','year'=>2026,'series'=>'strata','medium'=>'Acrylic on canvas','orientation'=>'vertical','dimensions'=>['width'=>80,'height'=>120,'depth'=>3]],'editorial_core'=>['summary'=>'Summary','concept'=>'Concept'],'assets'=>['artwork'=>$asset('root.png','root:1'),'mockups'=>[$asset('mockup-1.png','mockup:1'),$asset('mockup-2.png','mockup:2')]]]]];
$draftFile=$root.'/drafts.json';file_put_contents($draftFile,json_encode($drafts));$result=(new ArtworkDraftPublisher($root.'/source',$draftFile,$root))->publish($id);
$catalog=json_decode((string)file_get_contents($root.'/data/artworks/publisher-test.json'),true);
if(empty($result['ok'])||($result['writes']['content_json']??true)!==false||($catalog['title']??'')!=='Publisher Test'||count($catalog['mockups']??[])!==2)throw new RuntimeException('Publisher assertion failed.');
echo "PASS: validated draft becomes one artwork file with copied media; content.json remains untouched.\n";
