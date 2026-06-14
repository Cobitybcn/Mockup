<?php
require_once __DIR__ . '/../app/bootstrap.php';

$source = __DIR__ . '/../results/base_artwork_gemini_1781208951_7064.png';
$testCopy = __DIR__ . '/test_crop.png';

copy($source, $testCopy);

$image = imagecreatefrompng($testCopy);
$width = imagesx($image);
$height = imagesy($image);

// Replicate averageCornerColor logic
$points = [
    [2, 2],
    [$width - 3, 2],
    [2, $height - 3],
    [$width - 3, $height - 3],
];
$sum = [0, 0, 0];
foreach ($points as [$x, $y]) {
    $color = imagecolorat($image, $x, $y);
    $r = ($color >> 16) & 0xFF;
    $g = ($color >> 8) & 0xFF;
    $b = $color & 0xFF;
    $sum[0] += $r;
    $sum[1] += $g;
    $sum[2] += $b;
}
$bg = [
    (int)round($sum[0] / 4),
    (int)round($sum[1] / 4),
    (int)round($sum[2] / 4),
];
echo "Background Color (Average Corner): R=" . $bg[0] . ", G=" . $bg[1] . ", B=" . $bg[2] . "\n";

$threshold = 34;
$minX = $width;
$minY = $height;
$maxX = 0;
$maxY = 0;

for ($y = 0; $y < $height; $y += 2) {
    for ($x = 0; $x < $width; $x += 2) {
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
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
    }
}

echo "Detected Bounding Box: minX=$minX, minY=$minY, maxX=$maxX, maxY=$maxY\n";

$pad = 8;
$minX = max(0, $minX - $pad);
$minY = max(0, $minY - $pad);
$maxX = min($width - 1, $maxX + $pad);
$maxY = min($height - 1, $maxY + $pad);

$cropW = $maxX - $minX + 1;
$cropH = $maxY - $minY + 1;
echo "Cropped Size: {$cropW}x{$cropH}\n";
echo "Original Size: {$width}x{$height}\n";

$cropW_ratio = $cropW / $width;
$cropH_ratio = $cropH / $height;
echo "Crop Width Ratio: " . round($cropW_ratio, 4) . " (must be <= 0.92 to trigger crop)\n";
echo "Crop Height Ratio: " . round($cropH_ratio, 4) . " (must be <= 0.92 to trigger crop)\n";
