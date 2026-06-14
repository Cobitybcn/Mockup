<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$artworks = Database::connection()->query('SELECT id, user_id, main_file, final_title FROM artworks')->fetchAll();
header('Content-Type: application/json');
echo json_encode($artworks, JSON_PRETTY_PRINT);
