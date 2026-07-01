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

        $finalPrompt = $this->finalPrompt($contextId, $prompt, $metadata);
        $roleContract = "IMAGE ROLE CONTRACT:\n"
            . "- IMAGE 1 is the ROOT ARTWORK. It is the only source for the artwork content, colors, marks, composition, texture and proportions.\n"
            . "- IMAGE 2, when present, is the WORLD MOTHER. Use it only for environment identity, materials, light and atmosphere.\n"
            . "- Never replace the ROOT ARTWORK with a blank wall, empty canvas, decorative panel or object from the WORLD MOTHER.\n"
            . "- If the camera or environment conflicts with the artwork, preserve IMAGE 1 and adapt the environment around it.";
        $submittedPrompt = $roleContract . "\n\n" . $finalPrompt;
        $parts = [
            $this->client->textPart($submittedPrompt),
            $this->client->textPart("IMAGE 1 - ROOT ARTWORK: exact artwork to preserve inside the mockup."),
            $this->client->imagePart($imagePath),
        ];
        $rootReferencePath = (string)($metadata['root_reference_path'] ?? '');
        if ($rootReferencePath !== '' && is_file($rootReferencePath) && realpath($rootReferencePath) !== realpath($imagePath)) {
            $parts[] = $this->client->textPart("ADDITIONAL ROOT ARTWORK REFERENCE: same artwork identity, still authoritative for the artwork only.");
            $parts[] = $this->client->imagePart($rootReferencePath);
        }
        $worldMotherReferencePath = (string)($metadata['world_mother_reference_path'] ?? '');
        if ($worldMotherReferencePath !== '' && is_file($worldMotherReferencePath)) {
            $parts[] = $this->client->textPart("IMAGE 2 - WORLD MOTHER: environment reference only. Do not use this image as the artwork content.");
            $parts[] = $this->client->imagePart($worldMotherReferencePath);
        }

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

            file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $submittedPrompt);
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

    private function finalPrompt(string $contextId, string $contextPrompt, array $metadata = []): string
    {
        if (isset($metadata['prompt_passthrough_mode']) && is_string($metadata['prompt_passthrough_mode'])) {
            return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId(
                $metadata['prompt_passthrough_mode'],
                $contextId
            );
        }
        if (defined('MOCKUP_PROMPT_FIRST_MODE') && MOCKUP_PROMPT_FIRST_MODE && defined('MOCKUP_PROMPT_FIRST_NO_MASK_MODE') && MOCKUP_PROMPT_FIRST_NO_MASK_MODE) {
            $contextPrompt .= "\n\nARTWORK PRESERVATION DIRECTIVES:\n"
                . "- The provided artwork image is the authoritative visual reference for the artwork. Recreate the same artwork faithfully inside the mockup scene. Preserve its composition, colors, marks, texture, proportions and visual identity. Do not repaint, redesign, simplify, crop, mirror, recolor or reinterpret the artwork. The artwork may only undergo natural geometric perspective caused by the requested camera view.";
        }

        return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId($contextPrompt, $contextId);
    }
}
