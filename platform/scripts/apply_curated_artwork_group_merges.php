<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$service = new ArtworkGroupService($pdo);

$merges = [
    // Blue field with red squares.
    [1, 35],

    // Burgundy/maroon root variants with blue/red accent blocks.
    [29, 6],
    [29, 15],

    // Spiral works.
    [8, 25],
    [8, 46],
    [8, 86],

    // Horizontal stripe / sunset-like works.
    [30, 11],
    [30, 19],

    // Chair work.
    [20, 40],

    // Mask/face work.
    [23, 88],

    // Vertical green/orange root variants.
    [3, 5],

    // Blue geometric variants.
    [36, 39],

    // Orange/yellow geometric architectural family.
    [13, 10],
    [13, 12],
    [13, 16],
    [13, 22],
    [13, 24],
    [13, 87],

    // Repair groups that old sync versions could recreate from already-fused variants.
    [36, 89],
    [30, 90],
    [8, 91],
    [8, 92],
    [13, 93],
    [23, 94],
];

$userId = 1;
$before = activeGroupCount($pdo, $userId);

foreach ($merges as [$primary, $secondary]) {
    $service->mergeGroups($userId, $primary, $secondary);
    echo "merged {$secondary} into {$primary}\n";
}

markEmptyGroupsMerged($pdo, $userId);
$service->syncUser($userId);

$after = activeGroupCount($pdo, $userId);
echo "active_groups_before={$before}\n";
echo "active_groups_after={$after}\n";

function activeGroupCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM artwork_groups WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function markEmptyGroupsMerged(PDO $pdo, int $userId): void
{
    $rows = $pdo->prepare("
        SELECT g.id, g.canonical_artwork_id, a.artwork_group_id AS current_group_id
        FROM artwork_groups g
        LEFT JOIN artworks own ON own.artwork_group_id = g.id
        LEFT JOIN artworks a ON a.id = g.canonical_artwork_id AND a.user_id = g.user_id
        WHERE g.user_id = ?
        AND g.status = 'active'
        GROUP BY g.id
        HAVING COUNT(own.id) = 0
    ");
    $rows->execute([$userId]);
    foreach ($rows->fetchAll() as $row) {
        $target = (int)($row['current_group_id'] ?? 0);
        if ($target <= 0 || $target === (int)$row['id']) {
            $target = null;
        }
        $stmt = $pdo->prepare("
            UPDATE artwork_groups
            SET status = 'merged',
                merged_into_group_id = :target,
                updated_at = :updated_at
            WHERE id = :id
            AND user_id = :user_id
        ");
        $stmt->execute([
            'target' => $target,
            'updated_at' => date('c'),
            'id' => (int)$row['id'],
            'user_id' => $userId,
        ]);
        echo 'marked_empty_group=' . (int)$row['id'] . ' target=' . ($target ?: 'null') . "\n";
    }
}
