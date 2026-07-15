<?php
declare(strict_types=1);

class OpenAIMockupGenerator implements MockupGeneratorInterface
{
    private string $apiKey;
    private string $model;
    private string $size;
    private string $quality;

    public function __construct(string $apiKey = '', string $model = '', string $size = '', string $quality = '')
    {
        $this->apiKey = trim($apiKey !== '' ? $apiKey : ProviderSettings::openAIAPIKey());
        $configuredModel = trim($model !== '' ? $model : ProviderSettings::openAIImageModel());
        $this->model = str_starts_with($configuredModel, 'gpt-image-') ? $configuredModel : 'gpt-image-2';
        $this->size = trim($size !== '' ? $size : ProviderSettings::openAIImageSize());
        $configuredQuality = strtolower(trim($quality !== '' ? $quality : ProviderSettings::openAIImageQuality()));
        $this->quality = in_array($configuredQuality, ['low', 'medium', 'high', 'auto'], true)
            ? $configuredQuality
            : 'medium';
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
        Logger::log("Iniciando generacion de mockup OpenAI. Contexto: {$contextId}, Obra: " . basename($imagePath), 'openai');

        $finalPrompt = $this->finalPrompt($contextId, $prompt, $metadata);
        $worldMotherReferencePath = (string)($metadata['world_mother_reference_path'] ?? '');
        if ($worldMotherReferencePath !== '' && is_file($worldMotherReferencePath)) {
            $cameraSlotId = trim((string)($metadata['mockup_combination']['selected_camera_slot_id'] ?? ''));
            $finalPrompt = WorldMotherCameraAuthorityPolicy::applyToPrompt($finalPrompt, $cameraSlotId);
        }

        $referenceImages = $this->referenceImages(
            $imagePath,
            (string)($metadata['root_reference_path'] ?? ''),
            $worldMotherReferencePath
        );
        $finalPrompt = $this->referenceContract($finalPrompt, $referenceImages);
        
        try {
            $b64 = $this->callImageEdit($referenceImages, $finalPrompt);
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

            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup OpenAI generado en resolucion nativa en {$elapsed}s. Archivo: {$outputName}", 'openai');
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
        if (!empty($metadata['slot_full_prompt_mode'])) {
            return $contextPrompt;
        }

        if (isset($metadata['prompt_passthrough_mode']) && is_string($metadata['prompt_passthrough_mode'])) {
            $contextPrompt = $metadata['prompt_passthrough_mode'];
        }

        if (!empty($metadata['skip_world_visual_enhancer'])) {
            return $contextPrompt;
        }

        return (new MockupWorldVisualPromptEnhancer())->enhancePromptForContextId($contextPrompt, $contextId);
    }

    /** @param array<int,array{path:string,role:string}> $referenceImages */
    private function callImageEdit(array $referenceImages, string $prompt): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY para generar el mockup.');
        }

        [$body, $contentType] = $this->multipartBody([
            'model' => $this->model,
            'prompt' => $prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'n' => '1',
        ], $referenceImages);

        $ch = curl_init('https://api.openai.com/v1/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: ' . $contentType,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $body,
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

    /** @return array<int,array{path:string,role:string}> */
    private function referenceImages(string $imagePath, string $rootReferencePath, string $worldMotherReferencePath): array
    {
        $images = [];
        $seen = [];
        $append = function (string $path, string $role) use (&$images, &$seen): void {
            if ($path === '' || !is_file($path)) {
                return;
            }
            $identity = realpath($path) ?: $path;
            if (isset($seen[$identity])) {
                return;
            }
            $seen[$identity] = true;
            $images[] = ['path' => $path, 'role' => $role];
        };

        $append($imagePath, 'root_artwork');
        $append($rootReferencePath, 'root_artwork');
        $append($worldMotherReferencePath, 'world_mother');

        return $images;
    }

    /** @param array<int,array{path:string,role:string}> $referenceImages */
    private function referenceContract(string $prompt, array $referenceImages): string
    {
        $rootIndexes = [];
        $worldIndex = 0;
        foreach ($referenceImages as $index => $referenceImage) {
            $humanIndex = $index + 1;
            if ($referenceImage['role'] === 'world_mother') {
                $worldIndex = $humanIndex;
            } else {
                $rootIndexes[] = $humanIndex;
            }
        }

        $rootLabel = implode(' and IMAGE ', $rootIndexes);
        $contract = "OPENAI REFERENCE IMAGE CONTRACT:\n"
            . "- IMAGE {$rootLabel} contain the ROOT ARTWORK and are the only authority for artwork identity. Preserve the exact visible artwork, colors, marks, texture, orientation, proportions and composition.\n"
            . "- Generate a new photographic mockup around the ROOT ARTWORK. Never repaint, reinterpret, mirror, replace or borrow artwork content from another reference.\n";
        if ($worldIndex > 0) {
            $contract .= "- IMAGE {$worldIndex} is the WORLD MOTHER: environmental inspiration only. Use its materiality, light, palette and architectural mood, but do not copy its camera, crop, layout, geometry, furniture or object positions.\n";
        }
        $contract .= "- The selected camera slot in the prompt is the composition authority.\n"
            . "- Output exactly a 4:5 portrait image. Keep the complete scene inside that frame unless the selected camera explicitly requests an artwork detail crop.";

        return $contract . "\n\n" . trim($prompt);
    }

    /**
     * OpenAI documents multiple edit references as repeated image[] parts.
     * Building the multipart body explicitly preserves those duplicate field names.
     *
     * @param array<string,string> $fields
     * @param array<int,array{path:string,role:string}> $referenceImages
     * @return array{0:string,1:string}
     */
    private function multipartBody(array $fields, array $referenceImages): array
    {
        $boundary = '----ArtworkMockups' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="' . $this->multipartToken($name) . "\"\r\n\r\n";
            $body .= $value . "\r\n";
        }

        foreach ($referenceImages as $referenceImage) {
            $path = $referenceImage['path'];
            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('No se pudo leer una imagen de referencia OpenAI: ' . basename($path));
            }
            $body .= '--' . $boundary . "\r\n";
            $body .= 'Content-Disposition: form-data; name="image[]"; filename="'
                . $this->multipartToken(basename($path)) . "\"\r\n";
            $body .= 'Content-Type: ' . $this->mime($path) . "\r\n\r\n";
            $body .= $content . "\r\n";
        }
        $body .= '--' . $boundary . "--\r\n";

        return [$body, 'multipart/form-data; boundary=' . $boundary];
    }

    private function multipartToken(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['', '', '%22'], $value);
    }

    private function mime(string $path): string
    {
        $mime = @mime_content_type($path);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/png';
    }
}
