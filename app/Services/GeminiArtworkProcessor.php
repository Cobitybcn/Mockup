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
        $rootCount = PromptSettings::rootArtworkCount();

        for ($i = 1; $i <= $rootCount; $i++) {
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
            'message' => "Root image enhanced ({$rootCount} versions). Please select one.",
            'meta' => $this->imageMeta($paths[0]),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        return trim(PromptSettings::rootArtworkRules());
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
