<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
Auth::start();

// Ensure user is logged in
$user = Auth::user();
if (!$user) {
    // Autologin as admin@test.com (user 7) for testing purposes if not logged in
    $_SESSION['user_id'] = 7;
    $user = Auth::user();
}

$session_cookie = session_name() . '=' . session_id();

// Use the existing test image in scratch folder
$filePath = dirname(__DIR__) . '/scratch/manual_gen_test.png';
if (!is_file($filePath)) {
    die("File not found at $filePath. Please place an image there.");
}

$cfile = new CURLFile($filePath, 'image/png', 'manual_gen_test.png');

$postData = [
    'main_artwork' => $cfile,
    'width' => '80',
    'height' => '120',
    'depth' => '5',
    'unit' => 'cm'
];

$url = 'http://localhost/mockups/start_generate.php';

$ch = curl_init();
if ($ch === false) {
    die("Failed to initialize cURL.");
}

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_COOKIE, $session_cookie);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Don't redirect, we want to capture the header
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
if ($response === false) {
    $err = curl_error($ch);
    curl_close($ch);
    die("Curl error: " . $err);
}

curl_close($ch);

// Parse Location header
$location = '';
if (preg_match('/^Location:\s*([^\r\n]+)/mi', $response, $matches)) {
    $location = trim($matches[1]);
}

if ($location !== '') {
    // Redirect browser to the waiting page
    header("Location: ../" . $location);
    exit;
} else {
    echo "<pre>Upload failed. Response:\n";
    echo htmlspecialchars($response);
    echo "</pre>";
}
