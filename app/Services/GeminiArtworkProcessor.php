<?php
declare(strict_types=1);

class GeminiArtworkProcessor implements ArtworkProcessorInterface
{
    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

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

        $prompt = $this->buildPrompt($status, $source);
        $parts = [$this->client->textPart($prompt), $this->client->imagePart($source)];

        foreach (($status['extra_files'] ?? []) as $extraFile) {
            $extraPath = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . basename((string)$extraFile);
            if (is_file($extraPath)) {
                $parts[] = $this->client->imagePart($extraPath);
            }
        }

        file_put_contents($jobDir . '/prompt.txt', $prompt);
        $model = ProviderSettings::geminiImageModel();

        file_put_contents($jobDir . '/target_size.txt', 'gemini-native model=' . $model);

        $files = [];
        $paths = [];

        for ($i = 1; $i <= 3; $i++) {
            $b64 = $this->client->generateImage($parts, $model);
            $imageData = base64_decode($b64);

            if ($imageData === false) {
                throw new RuntimeException('Gemini no devolvio una imagen base64 valida para la version ' . $i);
            }

            $outputName = 'base_artwork_gemini_' . $jobId . '_v' . $i . '.png';
            $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;
            file_put_contents($outputPath, $imageData);

            $files[] = $outputName;
            $paths[] = $outputPath;
            sleep(1);
        }

        return [
            'files' => $files,
            'paths' => $paths,
            'mock' => false,
            'gemini_root_enhancement' => true,
            'message' => 'Root image enhanced (3 versions). Please select one.',
            'meta' => $this->imageMeta($paths[0]),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        return PromptSettings::rootArtworkRules();
    }

    private function dimensionText(array $measurements, string $source): string
    {
        $width = trim((string)($measurements['width'] ?? ''));
        $height = trim((string)($measurements['height'] ?? ''));
        $depth = trim((string)($measurements['depth'] ?? ''));
        $unit = trim((string)($measurements['unit'] ?? 'cm'));

        if ($width !== '' && $height !== '') {
            $text = "The provided dimensions refer ONLY to the physical artwork itself: {$width} {$unit} wide x {$height} {$unit} high.";
            $text .= " They do not refer to the full photograph, background, table, wall, support board, margins, or surrounding objects.";
            $ratio = (float)str_replace(',', '.', $width) / max(0.01, (float)str_replace(',', '.', $height));
            $orientation = $ratio >= 1 ? 'landscape' : 'portrait';
            $fraction = $this->getRatioFraction($ratio);
            $text .= " Use these dimensions to preserve the real artwork proportion. Required output orientation: {$orientation}. Required aspect ratio: {$fraction}.";

            if ($depth !== '') {
                $text .= " Stretcher/support depth of the artwork: {$depth} {$unit}.";
            }

            return $text;
        }

        $meta = $this->imageMeta($source);
        return 'No physical artwork measurements were provided. Preserve the visible artwork proportion from the main image: ' . ($meta['aspect_ratio'] ?? 'unknown') . '.';
    }

    private function getRatioFraction(float $ratio): string
    {
        $best_num = 1;
        $best_den = 1;
        $best_diff = 999.0;
        for ($den = 1; $den <= 16; $den++) {
            $num = (int)round($ratio * $den);
            $diff = abs($ratio - ($num / $den));
            if ($diff < $best_diff) {
                $best_diff = $diff;
                $best_num = $num;
                $best_den = $den;
            }
        }
        return "{$best_num}:{$best_den}";
    }

    private function expectedRatio(array $status, string $source): ?float
    {
        $m = $status['measurements'] ?? [];
        $width = (float)str_replace(',', '.', (string)($m['width'] ?? '0'));
        $height = (float)str_replace(',', '.', (string)($m['height'] ?? '0'));

        if ($width > 0 && $height > 0) {
            return $width / $height;
        }

        $meta = $this->imageMeta($source);
        return isset($meta['aspect_ratio']) ? (float)$meta['aspect_ratio'] : null;
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
