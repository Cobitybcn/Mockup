<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$pdo = Database::connection();
$notes = $pdo->query(
    "SELECT id,user_id,title,status,payload_json
     FROM social_campaigns
     WHERE campaign_type='website_blog'
     ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC);

$report = [];
foreach ($notes as $note) {
    $payload = json_decode((string)$note['payload_json'], true);
    $payload = is_array($payload) ? $payload : [];
    $localized = $pdo->prepare(
        "SELECT locale,content_json,published_content_json,status,is_published
         FROM bilingual_editorial_content
         WHERE user_id=? AND entity_type='studio_note' AND entity_id=?
         ORDER BY locale"
    );
    $localized->execute([(int)$note['user_id'], (int)$note['id']]);
    $languages = [];
    foreach ($localized as $row) {
        $content = json_decode((string)$row['content_json'], true);
        $published = json_decode((string)$row['published_content_json'], true);
        $languages[(string)$row['locale']] = [
            'status' => (string)$row['status'],
            'is_published' => (bool)$row['is_published'],
            'current_fields' => fieldSizes($content),
            'published_fields' => fieldSizes($published),
            'current' => inspectBody((string)($content['body_html'] ?? '')),
            'published' => inspectBody((string)($published['body_html'] ?? '')),
        ];
    }
    $jobs = $pdo->prepare(
        "SELECT status,COUNT(*) AS count,
                MAX(CHAR_LENGTH(payload_json)) AS max_payload_bytes,
                MAX(CHAR_LENGTH(result_json)) AS max_result_bytes
         FROM bilingual_editorial_jobs
         WHERE user_id=? AND entity_type='studio_note' AND entity_id=?
         GROUP BY status"
    );
    $jobs->execute([(int)$note['user_id'], (int)$note['id']]);
    $report[] = [
        'id' => (int)$note['id'],
        'title' => (string)$note['title'],
        'status' => (string)$note['status'],
        'payload_bytes' => strlen((string)$note['payload_json']),
        'media_count' => count((array)($payload['media'] ?? [])),
        'source_file' => basename((string)($payload['source']['file'] ?? '')),
        'languages' => $languages,
        'jobs' => $jobs->fetchAll(PDO::FETCH_ASSOC),
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

/** @return array<string,mixed> */
function inspectBody(string $html): array
{
    preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/iu', $html, $matches);
    $sources = [];
    foreach ((array)($matches[1] ?? []) as $source) {
        $decoded = html_entity_decode((string)$source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isData = str_starts_with(strtolower($decoded), 'data:image/');
        $sources[] = [
            'kind' => $isData ? 'data:image' : 'url',
            'bytes' => strlen((string)$source),
            'file' => $isData
                ? ''
                : basename((string)(parse_url($decoded, PHP_URL_PATH) ?: $decoded)),
        ];
    }
    $lower = strtolower($html);
    return [
        'bytes' => strlen($html),
        'image_count' => count($sources),
        'data_image_count' => count(array_filter(
            $sources,
            static fn(array $source): bool => $source['kind'] === 'data:image'
        )),
        'data_uri_occurrences' => substr_count($lower, 'data:image/'),
        'encoded_data_uri_occurrences' => substr_count($lower, 'data&#')
            + substr_count($lower, 'data%3aimage'),
        'base64_occurrences' => substr_count($lower, 'base64'),
        'img_tag_occurrences' => substr_count($lower, '<img'),
        'text_bytes' => strlen(strip_tags($html)),
        'sources' => $sources,
    ];
}

/** @param array<string,mixed> $content */
function fieldSizes(array $content): array
{
    $sizes = [];
    foreach ($content as $key => $value) {
        $encoded = is_string($value)
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sizes[(string)$key] = strlen(is_string($encoded) ? $encoded : '');
    }
    arsort($sizes);
    return $sizes;
}
