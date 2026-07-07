<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$pdo = Database::connection();
echo 'embeddings=' . (int)$pdo->query('SELECT COUNT(*) FROM artwork_embeddings')->fetchColumn() . PHP_EOL;
echo 'current_user1=' . (int)$pdo->query('SELECT COUNT(*) FROM artwork_embeddings e JOIN artworks a ON a.id = e.artwork_id WHERE a.user_id = 1')->fetchColumn() . PHP_EOL;
