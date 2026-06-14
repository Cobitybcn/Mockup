<?php
require_once 'app/bootstrap.php';
$client = new GeminiImageClient();
$ref = new ReflectionMethod($client, 'getPythonExecutable');
$ref->setAccessible(true);
echo "Selected: " . $ref->invoke($client) . "\n";
