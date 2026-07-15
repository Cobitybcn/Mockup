<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
$first = RESULTS_DIR . DIRECTORY_SEPARATOR . 'social-video' . DIRECTORY_SEPARATOR . (string)($argv[1] ?? '');
if (!is_file($first)) { throw new RuntimeException('Provide an existing first segment filename.'); }
$client = new VeoVideoClient(); $frame = $client->extractFinalFrame($first);
$second = $client->generateSingleSegment(['duration_seconds' => 4, 'segment_prompt' => 'Continue from the supplied final frame exactly. Keep the same artwork composition, red field, five diagonal rectangular marks, thin gestural lines, canvas edge, framing, exposure, wood floor, and camera position. Add only an almost imperceptible slow editorial push. No people, no text.'], ['aspect_ratio' => '9:16'], $frame);
echo json_encode(['continuity_frame' => $frame, 'segment_2' => $second], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
