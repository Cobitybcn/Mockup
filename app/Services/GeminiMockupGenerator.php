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

            $stamp = time() . '_' . random_int(1000, 9999);
            $promptName = 'mockup_prompt_gemini_' . $stamp . '.txt';
            $outputName = 'mockup_gemini_' . $stamp . '.png';

            file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $finalPrompt);
            file_put_contents($resultsDir . DIRECTORY_SEPARATOR . $outputName, $imageData);
            
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup Gemini generado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'gemini');
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
            'message' => 'Mockup generado con Gemini Nano Banana desde la obra raiz y el contexto seleccionado.',
        ];
    }

    private function finalPrompt(string $contextId, string $contextPrompt): string
    {
        $scaleRules = PromptSettings::mockupScaleRules();
        $negativeRules = PromptSettings::mockupNegativeRules();
        $qualityRules = PromptSettings::mockupQualityRules();

        return <<<PROMPT
Create a world-class premium art mockup from the provided ROOT ARTWORK image and the selected curatorial context.

This app sells art online through emotion, desire and collector-level atmosphere. The result must feel unique, not like a generic marketplace mockup.

ROOT ARTWORK PRESERVATION:
- The provided image is the approved root artwork.
- Preserve the artwork's identity, composition, color relationships, brushwork, texture, incisions, palette knife marks, material vibration and proportions.
- Do not redesign, repaint, redraw, simplify, stylize, clean, symmetrize, crop or invent elements inside the artwork.
- The artwork may be integrated into the scene with realistic perspective, edge depth, shadows, wall contact, scale and lighting, but its internal image must remain faithful.

SCALE IS CRITICAL:
- Follow the scale instructions already present in the curatorial direction.
{$scaleRules}

MOCKUP QUALITY:
- Create a fully integrated scene, not a pasted photo on a stock background.
- Add believable shadows, contact shadows, scale, wall interaction, depth, edge detail and subtle environmental light on the physical canvas.
- The setting must feel European or American, sophisticated, collector-grade, emotionally compelling and original.
{$qualityRules}

NEGATIVE RULES:
{$negativeRules}

SELECTED CONTEXT:
{$contextId}

CURATORIAL DIRECTION:
{$contextPrompt}

OUTPUT:
Realistic, premium, emotionally persuasive art mockup. The artwork should feel placed, collected and desired, not copied and pasted.
PROMPT;
    }
}
