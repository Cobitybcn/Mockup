<?php
declare(strict_types=1);

$seriesId = max(0, (int)($_GET['id'] ?? 0));
$query = http_build_query([
    'series' => $seriesId,
    'bilingual_experiment' => 1,
]);

header('Location: series.php?' . $query);
exit;
