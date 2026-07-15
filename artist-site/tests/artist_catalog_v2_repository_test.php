<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/ArtistCatalogV2Repository.php';
$root=sys_get_temp_dir().'/artist-catalog-v2-'.bin2hex(random_bytes(4));$repo=new ArtistCatalogV2Repository($root);
$document=['schema_version'=>'2.0','operation'=>'upsert_editorial','identity'=>['source'=>'artwork_mockups','source_artwork_id'=>'amw:10002','editorial_revision'=>1,'analysis_version'=>'2'],'artwork_facts'=>['slug'=>'echoes-in-blue','title'=>'Echoes in Blue'],'editorial'=>['summary'=>'First version'],'assets'=>['artwork'=>[],'details'=>[],'mockups'=>[]]];
$repo->saveEditorial($document);$repo->saveCommerce('amw:10002',['status'=>'available','price'=>2400,'currency'=>'EUR','sale_mode'=>'inquiry','location'=>['show_on_map'=>true,'type'=>'assigned','country'=>'Spain']]);
$document['identity']['editorial_revision']=2;$document['editorial']['summary']='Revised editorial';$repo->saveEditorial($document);$combined=$repo->combined('amw:10002');
$checks=[($combined['editorial']['summary']??'')==='Revised editorial',($combined['commerce']['price']??0)===2400,($combined['commerce']['status']??'')==='available',($combined['commerce']['location']['type']??'')==='assigned'];
$blocked=false;try{$document['editorial']['price']=99;$repo->saveEditorial($document);}catch(InvalidArgumentException){$blocked=true;}$checks[]=$blocked;
if(in_array(false,$checks,true)){fwrite(STDERR,"FAIL: catalog V2 separation checks failed.\n");exit(1);}echo "PASS: editorial resync preserves commerce and rejects commercial fields.\n";
