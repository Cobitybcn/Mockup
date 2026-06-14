<?php
require_once __DIR__ . "/../app/bootstrap.php";
$pdo = Database::connection();
foreach($pdo->query("select key, value from app_settings") as $r) {
    echo $r["key"] . " = " . str_replace("\n", " ", $r["value"]) . "\n";
}
