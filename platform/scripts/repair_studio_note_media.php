<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['apply', 'notes:']);
$apply = array_key_exists('apply', $options);
$noteIds = array_values(array_unique(array_filter(
    array_map('intval', preg_split('/[^0-9]+/', (string)($options['notes'] ?? '')) ?: []),
    static fn(int $id): bool => $id > 0
)));
if ($noteIds === []) {
    fwrite(STDERR, "Use --notes=18,19 and add --apply only after reviewing the audit.\n");
    exit(2);
}

$pdo = Database::connection();
$board = new WebsiteBoardService($pdo);
$marks = implode(',', array_fill(0, count($noteIds), '?'));
$notes = $pdo->prepare(
    "SELECT id,user_id,title,objective,source_type,source_id,source_label,payload_json
     FROM social_campaigns
     WHERE campaign_type='website_blog' AND id IN ({$marks})
     ORDER BY id"
);
$notes->execute($noteIds);
$rows = $notes->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== count($noteIds)) {
    throw new RuntimeException('One or more requested Studio Notes do not exist.');
}

$report = [];
foreach ($rows as $note) {
    $noteId = (int)$note['id'];
    $userId = (int)$note['user_id'];
    $payload = json_decode((string)$note['payload_json'], true);
    $payload = is_array($payload) ? $payload : [];
    $localized = $pdo->prepare(
        "SELECT locale,content_json,published_content_json
         FROM bilingual_editorial_content
         WHERE user_id=? AND entity_type='studio_note' AND entity_id=?
         ORDER BY locale"
    );
    $localized->execute([$userId, $noteId]);
    $languageRows = $localized->fetchAll(PDO::FETCH_ASSOC);
    $beforeBytes = 0;
    $beforeImages = 0;
    foreach ($languageRows as $languageRow) {
        foreach (['content_json', 'published_content_json'] as $column) {
            $content = json_decode((string)$languageRow[$column], true);
            $body = is_array($content) ? (string)($content['body_html'] ?? '') : '';
            $beforeBytes += strlen($body);
            $beforeImages += embeddedImageCount($body);
        }
    }
    $reportRow = [
        'id' => $noteId,
        'before_body_bytes' => $beforeBytes,
        'embedded_images' => $beforeImages,
        'mode' => $apply ? 'apply' : 'dry-run',
    ];
    if (!$apply) {
        $report[] = $reportRow;
        continue;
    }
    if ($beforeImages === 0) {
        throw new RuntimeException("Studio Note {$noteId} has no recoverable embedded images.");
    }

    $availableSources = $board->sources($userId);
    $updates = [];
    foreach ($languageRows as $languageRow) {
        $rowUpdate = ['locale' => (string)$languageRow['locale']];
        foreach (['content_json', 'published_content_json'] as $column) {
            $content = json_decode((string)$languageRow[$column], true);
            $content = is_array($content) ? $content : [];
            $body = (string)($content['body_html'] ?? '');
            if ($body === '') {
                $rowUpdate[$column] = (string)$languageRow[$column];
                continue;
            }
            $textFingerprint = hash('sha256', normalizedEditorialText($body));
            $imageCount = embeddedImageCount($body);
            if ($imageCount > 0) {
                $normalized = StudioNoteMediaService::normalize(
                    $userId,
                    $noteId,
                    $body,
                    $payload,
                    $availableSources
                );
                $payload = (array)$normalized['payload'];
                $content['body_html'] = (string)$normalized['html'];
            }
            $normalizedBody = (string)($content['body_html'] ?? '');
            if (embeddedImageCount($normalizedBody) !== 0) {
                throw new RuntimeException("Studio Note {$noteId} still contains embedded image bytes.");
            }
            if (!hash_equals($textFingerprint, hash('sha256', normalizedEditorialText($normalizedBody)))) {
                throw new RuntimeException("Studio Note {$noteId} text changed while extracting its images.");
            }
            $rowUpdate[$column] = json_encode(
                $content,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }
        $updates[] = $rowUpdate;
    }

    $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
    $englishCurrent = '';
    foreach ($updates as $update) {
        if ((string)$update['locale'] !== 'en') continue;
        $decoded = json_decode((string)$update['content_json'], true);
        $englishCurrent = is_array($decoded) ? (string)($decoded['body_html'] ?? '') : '';
    }

    $pdo->beginTransaction();
    try {
        $updateLocale = $pdo->prepare(
            "UPDATE bilingual_editorial_content
             SET content_json=?,published_content_json=?,updated_at=?
             WHERE user_id=? AND entity_type='studio_note' AND entity_id=? AND locale=?"
        );
        foreach ($updates as $update) {
            $updateLocale->execute([
                (string)$update['content_json'],
                (string)$update['published_content_json'],
                date(DATE_ATOM),
                $userId,
                $noteId,
                (string)$update['locale'],
            ]);
        }
        $objective = $englishCurrent !== '' ? $englishCurrent : (string)$note['objective'];
        $pdo->prepare(
            'UPDATE social_campaigns
             SET objective=?,source_type=?,source_id=?,source_label=?,payload_json=?,updated_at=?
             WHERE id=? AND user_id=?'
        )->execute([
            $objective,
            (string)($source['type'] ?? $note['source_type']),
            (string)($source['id'] ?? $note['source_id']),
            (string)($source['label'] ?? $note['source_label']),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            date(DATE_ATOM),
            $noteId,
            $userId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    $afterBytes = 0;
    foreach ($updates as $update) {
        foreach (['content_json', 'published_content_json'] as $column) {
            $content = json_decode((string)$update[$column], true);
            $afterBytes += strlen(is_array($content) ? (string)($content['body_html'] ?? '') : '');
        }
    }
    $reportRow['after_body_bytes'] = $afterBytes;
    $reportRow['media_count'] = count((array)($payload['media'] ?? []));
    $report[] = $reportRow;
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;

function embeddedImageCount(string $html): int
{
    $lower = strtolower($html);
    return substr_count($lower, 'data:image/jpeg;base64,')
        + substr_count($lower, 'data:image/png;base64,')
        + substr_count($lower, 'data:image/webp;base64,')
        + substr_count($lower, 'data:application/octet-stream;base64,');
}

function normalizedEditorialText(string $html): string
{
    $withoutImages = '';
    $copyFrom = 0;
    $searchFrom = 0;
    $length = strlen($html);
    while ($searchFrom < $length && ($start = stripos($html, '<img', $searchFrom)) !== false) {
        $end = strpos($html, '>', $start + 4);
        if ($end === false) break;
        $withoutImages .= substr($html, $copyFrom, $start - $copyFrom);
        $copyFrom = $end + 1;
        $searchFrom = $end + 1;
    }
    $withoutImages .= substr($html, $copyFrom);
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(
        strip_tags($withoutImages),
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    )) ?? '');
}
