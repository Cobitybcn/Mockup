<?php
declare(strict_types=1);

class MockArtworkProcessor implements ArtworkProcessorInterface
{
    public function createRootImage(string $jobDir, array $status): array
    {
        $mainFile = basename((string)($status['main_file'] ?? ''));
        $source = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . $mainFile;
        $jobId = basename($jobDir);

        if (!$mainFile || !is_file($source)) {
            throw new RuntimeException('No se encontro la imagen principal del job.');
        }

        $resultsDir = RESULTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }

        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $files = [];
        $paths = [];
        $rootCount = !empty($status['user_scene_flow'])
            ? RootArtworkViewSetService::requiredCount()
            : PromptSettings::rootArtworkCount();

        for ($i = 1; $i <= $rootCount; $i++) {
            $outputName = 'base_artwork_mock_' . $jobId . '_v' . $i . '.' . $ext;
            $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;

            if (extension_loaded('gd')) {
                $img = $this->loadGdImage($source);
                if ($img) {
                    imagepalettetotruecolor($img);
                    $w = imagesx($img);
                    $h = imagesy($img);

                    // Alternate simple crops so test candidates remain visually distinct.
                    if ($i === 2) {
                        $cropped = imagecrop($img, ['x' => (int)($w * 0.05), 'y' => 0, 'width' => (int)($w * 0.9), 'height' => $h]);
                        if ($cropped) {
                            imagedestroy($img);
                            $img = $cropped;
                        }
                    } elseif ($i % 3 === 0) {
                        $cropped = imagecrop($img, ['x' => 0, 'y' => (int)($h * 0.05), 'width' => $w, 'height' => (int)($h * 0.9)]);
                        if ($cropped) {
                            imagedestroy($img);
                            $img = $cropped;
                        }
                    } elseif ($i > 3) {
                        $cropInset = min(0.12, 0.02 * $i);
                        $cropped = imagecrop($img, [
                            'x' => (int)($w * $cropInset / 2),
                            'y' => (int)($h * $cropInset / 2),
                            'width' => (int)($w * (1 - $cropInset)),
                            'height' => (int)($h * (1 - $cropInset)),
                        ]);
                        if ($cropped) {
                            imagedestroy($img);
                            $img = $cropped;
                        }
                    }

                    // Draw a visual indicator overlay box in the top-left corner
                    $white = imagecolorallocate($img, 255, 255, 255);
                    $black = imagecolorallocate($img, 0, 0, 0);
                    imagefilledrectangle($img, 10, 10, 190, 40, $black);
                    imagestring($img, 4, 20, 17, "Candidate V{$i} (Mock)", $white);

                    if ($ext === 'png') {
                        imagepng($img, $outputPath);
                    } elseif ($ext === 'webp' && function_exists('imagewebp')) {
                        imagewebp($img, $outputPath, 85);
                    } else {
                        imagejpeg($img, $outputPath, 85);
                    }
                    imagedestroy($img);
                } else {
                    copy($source, $outputPath);
                }
            } else {
                copy($source, $outputPath);
            }

            $files[] = $outputName;
            $paths[] = $outputPath;
        }

        $meta = $this->imageMeta($paths[0]);

        return [
            'files' => $files,
            'paths' => $paths,
            'mock' => true,
            'message' => "Simulated root image candidates created ({$rootCount} versions).",
            'meta' => $meta,
        ];
    }

    private function loadGdImage(string $path): mixed
    {
        $mime = @mime_content_type($path);
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private function imageMeta(string $path): array
    {
        $size = @getimagesize($path);
        $width = $size ? (int)$size[0] : 0;
        $height = $size ? (int)$size[1] : 0;

        $orientation = 'unknown';
        if ($width > 0 && $height > 0) {
            $orientation = $width > $height ? 'horizontal' : ($height > $width ? 'vertical' : 'square');
        }

        return [
            'width_px' => $width,
            'height_px' => $height,
            'orientation' => $orientation,
            'aspect_ratio' => $height > 0 ? round($width / $height, 4) : null,
        ];
    }
}
