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

        $b64 = $this->client->generateImage($parts, $model);
        $imageData = base64_decode($b64);

        if ($imageData === false) {
            throw new RuntimeException('Gemini no devolvio una imagen base64 valida.');
        }

        $outputName = 'base_artwork_gemini_' . time() . '_' . random_int(1000, 9999) . '.png';
        $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;
        file_put_contents($outputPath, $imageData);

        return [
            'file' => $outputName,
            'path' => $outputPath,
            'mock' => false,
            'gemini_root_enhancement' => true,
            'message' => 'Imagen raiz mejorada con Gemini usando modelo ' . $model . '. Revisala antes de crear mockups.',
            'meta' => $this->imageMeta($outputPath),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        $artistNotes = trim((string)($status['artist_notes'] ?? ''));
        $extraCount = count($status['extra_files'] ?? []);
        $dimensionText = $this->dimensionText($status['measurements'] ?? [], $source);
        $notesBlock = $artistNotes !== '' ? "\n\nARTIST NOTES:\n{$artistNotes}" : '';
        $rootArtworkRules = PromptSettings::rootArtworkRules();

        $references = $extraCount > 0
            ? "\nUsa las imagenes secundarias solo como referencia de textura, color real, incisiones, pincelada, espatula, bordes y bastidor. No deben cambiar la composicion de la imagen principal."
            : '';

        return <<<PROMPT
ACTIVE ROOT ARTWORK DIRECTIVES:
{$rootArtworkRules}
{$references}

Medidas reales de la obra, no de la foto ni del fondo:
{$dimensionText}{$notesBlock}
PROMPT;
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
            $text .= " Use these dimensions to preserve the real artwork proportion. Required output orientation: {$orientation}. Required aspect ratio: " . round($ratio, 4) . ".";

            if ($depth !== '') {
                $text .= " Stretcher/support depth of the artwork: {$depth} {$unit}.";
            }

            return $text;
        }

        $meta = $this->imageMeta($source);
        return 'No physical artwork measurements were provided. Preserve the visible artwork proportion from the main image: ' . ($meta['aspect_ratio'] ?? 'unknown') . '.';
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
