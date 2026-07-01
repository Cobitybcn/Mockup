<?php
declare(strict_types=1);

class OpenAIMockupGenerator implements MockupGeneratorInterface
{
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
        Logger::log("Iniciando generacion de mockup OpenAI. Contexto: {$contextId}, Obra: " . basename($imagePath), 'openai');

        $finalPrompt = $this->finalPrompt($contextId, $prompt, $metadata);
        
        try {
            $b64 = $this->callImageEdit(
                $imagePath,
                $finalPrompt,
                (string)($metadata['root_reference_path'] ?? ''),
                (string)($metadata['world_mother_reference_path'] ?? '')
            );
            $imageData = base64_decode($b64);

            if ($imageData === false) {
                throw new RuntimeException('OpenAI no devolvio una imagen base64 valida para el mockup.');
            }

            $seoParams = $metadata['seo_params'] ?? null;
            if ($seoParams) {
                $outputName = Display::generateSeoImageFilename($seoParams, $resultsDir);
                if (pathinfo($outputName, PATHINFO_EXTENSION) === 'jpg') {
                    $imageData = Display::convertPngToJpg($imageData);
                }
            } else {
                $stamp = time() . '_' . random_int(1000, 9999);
                $outputName = 'mockup_ai_' . $stamp . '.png';
            }
            $promptName = pathinfo($outputName, PATHINFO_FILENAME) . '.txt';

            file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $finalPrompt);
            file_put_contents($resultsDir . DIRECTORY_SEPARATOR . $outputName, $imageData);
            
            // Apply ImageResizer to scale the generated mockup proportionally to 2200 px on shortest side
            ImageResizer::resize($resultsDir . DIRECTORY_SEPARATOR . $outputName);

            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup OpenAI generado y redimensionado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'openai');
        } catch (Throwable $e) {
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Error generando mockup OpenAI despues de {$elapsed}s. Error: " . $e->getMessage(), 'error');
            throw $e;
        }

        return [
            'file' => $outputName,
            'path' => $resultsDir . DIRECTORY_SEPARATOR . $outputName,
            'prompt_file' => $promptName,
            'mock' => false,
            'ai_mockup' => true,
            'message' => 'Mockup generated from the root image and the selected context.',
        ];
    }

    private function finalPrompt(string $contextId, string $contextPrompt, array $metadata = []): string
    {
        if (isset($metadata['prompt_passthrough_mode']) && is_string($metadata['prompt_passthrough_mode'])) {
            $contextPrompt = $metadata['prompt_passthrough_mode'];
        }

        return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId($contextPrompt, $contextId);
    }

    private function callImageEdit(string $imagePath, string $prompt, string $rootReferencePath = '', string $worldMotherReferencePath = ''): string
    {
        $fields = [
            'model' => ProviderSettings::openAIImageModel(),
            'prompt' => $prompt,
            'size' => ProviderSettings::openAIImageSize(),
            'quality' => ProviderSettings::openAIImageQuality(),
            'n' => '1',
            'image[0]' => new CURLFile($imagePath, $this->mime($imagePath), basename($imagePath)),
        ];

        if ($rootReferencePath !== '' && is_file($rootReferencePath) && realpath($rootReferencePath) !== realpath($imagePath)) {
            $fields['image[1]'] = new CURLFile($rootReferencePath, $this->mime($rootReferencePath), basename($rootReferencePath));
        }
        if ($worldMotherReferencePath !== '' && is_file($worldMotherReferencePath)) {
            $fields['image[' . count(array_filter(array_keys($fields), static fn($key): bool => str_starts_with((string)$key, 'image['))) . ']'] = new CURLFile(
                $worldMotherReferencePath,
                $this->mime($worldMotherReferencePath),
                basename($worldMotherReferencePath)
            );
        }

        $ch = curl_init('https://api.openai.com/v1/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ProviderSettings::openAIAPIKey(),
            ],
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_TIMEOUT => 900,
            CURLOPT_CONNECTTIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException('Error CURL OpenAI: ' . $err);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Error OpenAI HTTP ' . $status . ': ' . $raw);
        }

        $decoded = json_decode((string)$raw, true);
        $b64 = $decoded['data'][0]['b64_json'] ?? null;

        if (!$b64) {
            throw new RuntimeException('OpenAI no devolvio b64_json para el mockup. Respuesta: ' . $raw);
        }

        return $b64;
    }

    private function mime(string $path): string
    {
        $mime = @mime_content_type($path);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/png';
    }
}
