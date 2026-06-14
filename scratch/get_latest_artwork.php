<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$artwork = Database::connection()->query('SELECT * FROM artworks ORDER BY id DESC LIMIT 1')->fetch();
header('Content-Type: application/json');
echo json_encode($artwork, JSON_PRETTY_PRINT);
