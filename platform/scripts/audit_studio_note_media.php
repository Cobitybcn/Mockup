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
        $content = is_array($content) ? $content : [];
        $published = is_array($published) ? $published : [];
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
    $workspace = $pdo->prepare(
        "SELECT board_type,locale,COUNT(*) AS count,
                SUM(CHAR_LENGTH(content_json)) AS total_bytes,
                MAX(CHAR_LENGTH(content_json)) AS max_bytes
         FROM studio_note_workspace_items
         WHERE user_id=? AND note_id=?
         GROUP BY board_type,locale"
    );
    $workspace->execute([(int)$note['user_id'], (int)$note['id']]);
    $report[] = [
        'id' => (int)$note['id'],
        'title' => (string)$note['title'],
        'status' => (string)$note['status'],
        'payload_bytes' => strlen((string)$note['payload_json']),
        'media_count' => count((array)($payload['media'] ?? [])),
        'source_file' => basename((string)($payload['source']['file'] ?? '')),
        'languages' => $languages,
        'jobs' => $jobs->fetchAll(PDO::FETCH_ASSOC),
        'workspace' => $workspace->fetchAll(PDO::FETCH_ASSOC),
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
    preg_match_all('/<img\b[^>]*>/iu', $html, $imageTags);
    $tagDiagnostics = [];
    foreach ((array)($imageTags[0] ?? []) as $tag) {
        $marker = stripos((string)$tag, 'base64');
        $context = $marker === false
            ? substr((string)$tag, 0, 180)
            : substr((string)$tag, max(0, $marker - 100), 220);
        $context = preg_replace(
            '/(base64(?:%2c|&#0*44;|,)?)[A-Za-z0-9+\/=%\s]{12,}/iu',
            '$1[IMAGE_BYTES]',
            $context
        ) ?? $context;
        $tagDiagnostics[] = [
            'bytes' => strlen((string)$tag),
            'context' => $context,
        ];
    }
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
        'image_tags' => $tagDiagnostics,
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
