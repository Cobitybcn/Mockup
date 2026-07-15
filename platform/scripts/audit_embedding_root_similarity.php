<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$userId = (int)($argv[1] ?? 1);
$threshold = (float)($argv[2] ?? 0.90);

$stmt = $pdo->prepare("
    SELECT a.id, a.artwork_group_id, a.root_file, a.main_file, a.width, a.height, a.unit, e.embedding_json
    FROM artworks a
    INNER JOIN artwork_groups g ON g.id = a.artwork_group_id
    INNER JOIN artwork_embeddings e ON e.artwork_id = a.id
    WHERE a.user_id = ?
    AND g.status = 'active'
    ORDER BY a.id ASC
");
$stmt->execute([$userId]);

$items = [];
foreach ($stmt->fetchAll() as $row) {
    $embedding = json_decode((string)$row['embedding_json'], true);
    if (!is_array($embedding) || !$embedding) {
        continue;
    }
    $items[] = [
        'id' => (int)$row['id'],
        'group_id' => (int)$row['artwork_group_id'],
        'file' => basename((string)($row['root_file'] ?: $row['main_file'])),
        'size' => trim((string)$row['width'] . 'x' . (string)$row['height'] . ' ' . (string)$row['unit']),
        'embedding' => array_map('floatval', $embedding),
    ];
}

$pairs = [];
$count = count($items);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        if ($items[$i]['group_id'] === $items[$j]['group_id']) {
            continue;
        }
        $score = cosine($items[$i]['embedding'], $items[$j]['embedding']);
        if ($score >= $threshold) {
            $pairs[] = [$score, $items[$i], $items[$j]];
        }
    }
}

usort($pairs, fn(array $a, array $b): int => $b[0] <=> $a[0]);
foreach ($pairs as [$score, $a, $b]) {
    echo 'score=' . number_format($score, 4)
        . ' group=' . $a['group_id'] . ' artwork=' . $a['id'] . ' size=' . $a['size']
        . ' file=' . $a['file'] . "\n";
    echo '           group=' . $b['group_id'] . ' artwork=' . $b['id'] . ' size=' . $b['size']
        . ' file=' . $b['file'] . "\n";
}
echo 'pairs=' . count($pairs) . ' artworks=' . count($items) . "\n";

function cosine(array $a, array $b): float
{
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    $length = min(count($a), count($b));
    for ($i = 0; $i < $length; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    if ($normA <= 0.0 || $normB <= 0.0) {
        return 0.0;
    }
    return $dot / (sqrt($normA) * sqrt($normB));
}
