<?php
require_once __DIR__ . '/../app/bootstrap.php';

$source = __DIR__ . '/../results/base_artwork_gemini_1781208951_7064.png';
$testCopy = __DIR__ . '/../results/base_artwork_gemini_1781208951_7064.png';
$visualOut = __DIR__ . '/test_crop_visual.png';

$image = imagecreatefrompng($source);
$width = imagesx($image);
$height = imagesy($image);

// Replicate averageCornerColor
$points = [
    [2, 2],
    [$width - 3, 2],
    [2, $height - 3],
    [$width - 3, $height - 3],
];
$sum = [0, 0, 0];
foreach ($points as [$x, $y]) {
    $color = imagecolorat($image, $x, $y);
    $sum[0] += ($color >> 16) & 0xFF;
    $sum[1] += ($color >> 8) & 0xFF;
    $sum[2] += $color & 0xFF;
}
$bg = [
    (int)round($sum[0] / 4),
    (int)round($sum[1] / 4),
    (int)round($sum[2] / 4),
];

$threshold = 34;

// Create a copy to draw on
$visual = imagecreatetruecolor($width, $height);
imagecopy($visual, $image, 0, 0, 0, 0, $width, $height);

$red = imagecolorallocate($visual, 255, 0, 0);

for ($y = 0; $y < $height; $y += 4) {
    for ($x = 0; $x < $width; $x += 4) {
        $color = imagecolorat($image, $x, $y);
        $r = ($color >> 16) & 0xFF;
        $g = ($color >> 8) & 0xFF;
        $b = $color & 0xFF;
        
        $distance = sqrt(
            (($r - $bg[0]) ** 2) +
            (($g - $bg[1]) ** 2) +
            (($b - $bg[2]) ** 2)
        );
        
        if ($distance > $threshold) {
            // Draw a small red pixel or dot
            imagesetpixel($visual, $x, $y, $red);
        }
    }
}

// Scale down for easier viewing if large
$maxW = 800;
if ($width > $maxW) {
    $newW = $maxW;
    $newH = (int)round($height * ($maxW / $width));
    $resized = imagecreatetruecolor($newW, $newH);
    imagecopyresampled($resized, $visual, 0, 0, 0, 0, $newW, $newH, $width, $height);
    imagepng($resized, $visualOut);
    imagedestroy($resized);
} else {
    imagepng($visual, $visualOut);
}

imagedestroy($visual);
imagedestroy($image);
echo "Visual output saved\n";
