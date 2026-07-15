<?php
declare(strict_types=1);

final class ArtworkDraftPublisher
{
    public function __construct(
        private readonly string $sourceDirectory,
        private readonly string $draftFile,
        private readonly string $siteRoot
    ) {}

    public function publish(string $draftId): array
    {
        $drafts=$this->readJson($this->draftFile);$draft=$drafts[$draftId]??null;
        if(!is_array($draft)||($draft['status']??'')!=='validated_draft')throw new RuntimeException('A validated website draft is required.');
        $payload=(array)($draft['source_payload']??[]);$art=(array)($payload['artwork']??[]);$core=(array)($payload['editorial_core']??[]);$assets=(array)($payload['assets']??[]);
        $slug=(string)($art['slug']??'');if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug))throw new RuntimeException('Invalid artwork slug.');
        $uploads=$this->siteRoot.'/assets/uploads';$catalog=$this->siteRoot.'/data/artworks';
        foreach([$uploads,$catalog] as $dir)if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create website publication directory.');
        $root=$this->copyAsset((array)($assets['artwork']??[]),$slug.'-artwork',$uploads);
        $mockups=[];foreach(array_values((array)($assets['mockups']??[])) as $i=>$asset){if(!is_array($asset))continue;$path=$this->copyAsset($asset,$slug.'-mockup-'.($i+1),$uploads);$mockups[]=['image'=>$path,'alt'=>(string)($asset['alt_text']??$art['title'].' in context'),'caption'=>(string)($asset['caption']??'')];}
        $d=(array)($art['dimensions']??[]);$values=array_filter([(float)($d['width']??0),(float)($d['height']??0),(float)($d['depth']??0)],fn($v)=>$v>0);$fmt=fn(float $v)=>rtrim(rtrim(number_format($v,1,'.',''),'0'),'.');
        $entry=['title'=>(string)$art['title'],'year'=>(string)$art['year'],'series'=>(string)($art['series']?:'structural-metaphysical-painting'),'status'=>'available','medium'=>(string)$art['medium'],
            'dimensions_cm'=>implode(' x ',array_map($fmt,$values)).' cm','dimensions_in'=>implode(' x ',array_map(fn($v)=>$fmt($v/2.54),$values)).' in','orientation'=>ucfirst((string)($art['orientation']??'')),
            'image'=>$root,'detail_image'=>'','detail_images'=>[],'mockups'=>$mockups,'price'=>'Inquire','currency'=>'EUR','purchase_url'=>'','sale_platform'=>'','sale_result'=>'',
            'summary'=>(string)($core['summary']??''),'concept'=>(string)($core['concept']??''),'commercial_note'=>(string)($core['commercial_note']??'Original painting with certificate of authenticity.'),'sort_order'=>time(),'pinned'=>0];
        $target=$catalog.'/'.$slug.'.json';$encoded=json_encode($entry,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;if($encoded===false)throw new RuntimeException('Cannot encode artwork catalog entry.');
        if(is_file($target))copy($target,$target.'.bak');$tmp=$target.'.tmp';if(file_put_contents($tmp,$encoded,LOCK_EX)===false||!rename($tmp,$target))throw new RuntimeException('Cannot write artwork catalog entry.');
        $drafts[$draftId]['status']='published';$drafts[$draftId]['published_at']=date(DATE_ATOM);$drafts[$draftId]['website_url']='/paintings/'.$slug.'/';
        $this->writeJson($this->draftFile,$drafts);
        return ['ok'=>true,'status'=>'published','draft_id'=>$draftId,'slug'=>$slug,'website_url'=>'/paintings/'.$slug.'/','writes'=>['content_json'=>false,'artwork_catalog'=>true,'media'=>true]];
    }

    private function copyAsset(array $asset,string $name,string $uploads): string
    {
        $file=basename((string)($asset['source_file']??''));$source=$this->sourceDirectory.'/'.$file;
        if($file===''||!is_file($source)||!hash_equals((string)($asset['sha256']??''),(string)hash_file('sha256',$source))||@getimagesize($source)===false)throw new RuntimeException('A publication image is missing or changed.');
        $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));$target=$uploads.'/'.$name.'.'.$ext;
        if(!is_file($target)||!hash_equals((string)hash_file('sha256',$source),(string)hash_file('sha256',$target))){$tmp=$target.'.tmp';if(!copy($source,$tmp)||!rename($tmp,$target))throw new RuntimeException('Cannot copy publication image.');}
        return '/assets/uploads/'.basename($target);
    }

    private function readJson(string $file): array{$data=is_file($file)?json_decode((string)file_get_contents($file),true):[];return is_array($data)?$data:[];}
    private function writeJson(string $file,array $data): void{$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;$tmp=$file.'.tmp';if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$file))throw new RuntimeException('Cannot update draft state.');}
}
