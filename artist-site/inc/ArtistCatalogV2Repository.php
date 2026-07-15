<?php
declare(strict_types=1);

final class ArtistCatalogV2Repository
{
    public function __construct(private readonly string $root) {}

    public function saveEditorial(array $document): array
    {
        $id=$this->sourceId($document);$this->assertEditorialDocument($document);
        $path=$this->path('editorial',$id);$encoded=$this->encode($document);
        $unchanged=is_file($path)&&hash_equals(hash('sha256',(string)file_get_contents($path)),hash('sha256',$encoded));
        if(!$unchanged)$this->atomicWrite($path,$encoded);
        $state=['source_artwork_id'=>$id,'editorial_revision'=>(int)$document['identity']['editorial_revision'],'editorial_hash'=>hash('sha256',$encoded),'synced_at'=>date(DATE_ATOM)];
        $this->atomicWrite($this->path('sync-state',$id),$this->encode($state));
        return ['source_artwork_id'=>$id,'changed'=>!$unchanged,'editorial_hash'=>$state['editorial_hash']];
    }

    public function saveCommerce(string $sourceArtworkId,array $commerce): void
    {
        $id=$this->validId($sourceArtworkId);$allowed=['status','visibility','price','currency','sale_mode','sort_order','pinned','location'];
        $unknown=array_diff(array_keys($commerce),$allowed);if($unknown)throw new InvalidArgumentException('Unknown commerce fields: '.implode(', ',$unknown));
        $status=(string)($commerce['status']??'available');if(!in_array($status,['available','reserved','sold','not_for_sale','archived'],true))throw new InvalidArgumentException('Invalid commerce status.');
        $commerce['source_artwork_id']=$id;$commerce['status']=$status;$commerce['updated_at']=date(DATE_ATOM);
        $this->atomicWrite($this->path('commerce',$id),$this->encode($commerce));
    }

    public function combined(string $sourceArtworkId): array
    {
        $id=$this->validId($sourceArtworkId);$editorial=$this->read($this->path('editorial',$id));if(!$editorial)throw new RuntimeException('Editorial artwork not found.');
        return ['source_artwork_id'=>$id,'artwork_facts'=>(array)$editorial['artwork_facts'],'editorial'=>(array)$editorial['editorial'],'assets'=>(array)$editorial['assets'],'commerce'=>$this->read($this->path('commerce',$id))];
    }

    public function allCombined(): array
    {
        $items=[];$dir=rtrim($this->root,'/\\').DIRECTORY_SEPARATOR.'editorial';
        foreach(glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[] as $file){$document=$this->read($file);$id=(string)($document['identity']['source_artwork_id']??'');if($id==='')continue;try{$items[]=$this->combined($id);}catch(Throwable){}}
        usort($items,static fn(array $a,array $b):int=>((int)($a['commerce']['sort_order']??PHP_INT_MAX)<=> (int)($b['commerce']['sort_order']??PHP_INT_MAX))?:strcasecmp((string)($a['artwork_facts']['title']??''),(string)($b['artwork_facts']['title']??'')));
        return $items;
    }

    private function assertEditorialDocument(array $d): void
    {
        if(($d['schema_version']??'')!=='2.0'||($d['operation']??'')!=='upsert_editorial')throw new InvalidArgumentException('Editorial contract 2.0 is required.');
        foreach(['identity','artwork_facts','editorial','assets'] as $key)if(!is_array($d[$key]??null))throw new InvalidArgumentException($key.' is required.');
        foreach(['price','currency','status','sale_mode','location','commerce'] as $forbidden)if(array_key_exists($forbidden,$d)||array_key_exists($forbidden,$d['editorial']))throw new InvalidArgumentException('Commercial field is forbidden in editorial sync: '.$forbidden);
        if((int)($d['identity']['editorial_revision']??0)<1)throw new InvalidArgumentException('A positive editorial revision is required.');
    }

    private function sourceId(array $d): string{return $this->validId((string)($d['identity']['source_artwork_id']??''));}
    private function validId(string $id): string{if(!preg_match('/^[a-z0-9]+:[a-z0-9._-]+$/i',$id))throw new InvalidArgumentException('Invalid source_artwork_id.');return strtolower($id);}
    private function path(string $type,string $id): string{return rtrim($this->root,'/\\').DIRECTORY_SEPARATOR.$type.DIRECTORY_SEPARATOR.str_replace(':','--',$id).'.json';}
    private function read(string $path): array{$data=is_file($path)?json_decode((string)file_get_contents($path),true):[];return is_array($data)?$data:[];}
    private function encode(array $data): string{$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($json===false)throw new RuntimeException('Cannot encode catalog document.');return $json.PHP_EOL;}
    private function atomicWrite(string $path,string $contents): void{$dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create catalog directory.');$tmp=$path.'.tmp';if(file_put_contents($tmp,$contents,LOCK_EX)===false||!rename($tmp,$path))throw new RuntimeException('Cannot write catalog document.');}
}
