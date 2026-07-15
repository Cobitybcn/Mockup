<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$path = __DIR__ . '/../storage/ficha_proposal.json';
$data = json_decode((string)file_get_contents($path), true);
$groups = is_array($data) ? ($data['groups'] ?? []) : [];
$pdo = Database::connection();

$stmt = $pdo->prepare('
    SELECT id, user_id, final_title, width, height, unit, root_file
    FROM artworks
    WHERE id = ?
    LIMIT 1
');

foreach ($groups as $index => $group) {
    $ids = array_values(array_filter(array_map('intval', $group['artwork_ids'] ?? [])));
    $canonical = (int)($group['canonical'] ?? ($ids[0] ?? 0));
    $stmt->execute([$canonical]);
    $row = $stmt->fetch() ?: [];
    $title = trim((string)($row['final_title'] ?? 'Untitled'));
    $size = trim((string)($row['width'] ?? '') . 'x' . (string)($row['height'] ?? '') . ' ' . (string)($row['unit'] ?? ''));
    echo str_pad((string)($index + 1), 2, ' ', STR_PAD_LEFT) . ' ';
    echo 'canonical=' . $canonical . ' count=' . count($ids) . ' user=' . (int)($row['user_id'] ?? 0);
    echo ' size=' . $size . ' title=' . $title . "\n";
}
