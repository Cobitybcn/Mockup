<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$user = Auth::requireUser();
$artworkId = max(0, (int)($_GET['id'] ?? 0));
try {
    if ($artworkId <= 0) throw new RuntimeException('Artwork not found.');
    $sheet = (new ArtworkSheetService(Database::connection()))->sheetForArtwork($artworkId, (int)$user['id']);
    header('Location: prepare_publication.php?sheet_id=' . (int)$sheet['id']);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
