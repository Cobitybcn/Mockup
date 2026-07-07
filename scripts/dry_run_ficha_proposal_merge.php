<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$data = json_decode((string)file_get_contents(__DIR__ . '/../storage/ficha_proposal.json'), true);
$groups = is_array($data) ? ($data['groups'] ?? []) : [];

$groupStmt = $pdo->prepare("
    SELECT a.id, a.user_id, a.artwork_group_id
    FROM artworks a
    INNER JOIN artwork_groups g ON g.id = a.artwork_group_id
    WHERE a.id = ?
    AND g.status = 'active'
    LIMIT 1
");

$mergeSets = [];
foreach ($groups as $group) {
    $byUser = [];
    foreach (($group['artwork_ids'] ?? []) as $artworkId) {
        $groupStmt->execute([(int)$artworkId]);
        $row = $groupStmt->fetch();
        if (!$row) {
            continue;
        }
        $userId = (int)$row['user_id'];
        $byUser[$userId][(int)$row['artwork_group_id']][] = (int)$row['id'];
    }

    foreach ($byUser as $userId => $groupMap) {
        if (count($groupMap) > 1) {
            $mergeSets[] = ['user_id' => $userId, 'groups' => $groupMap];
        }
    }
}

foreach ($mergeSets as $set) {
    echo 'user=' . $set['user_id'] . ' groups=' . implode(',', array_keys($set['groups'])) . "\n";
    foreach ($set['groups'] as $groupId => $ids) {
        echo '  group=' . $groupId . ' artwork_ids=' . implode(',', $ids) . "\n";
    }
}
echo 'merge_sets=' . count($mergeSets) . "\n";
