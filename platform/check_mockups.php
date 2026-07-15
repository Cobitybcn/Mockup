<?php
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$db = Database::connection();

// Obtener artworks recientes del usuario 2 (que es el que vemos en la imagen)
$stmt = $db->prepare("
    SELECT id, job_id, root_file, user_id, status FROM artworks 
    WHERE user_id = 2 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$artworks = $stmt->fetchAll();

$data = [
    'artworks' => [],
    'mockups_details' => []
];

foreach ($artworks as $art) {
    $art_id = $art['id'];
    $data['artworks'][] = [
        'id' => $art_id,
        'job_id' => $art['job_id'],
        'root_file' => $art['root_file'],
        'status' => $art['status']
    ];
    
    // Get mockups for this artwork
    $stmt2 = $db->prepare("SELECT id, context_id, mockup_file, created_at FROM mockups WHERE artwork_id = :aid");
    $stmt2->execute(['aid' => $art_id]);
    $mockups = $stmt2->fetchAll();
    
    foreach ($mockups as $m) {
        $file_path = RESULTS_DIR . DIRECTORY_SEPARATOR . $m['mockup_file'];
        $exists = is_file($file_path);
        
        $data['mockups_details'][] = [
            'artwork_id' => $art_id,
            'mockup_id' => $m['id'],
            'context_id' => $m['context_id'],
            'mockup_file' => $m['mockup_file'],
            'file_exists' => $exists,
            'file_path' => $file_path
        ];
    }
}

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
