<?php
declare(strict_types=1);

class GeminiMockupGenerator implements MockupGeneratorInterface
{
    private GeminiImageClient $client;

    public function __construct(?GeminiImageClient $client = null)
    {
        $this->client = $client ?: new GeminiImageClient();
    }

    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen raiz para el mockup.');
        }

        $resultsDir = RESULTS_DIR;
        $promptsDir = PROMPTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }
        if (!is_dir($promptsDir)) {
            mkdir($promptsDir, 0775, true);
        }

        $t0 = microtime(true);
        Logger::log("Iniciando generacion de mockup Gemini. Contexto: {$contextId}, Obra: " . basename($imagePath), 'gemini');

        $finalPrompt = $this->finalPrompt($contextId, $prompt);
        $parts = [
            $this->client->textPart($finalPrompt),
            $this->client->imagePart($imagePath),
        ];

        try {
            $b64 = $this->client->generateImage($parts);
            $imageData = base64_decode($b64);

            if ($imageData === false) {
                throw new RuntimeException('Gemini no devolvio una imagen base64 valida para el mockup.');
            }

            $seoParams = $metadata['seo_params'] ?? null;
            if ($seoParams) {
                $outputName = Display::generateSeoImageFilename($seoParams, $resultsDir);
                if (pathinfo($outputName, PATHINFO_EXTENSION) === 'jpg') {
                    $imageData = Display::convertPngToJpg($imageData);
                }
            } else {
                $stamp = time() . '_' . random_int(1000, 9999);
                $outputName = 'mockup_gemini_' . $stamp . '.png';
            }
            $promptName = pathinfo($outputName, PATHINFO_FILENAME) . '.txt';

            file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $finalPrompt);
            file_put_contents($resultsDir . DIRECTORY_SEPARATOR . $outputName, $imageData);
            
            // Apply ImageResizer to scale the generated mockup proportionally to 2200 px on shortest side
            ImageResizer::resize($resultsDir . DIRECTORY_SEPARATOR . $outputName);

            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup Gemini generado y redimensionado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'gemini');
        } catch (Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Error generando mockup Gemini despues de {$elapsed}s. Error: " . $e->getMessage(), 'error');
            throw $e;
        }

        return [
            'file' => $outputName,
            'path' => $resultsDir . DIRECTORY_SEPARATOR . $outputName,
            'prompt_file' => $promptName,
            'mock' => false,
            'gemini_mockup' => true,
            'message' => 'Mockup generated from the root image and the selected context.',
        ];
    }

    private function finalPrompt(string $contextId, string $contextPrompt): string
    {
        return $contextPrompt;
    }
}
