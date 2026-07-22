<?php
declare(strict_types=1);

$artworkId = max(0, (int)($_GET['id'] ?? 0));
$query = http_build_query([
    'id' => $artworkId,
    'bilingual_experiment' => 1,
]);

header('Location: artwork.php?' . $query);
exit;
