<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

$artworkId = (int)($argv[1] ?? 0);
if ($artworkId <= 0) { throw new RuntimeException('Usage: php scripts/test_veo_two_segments.php <artwork_id>'); }
$stmt = Database::connection()->prepare('SELECT root_file FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $artworkId]);
$root = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$stmt->fetchColumn());
if (!is_file($root)) { throw new RuntimeException('Root artwork was not found.'); }

$client = new VeoVideoClient();
$specs = ['aspect_ratio' => '9:16'];
$first = $client->generateSingleSegment([
    'duration_seconds' => 4,
    'segment_prompt' => 'Four-second vertical editorial shot of the supplied artwork in its existing product-photo context. Preserve its exact composition, five diagonal rectangular marks, thin gestural lines, red field, canvas edge, frame, and wood floor. Slow stable camera, no people, no text.',
], $specs, $root);
$firstPath = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $first['file']);
$frame = $client->extractFinalFrame($firstPath);
$second = $client->generateSingleSegment([
    'duration_seconds' => 4,
    'segment_prompt' => 'Continue from the supplied final frame exactly. Keep the same artwork composition, red field, five diagonal rectangular marks, thin gestural lines, canvas edge, framing, exposure, wood floor, and camera position. Add only an almost imperceptible slow editorial push. No people, no text.',
], $specs, $frame);

echo json_encode(['segment_1' => $first, 'continuity_frame' => $frame, 'segment_2' => $second], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
