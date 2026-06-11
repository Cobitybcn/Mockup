<?php
declare(strict_types=1);

class MockArtworkProcessor implements ArtworkProcessorInterface
{
    public function createRootImage(string $jobDir, array $status): array
    {
        $mainFile = basename((string)($status['main_file'] ?? ''));
        $source = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . $mainFile;

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

        $outputName = 'base_artwork_mock_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
        $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;

        if (!copy($source, $outputPath)) {
            throw new RuntimeException('No se pudo crear la imagen raiz simulada.');
        }

        $meta = $this->imageMeta($outputPath);

        return [
            'file' => $outputName,
            'path' => $outputPath,
            'mock' => true,
            'message' => 'Imagen raiz simulada creada localmente. No se uso API.',
            'meta' => $meta,
        ];
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
