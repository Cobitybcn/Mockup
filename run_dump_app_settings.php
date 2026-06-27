<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pdo = Database::connection();
$stmt = $pdo->prepare("SELECT * FROM app_settings WHERE `key` = 'mockup_final_request' LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$out = "";
if ($row) {
    $out .= "KEY: " . $row['key'] . "\n";
    $out .= "VALUE:\n" . $row['value'] . "\n";
} else {
    $out .= "mockup_final_request not found in DB app_settings!\n";
}

file_put_contents(__DIR__ . '/dump_output.txt', $out);
echo "Done";
