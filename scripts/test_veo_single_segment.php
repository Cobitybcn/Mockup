<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$artworkId = (int)($argv[1] ?? 0);
if ($artworkId <= 0) { throw new RuntimeException('Usage: php scripts/test_veo_single_segment.php <artwork_id>'); }
$stmt = Database::connection()->prepare('SELECT root_file FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $artworkId]);
$rootFile = basename((string)$stmt->fetchColumn());
$reference = RESULTS_DIR . DIRECTORY_SEPARATOR . $rootFile;
if (!is_file($reference)) { throw new RuntimeException('Root artwork reference was not found for artwork ' . $artworkId . '.'); }
$result = (new VeoVideoClient())->generateSingleSegment([
    'duration_seconds' => 4,
    'segment_prompt' => 'A four-second vertical cinematic shot of an abstract painting in a quiet contemporary gallery. Keep the painting fully visible, unchanged, and correctly proportioned. No people, no text, no logos.',
], [
    'aspect_ratio' => '9:16',
], $reference);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
