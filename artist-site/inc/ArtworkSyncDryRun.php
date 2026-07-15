<?php
declare(strict_types=1);

final class ArtworkSyncDryRun
{
    public function __construct(
        private readonly string $sourceDirectory,
        private readonly string $draftFile
    ) {}

    public function process(array $payload): array
    {
        $errors = $this->validate($payload);
        if ($errors !== []) {
            return $this->response($payload, false, 'rejected', $errors, false);
        }

        $draftId = hash('sha256', (string)$payload['idempotency_key']);
        $entry = [
            'draft_id' => $draftId,
            'request_id' => $payload['request_id'],
            'idempotency_key' => $payload['idempotency_key'],
            'received_at' => date(DATE_ATOM),
            'status' => 'validated_draft',
            'source_payload' => $payload,
            'website_preview' => $this->websitePreview($payload),
        ];
        $replay = $this->store($entry);
        return $this->response($payload, true, 'validated_draft', [], true, $draftId, $replay);
    }

    private function validate(array $p): array
    {
        $errors = [];
        $add = static function (string $path, string $code, string $message) use (&$errors): void {
            $errors[] = compact('path', 'code', 'message');
        };
        if (($p['schema_version'] ?? null) !== '1.0') $add('schema_version', 'unsupported', 'schema_version must be 1.0.');
        if (($p['mode'] ?? null) !== 'dry_run') $add('mode', 'dry_run_only', 'Only dry_run is accepted.');
        if (!is_string($p['request_id'] ?? null) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $p['request_id'])) $add('request_id', 'invalid_uuid', 'A UUID v4 request_id is required.');
        if (trim((string)($p['idempotency_key'] ?? '')) === '') $add('idempotency_key', 'required', 'idempotency_key is required.');

        $art = is_array($p['artwork'] ?? null) ? $p['artwork'] : [];
        foreach (['slug','title','medium'] as $key) if (trim((string)($art[$key] ?? '')) === '') $add("artwork.$key", 'required', "$key is required.");
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string)($art['slug'] ?? ''))) $add('artwork.slug', 'invalid_slug', 'slug must be lowercase kebab-case.');
        if ((int)($art['year'] ?? 0) < 1900 || (int)($art['year'] ?? 0) > ((int)date('Y') + 1)) $add('artwork.year', 'invalid_year', 'year is outside the accepted range.');
        $dimensions = is_array($art['dimensions'] ?? null) ? $art['dimensions'] : [];
        if ((float)($dimensions['width'] ?? 0) <= 0 || (float)($dimensions['height'] ?? 0) <= 0 || ($dimensions['unit'] ?? '') !== 'cm') $add('artwork.dimensions', 'invalid_dimensions', 'Positive width and height in cm are required.');

        $core = is_array($p['editorial_core'] ?? null) ? $p['editorial_core'] : [];
        foreach (['language','summary','concept','alt_text'] as $key) if (trim((string)($core[$key] ?? '')) === '') $add("editorial_core.$key", 'required', "$key is required.");
        $assets = is_array($p['assets'] ?? null) ? $p['assets'] : [];
        $root = is_array($assets['artwork'] ?? null) ? $assets['artwork'] : [];
        $this->validateAsset($root, 'assets.artwork', $errors);
        $mockups = is_array($assets['mockups'] ?? null) ? array_values($assets['mockups']) : [];
        if (count($mockups) < 2 || count($mockups) > 12) $add('assets.mockups', 'invalid_count', 'Between 2 and 12 mockups are required.');
        $ids = [];
        foreach ($mockups as $i => $mockup) {
            if (!is_array($mockup)) { $add("assets.mockups.$i", 'invalid', 'Each mockup must be an object.'); continue; }
            $this->validateAsset($mockup, "assets.mockups.$i", $errors);
            $id = (string)($mockup['asset_id'] ?? '');
            if ($id !== '' && isset($ids[$id])) $add("assets.mockups.$i.asset_id", 'duplicate', 'asset_id must be unique.');
            $ids[$id] = true;
        }
        return $errors;
    }

    private function validateAsset(array $asset, string $path, array &$errors): void
    {
        $add = static function (string $field, string $code, string $message) use (&$errors, $path): void { $errors[] = ['path' => "$path.$field", 'code' => $code, 'message' => $message]; };
        $file = (string)($asset['source_file'] ?? '');
        if ($file === '' || basename($file) !== $file) { $add('source_file', 'invalid_filename', 'A basename without directories is required.'); return; }
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg','jpeg','png','webp'], true)) $add('source_file', 'invalid_extension', 'Only JPEG, PNG and WebP are accepted.');
        $full = $this->sourceDirectory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($full) || !is_readable($full) || @getimagesize($full) === false) { $add('source_file', 'invalid_image', 'The source image is missing or invalid.'); return; }
        $hash = strtolower((string)($asset['sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash) || !hash_equals(hash_file('sha256', $full), $hash)) $add('sha256', 'hash_mismatch', 'sha256 does not match the source image.');
    }

    private function websitePreview(array $p): array
    {
        $a = $p['artwork']; $d = $a['dimensions']; $c = $p['editorial_core'];
        $values = [(float)$d['width'], (float)$d['height']];
        if ((float)($d['depth'] ?? 0) > 0) $values[] = (float)$d['depth'];
        $format = static fn(float $v): string => rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        return ['slug' => $a['slug'], 'artwork' => [
            'title' => $a['title'], 'year' => (string)$a['year'], 'series' => (string)($a['series'] ?? ''),
            'status' => 'draft', 'medium' => $a['medium'],
            'dimensions_cm' => implode(' x ', array_map($format, $values)) . ' cm',
            'dimensions_in' => implode(' x ', array_map(fn(float $v): string => $format($v / 2.54), $values)) . ' in',
            'orientation' => ucfirst((string)($a['orientation'] ?? '')), 'image' => null, 'mockups' => [],
            'summary' => $c['summary'], 'concept' => $c['concept'], 'commercial_note' => (string)($c['commercial_note'] ?? ''),
        ]];
    }

    private function store(array $entry): bool
    {
        $dir = dirname($this->draftFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create draft directory.');
        $handle = fopen($this->draftFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Cannot lock draft file.');
        try {
            $raw = stream_get_contents($handle); $data = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) $data = [];
            $replay = isset($data[$entry['draft_id']]);
            $data[$entry['draft_id']] = $entry;
            rewind($handle); ftruncate($handle, 0);
            fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL); fflush($handle);
            return $replay;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function response(array $p, bool $ok, string $status, array $errors, bool $wrote, ?string $draftId = null, bool $replay = false): array
    {
        return ['ok'=>$ok,'mode'=>'dry_run','status'=>$status,'request_id'=>(string)($p['request_id']??''),'draft_id'=>$draftId,'idempotent_replay'=>$replay,
            'validation'=>['errors'=>$errors,'warnings'=>[]], 'writes'=>['draft_file'=>$wrote,'content_json'=>false,'artwork_catalog'=>false,'media'=>false]];
    }
}
