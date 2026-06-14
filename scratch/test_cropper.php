<?php
require_once __DIR__ . '/../app/bootstrap.php';

$source = __DIR__ . '/../results/base_artwork_gemini_1781208951_7064.png';
$testCopy = __DIR__ . '/test_crop.png';

if (!is_file($source)) {
    die("Source file not found: $source\n");
}

copy($source, $testCopy);

$beforeSize = getimagesize($testCopy);
echo "Before crop: " . $beforeSize[0] . "x" . $beforeSize[1] . "\n";

$cropper = new RootArtworkCropper();
$res = $cropper->cropNeutralMargin($testCopy);

echo "Crop return: " . ($res ? "true" : "false") . "\n";

if ($res && is_file($testCopy)) {
    $afterSize = getimagesize($testCopy);
    echo "After crop: " . $afterSize[0] . "x" . $afterSize[1] . "\n";
} else {
    echo "Crop failed or no changes\n";
}
