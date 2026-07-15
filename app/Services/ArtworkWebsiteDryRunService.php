<?php
declare(strict_types=1);

final class ArtworkWebsiteDryRunService
{
    public function __construct(private readonly PDO $pdo) {}

    public function buildPayload(int $publicationId, int $userId): array
    {
        $publication = (new PublicationService($this->pdo))->get($publicationId, $userId);
        $stmt = $this->pdo->prepare('SELECT s.*, a.id AS artwork_id, a.final_title, a.artwork_year, a.series, a.width, a.height, a.depth, a.unit, a.root_file, a.main_file
            FROM artwork_sheets s JOIN artworks a ON a.id=s.canonical_artwork_id
            WHERE s.id=? AND s.user_id=? AND a.user_id=? LIMIT 1');
        $stmt->execute([(int)$publication['artwork_sheet_id'], $userId, $userId]);
        $sheet = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sheet) throw new RuntimeException('Artwork sheet not found.');
        $root = basename((string)($sheet['source_image_file'] ?: $sheet['root_file'] ?: $sheet['main_file']));
        $width = (float)$sheet['width']; $height = (float)$sheet['height'];
        $mockups = [];
        foreach ($publication['items'] as $position => $item) {
            $file = basename((string)$item['mockup_file']);
            $mockups[] = $this->asset($file, 'mockup:' . (int)$item['mockup_sheet_id'], [
                'position'=>$position, 'role'=>$position === 0 ? 'cover' : 'context', 'title'=>(string)$item['title'],
                'alt_text'=>(string)$item['alt_text'], 'caption'=>(string)$item['caption'],
            ]);
        }
        return [
            'schema_version'=>'1.0','mode'=>'dry_run','request_id'=>$this->uuidV4(),
            'idempotency_key'=>'publication:' . $publicationId . ':revision:' . (string)$publication['updated_at'],
            'source'=>['application'=>'artwork_mockups','artwork_id'=>(int)$sheet['artwork_id'],'artwork_sheet_id'=>(int)$sheet['id'],'publication_id'=>$publicationId,'revision'=>(string)$publication['updated_at']],
            'artwork'=>[
                'slug'=>(string)$publication['slug'], 'title'=>(string)($sheet['title'] ?: $publication['title']),
                'year'=>(int)($sheet['artwork_year'] ?: date('Y')), 'series'=>(string)$sheet['series'], 'status'=>'draft',
                'medium'=>'Acrylic on canvas', 'dimensions'=>['width'=>$width,'height'=>$height,'depth'=>(float)$sheet['depth'],'unit'=>'cm'],
                'orientation'=>$width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square'),
            ],
            'editorial_core'=>[
                'language'=>(string)$publication['language'], 'objective'=>(string)$publication['objective'], 'subtitle'=>(string)$sheet['subtitle'],
                'summary'=>(string)($sheet['short_description'] ?: $publication['short_description']), 'concept'=>(string)($sheet['description'] ?: $publication['description']),
                'commercial_note'=>'Original painting with certificate of authenticity.', 'alt_text'=>(string)$sheet['alt_text'], 'caption'=>(string)$sheet['caption'],
                'keywords'=>$this->csv((string)$sheet['keywords']), 'tags'=>$this->csv((string)$sheet['tags']),
            ],
            'assets'=>['artwork'=>$this->asset($root, 'root:' . (int)$sheet['artwork_id'], ['alt_text'=>(string)$sheet['alt_text']]), 'mockups'=>$mockups],
        ];
    }

    public function send(array $payload, ?string $endpoint = null): array
    {
        $endpoint ??= getenv('ARTWORK_WEBSITE_SYNC_ENDPOINT') ?: 'http://127.0.0.1/maurizio-website-new/api/artworks/sync.php';
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['host'] ?? '')), ['127.0.0.1','localhost','::1'], true)) throw new RuntimeException('Dry-run endpoint must be local.');
        $ch = curl_init($endpoint);
        if ($ch === false) throw new RuntimeException('Unable to initialize local request.');
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10]);
        $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if ($body === false) throw new RuntimeException('Local dry run failed: ' . $error);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new RuntimeException('Local endpoint returned invalid JSON.');
        if ($status >= 400 || empty($decoded['ok'])) throw new RuntimeException('Website rejected the draft: ' . json_encode($decoded['validation']['errors'] ?? $decoded));
        return $decoded;
    }

    public function publishValidatedDraft(string $draftId, ?string $endpoint = null): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $draftId)) throw new RuntimeException('Website returned an invalid draft ID.');
        $endpoint ??= getenv('ARTWORK_WEBSITE_PUBLISH_ENDPOINT') ?: 'http://127.0.0.1/maurizio-website-new/api/artworks/publish.php';
        $parts=parse_url($endpoint);if(!is_array($parts)||!in_array(strtolower((string)($parts['host']??'')),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Website publish endpoint must be local until signed production sync is enabled.');
        $ch=curl_init($endpoint);if($ch===false)throw new RuntimeException('Unable to initialize website publication.');
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['draft_id'=>$draftId]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
        $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($body===false)throw new RuntimeException('Website publication failed: '.$error);$decoded=json_decode($body,true);
        if(!is_array($decoded)||$status>=400||empty($decoded['ok']))throw new RuntimeException('Website rejected publication: '.(is_array($decoded)?(string)($decoded['error']??'unknown error'):'invalid response'));
        return $decoded;
    }

    private function asset(string $file, string $id, array $extra): array
    {
        if ($file === '' || basename($file) !== $file) throw new RuntimeException('Invalid source filename.');
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) throw new RuntimeException('Source image not found: ' . $file);
        $baseUrl = rtrim(app_env('APP_URL', ''), '/');
        $publicUrl = $baseUrl !== '' ? $baseUrl . '/media.php?file=' . rawurlencode($file) : '';
        return array_merge(['asset_id'=>$id,'kind'=>str_starts_with($id,'root:')?'artwork':'mockup','source_file'=>$file,'source_url'=>$publicUrl,'sha256'=>hash_file('sha256',$path),'mime_type'=>(string)(mime_content_type($path) ?: 'application/octet-stream')], $extra);
    }

    private function csv(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $value) ?: []))); }
    private function uuidV4(): string { $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b),4)); }
}
