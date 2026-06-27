<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = Database::connection();

$stmt = $pdo->query("SELECT m.id, m.artwork_file, m.mockup_file, m.context_id, m.created_at, mc.context_name, mc.context_json 
                     FROM mockups m 
                     LEFT JOIN mockup_contexts mc ON m.context_id = mc.id 
                     ORDER BY m.id DESC LIMIT 12");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    if ($row['context_json']) {
        $json = json_decode($row['context_json'], true);
        $row['camera_view'] = $json['camera_view'] ?? '';
        $row['camera_distance'] = $json['camera_distance'] ?? '';
    }
    unset($row['context_json']);
}

file_put_contents(__DIR__ . '/scratch_latest_mockups.json', json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Done listing mockups!\n";
