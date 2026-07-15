<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
$queries = [
    'artwork_groups_total' => 'SELECT COUNT(*) FROM artwork_groups',
    'artwork_groups_active' => "SELECT COUNT(*) FROM artwork_groups WHERE status = 'active'",
    'artwork_groups_merged' => "SELECT COUNT(*) FROM artwork_groups WHERE status = 'merged'",
    'user1_artwork_groups_active' => "SELECT COUNT(*) FROM artwork_groups WHERE user_id = 1 AND status = 'active'",
    'artworks_total' => 'SELECT COUNT(*) FROM artworks',
    'artworks_grouped' => 'SELECT COUNT(*) FROM artworks WHERE artwork_group_id IS NOT NULL',
    'official_roots' => "SELECT COUNT(*) FROM artworks WHERE root_view_status = 'official'",
    'variant_roots' => "SELECT COUNT(*) FROM artworks WHERE root_view_status = 'variant' AND artwork_group_id IS NOT NULL",
    'mockups_total' => 'SELECT COUNT(*) FROM mockups',
    'mockups_grouped' => 'SELECT COUNT(*) FROM mockups WHERE artwork_group_id IS NOT NULL',
    'mockup_jobs_total' => 'SELECT COUNT(*) FROM mockup_generation_jobs',
    'mockup_jobs_grouped' => 'SELECT COUNT(*) FROM mockup_generation_jobs WHERE artwork_group_id IS NOT NULL',
    'mockup_sheets_total' => 'SELECT COUNT(*) FROM mockup_sheets',
    'mockup_sheets_grouped' => 'SELECT COUNT(*) FROM mockup_sheets WHERE artwork_group_id IS NOT NULL',
];

foreach ($queries as $label => $sql) {
    echo $label . '=' . (int)$pdo->query($sql)->fetchColumn() . PHP_EOL;
}

$orphans = $pdo->query("
    SELECT artwork_file, COUNT(*) AS total
    FROM mockups
    WHERE artwork_group_id IS NULL
    GROUP BY artwork_file
    ORDER BY total DESC, artwork_file ASC
    LIMIT 20
")->fetchAll();

if ($orphans) {
    echo "orphan_mockup_artwork_files:\n";
    foreach ($orphans as $row) {
        echo "- " . (string)$row['artwork_file'] . '=' . (int)$row['total'] . PHP_EOL;
    }
}
