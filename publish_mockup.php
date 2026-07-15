<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$userId = (int)$user['id'];
$mockupId = max(0, (int)($_GET['id'] ?? 0));

try {
    if ($mockupId <= 0) {
        throw new RuntimeException('Mockup not found.');
    }
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT * FROM mockups WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$mockupId, $userId]);
    $mockup = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mockup) {
        throw new RuntimeException('Mockup not found.');
    }

    $artworkId = (int)($mockup['source_artwork_id'] ?? 0);
    if ($artworkId <= 0) {
        $artworkStmt = $pdo->prepare('SELECT id FROM artworks WHERE user_id = ? AND (root_file = ? OR main_file = ?) LIMIT 1');
        $artworkStmt->execute([$userId, $mockup['artwork_file'], $mockup['artwork_file']]);
        $artworkId = (int)($artworkStmt->fetchColumn() ?: 0);
    }
    if ($artworkId <= 0) {
        throw new RuntimeException('Related artwork not found.');
    }

    $sheets = new ArtworkSheetService($pdo);
    $artworkSheet = $sheets->sheetForArtwork($artworkId, $userId);
    $mockupSheet = $sheets->attachMockupFile((int)$artworkSheet['id'], $userId, (string)$mockup['mockup_file']);
    $publicationId = (new PublicationService($pdo))->createForSheet((int)$artworkSheet['id'], $userId);

    header('Location: prepare_publication.php?id=' . $publicationId . '&mockup_id=' . (int)$mockupSheet['id']);
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
