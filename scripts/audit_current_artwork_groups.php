<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$groups = $pdo->query("
    SELECT
        g.id,
        g.user_id,
        g.canonical_artwork_id,
        g.official_root_artwork_ids,
        g.title,
        COUNT(DISTINCT a.id) AS artworks_count,
        COUNT(DISTINCT m.id) AS mockups_count
    FROM artwork_groups g
    LEFT JOIN artworks a ON a.artwork_group_id = g.id
    LEFT JOIN mockups m ON m.artwork_group_id = g.id
    WHERE g.status = 'active'
    GROUP BY g.id
    ORDER BY g.user_id ASC, g.id ASC
")->fetchAll();

$artworksStmt = $pdo->prepare("
    SELECT id, final_title, width, height, unit, root_file, main_file, root_view_status, root_view_type
    FROM artworks
    WHERE artwork_group_id = ?
    ORDER BY root_view_status = 'official' DESC, id ASC
");

foreach ($groups as $group) {
    $artworksStmt->execute([(int)$group['id']]);
    $artworks = $artworksStmt->fetchAll();
    echo 'group=' . (int)$group['id']
        . ' user=' . (int)$group['user_id']
        . ' canonical=' . (int)$group['canonical_artwork_id']
        . ' artworks=' . (int)$group['artworks_count']
        . ' mockups=' . (int)$group['mockups_count']
        . ' title=' . trim((string)$group['title'])
        . "\n";
    foreach ($artworks as $artwork) {
        echo '  #' . (int)$artwork['id']
            . ' ' . (string)$artwork['root_view_status']
            . '/' . (string)$artwork['root_view_type']
            . ' ' . trim((string)$artwork['width'] . 'x' . (string)$artwork['height'] . ' ' . (string)$artwork['unit'])
            . ' file=' . basename((string)($artwork['root_file'] ?: $artwork['main_file']))
            . "\n";
    }
}
