<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = Database::connection();

$data = [];

// 1. Fetch app_settings
$stmt = $pdo->query("SELECT `key`, value FROM app_settings");
$data['app_settings'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// 2. Find the latest artwork
$stmtArtwork = $pdo->query("SELECT * FROM artworks ORDER BY id DESC LIMIT 1");
$latestArtwork = $stmtArtwork->fetch(PDO::FETCH_ASSOC);
$data['latest_artwork'] = $latestArtwork;

if ($latestArtwork) {
    $artworkId = $latestArtwork['id'];
    
    // 3. Fetch mockup contexts for the latest artwork
    $stmtContexts = $pdo->prepare("SELECT * FROM mockup_contexts WHERE artwork_id = :id ORDER BY id ASC");
    $stmtContexts->execute(['id' => $artworkId]);
    $data['mockup_contexts'] = $stmtContexts->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Fetch mockups generated for the latest artwork
    $stmtMockups = $pdo->prepare("SELECT * FROM mockups WHERE artwork_file = :file ORDER BY id DESC");
    $stmtMockups->execute(['file' => basename($latestArtwork['root_file'])]);
    $data['mockups'] = $stmtMockups->fetchAll(PDO::FETCH_ASSOC);
}

// 5. Fetch queue status
try {
    $stmtQueue = $pdo->query("SELECT * FROM mockup_batch_queue ORDER BY id DESC LIMIT 10");
    $data['queue'] = $stmtQueue->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $data['queue'] = 'Table not found or error: ' . $e->getMessage();
}

file_put_contents(__DIR__ . '/scratch_audit_results.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo "Audit complete! Saved to scratch_audit_results.json\n";
