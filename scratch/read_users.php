<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';
$users = Database::connection()->query('SELECT id, email, is_admin FROM users')->fetchAll();
header('Content-Type: application/json');
echo json_encode($users, JSON_PRETTY_PRINT);
