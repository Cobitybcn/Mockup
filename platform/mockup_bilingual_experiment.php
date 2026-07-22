<?php
declare(strict_types=1);

$mockupId = max(0, (int)($_GET['id'] ?? 0));
$query = http_build_query([
    'id' => $mockupId,
    'bilingual_experiment' => 1,
]);

header('Location: viewer.php?' . $query);
exit;
