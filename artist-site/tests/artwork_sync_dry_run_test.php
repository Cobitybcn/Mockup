<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/ArtworkSyncDryRun.php';

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'artwork-sync-' . bin2hex(random_bytes(5));
$source = $base . DIRECTORY_SEPARATOR . 'source';
$draft = $base . DIRECTORY_SEPARATOR . 'drafts.json';
mkdir($source, 0777, true);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
foreach (['root.png','mockup-1.png','mockup-2.png'] as $file) file_put_contents($source . DIRECTORY_SEPARATOR . $file, $png);
$asset = static fn(string $file, string $id): array => ['asset_id'=>$id,'kind'=>'mockup','source_file'=>$file,'sha256'=>hash_file('sha256',$source.DIRECTORY_SEPARATOR.$file),'mime_type'=>'image/png','alt_text'=>'Test image'];
$payload = [
    'schema_version'=>'1.0','mode'=>'dry_run','request_id'=>'123e4567-e89b-42d3-a456-426614174000','idempotency_key'=>'test:1',
    'source'=>['application'=>'artwork_mockups','artwork_id'=>1,'artwork_sheet_id'=>2,'publication_id'=>3,'revision'=>'test'],
    'artwork'=>['slug'=>'synthetic-work','title'=>'Synthetic Work','year'=>(int)date('Y'),'series'=>'strata','status'=>'draft','medium'=>'Acrylic on canvas','dimensions'=>['width'=>120,'height'=>80,'depth'=>4,'unit'=>'cm'],'orientation'=>'horizontal'],
    'editorial_core'=>['language'=>'en','objective'=>'portfolio','subtitle'=>'Test','summary'=>'Synthetic summary.','concept'=>'Synthetic concept.','commercial_note'=>'Synthetic note.','alt_text'=>'Synthetic artwork.','caption'=>'Synthetic caption.','keywords'=>['abstract painting'],'tags'=>['strata']],
    'assets'=>['artwork'=>array_merge($asset('root.png','root:1'),['kind'=>'artwork']),'mockups'=>[$asset('mockup-1.png','mockup:1'),$asset('mockup-2.png','mockup:2')]],
];
$service = new ArtworkSyncDryRun($source, $draft);
$first = $service->process($payload);
$second = $service->process($payload);
$invalid = $payload; $invalid['mode'] = 'publish';
$rejected = $service->process($invalid);
$stored = json_decode((string)file_get_contents($draft), true);
$checks = [
    $first['ok'] === true, $first['status'] === 'validated_draft', $first['writes']['draft_file'] === true,
    $first['writes']['content_json'] === false, $first['writes']['artwork_catalog'] === false, $first['writes']['media'] === false,
    $second['idempotent_replay'] === true, count($stored) === 1,
    $rejected['ok'] === false, $rejected['writes']['draft_file'] === false, count($stored) === 1,
];
if (in_array(false, $checks, true)) { fwrite(STDERR, "FAIL: artwork sync dry run\n"); exit(1); }
echo "PASS: validates one artwork, Editorial Core and multiple mockups; writes drafts only.\n";
