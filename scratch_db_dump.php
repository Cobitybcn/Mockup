<?php
require_once __DIR__ . '/app/bootstrap.php';
$pdo = Database::connection();
$stmt = $pdo->query("SELECT `key`, value FROM app_settings");
$rows = $stmt->fetchAll();
header('Content-Type: application/json');
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
