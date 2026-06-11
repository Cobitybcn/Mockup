<?php
declare(strict_types=1);

class RootArtworkCropper
{
    public function cropNeutralMargin(string $path, ?float $expectedRatio = null): bool
    {
        if (!extension_loaded('gd')) {
            return false;
        }

        $image = $this->load($path);

        if (!$image) {
            return false;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 20 || $height < 20) {
            imagedestroy($image);
            return false;
        }

        $bg = $this->averageCornerColor($image, $width, $height);
        $threshold = 34;
        $minX = $width;
        $minY = $height;
        $maxX = 0;
        $maxY = 0;

        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $color = imagecolorat($image, $x, $y);
                $rgb = $this->rgb($color);

                if ($this->distance($rgb, $bg) > $threshold) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX <= $minX || $maxY <= $minY) {
            imagedestroy($image);
            return false;
        }

        $pad = 8;
        $minX = max(0, $minX - $pad);
        $minY = max(0, $minY - $pad);
        $maxX = min($width - 1, $maxX + $pad);
        $maxY = min($height - 1, $maxY + $pad);

        $cropW = $maxX - $minX + 1;
        $cropH = $maxY - $minY + 1;
        $cropRatio = $cropW / max(1, $cropH);

        if ($expectedRatio !== null && $expectedRatio > 0) {
            $ratioDelta = abs($cropRatio - $expectedRatio) / $expectedRatio;

            if ($ratioDelta > 0.22) {
                imagedestroy($image);
                return false;
            }
        }

        if (($cropW / $width) > 0.92 && ($cropH / $height) > 0.92) {
            imagedestroy($image);
            return false;
        }

        if ($cropW < 100 || $cropH < 100) {
            imagedestroy($image);
            return false;
        }

        $cropped = imagecrop($image, [
            'x' => $minX,
            'y' => $minY,
            'width' => $cropW,
            'height' => $cropH,
        ]);

        if (!$cropped) {
            imagedestroy($image);
            return false;
        }

        imagepng($cropped, $path, 5);
        imagedestroy($cropped);
        imagedestroy($image);

        return true;
    }

    private function load(string $path): mixed
    {
        $mime = @mime_content_type($path);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function averageCornerColor(mixed $image, int $width, int $height): array
    {
        $points = [
            [2, 2],
            [$width - 3, 2],
            [2, $height - 3],
            [$width - 3, $height - 3],
        ];

        $sum = [0, 0, 0];

        foreach ($points as [$x, $y]) {
            $rgb = $this->rgb(imagecolorat($image, $x, $y));
            $sum[0] += $rgb[0];
            $sum[1] += $rgb[1];
            $sum[2] += $rgb[2];
        }

        return [
            (int)round($sum[0] / 4),
            (int)round($sum[1] / 4),
            (int)round($sum[2] / 4),
        ];
    }

    private function rgb(int $color): array
    {
        return [
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
        ];
    }

    private function distance(array $a, array $b): float
    {
        return sqrt(
            (($a[0] - $b[0]) ** 2) +
            (($a[1] - $b[1]) ** 2) +
            (($a[2] - $b[2]) ** 2)
        );
    }
}
