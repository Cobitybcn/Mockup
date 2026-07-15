<?php
declare(strict_types=1);

final class ArtworkWebsiteV2Service
{
    public function __construct(private readonly PDO $pdo) {}

    public function buildContract(int $publicationId,int $userId): array
    {
        $legacy=(new ArtworkWebsiteDryRunService($this->pdo))->buildPayload($publicationId,$userId);$source=(array)$legacy['source'];$art=(array)$legacy['artwork'];$core=(array)$legacy['editorial_core'];$assets=(array)$legacy['assets'];
        return ['schema_version'=>'2.0','operation'=>'upsert_editorial','request_id'=>(string)$legacy['request_id'],'idempotency_key'=>(string)$legacy['idempotency_key'],
            'identity'=>['source'=>'artwork_mockups','source_artwork_id'=>'amw:'.(int)$source['artwork_id'],'editorial_revision'=>max(1,(int)strtotime((string)$source['revision'])),'analysis_version'=>'2'],
            'artwork_facts'=>$art,'editorial'=>['language'=>(string)$core['language'],'subtitle'=>(string)$core['subtitle'],'summary'=>(string)$core['summary'],'concept'=>(string)$core['concept'],'commercial_note'=>(string)$core['commercial_note'],'alt_text'=>(string)$core['alt_text'],'caption'=>(string)$core['caption'],'keywords'=>(array)$core['keywords'],'tags'=>(array)$core['tags']],
            'assets'=>['artwork'=>(array)$assets['artwork'],'details'=>[],'mockups'=>(array)$assets['mockups']]];
    }

    public function send(array $contract,?string $endpoint=null): array
    {
        $endpoint??=app_env('ARTWORK_WEBSITE_V2_ENDPOINT','');$secret=app_env('ARTWORK_SYNC_SHARED_SECRET','');if($endpoint===''||strlen($secret)<32)throw new RuntimeException('Website V2 endpoint and shared secret are required.');
        $raw=json_encode($contract,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if($raw===false)throw new RuntimeException('Cannot encode website contract.');$timestamp=(string)time();$signature=hash_hmac('sha256',$timestamp."\n".$raw,$secret);
        $ch=curl_init($endpoint);if($ch===false)throw new RuntimeException('Cannot initialize website sync.');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-AMW-Timestamp: '.$timestamp,'X-AMW-Signature: '.$signature],CURLOPT_POSTFIELDS=>$raw,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($body===false)throw new RuntimeException('Website sync failed: '.$error);$decoded=json_decode($body,true);
        if(!is_array($decoded)||$status>=400||empty($decoded['ok']))throw new RuntimeException('Website rejected V2 sync: '.(is_array($decoded)?(string)($decoded['error']??'unknown error'):'invalid response'));return $decoded;
    }
}
