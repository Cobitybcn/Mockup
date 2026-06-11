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

        $stamp = time() . '_' . random_int(1000, 9999);
        $promptName = 'mockup_prompt_ai_' . $stamp . '.txt';
        $outputName = 'mockup_ai_' . $stamp . '.png';
        $finalPrompt = $this->finalPrompt($contextId, $prompt);
        
        try {
            $b64 = $this->callImageEdit($imagePath, $finalPrompt);
            $imageData = base64_decode($b64);

            if ($imageData === false) {
                throw new RuntimeException('OpenAI no devolvio una imagen base64 valida para el mockup.');
            }

            file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $finalPrompt);
            file_put_contents($resultsDir . DIRECTORY_SEPARATOR . $outputName, $imageData);
            
            $elapsed = round(microtime(true) - $t0, 2);
            Logger::log("Mockup OpenAI generado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'openai');
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
            'message' => 'Mockup generado con IA desde la obra raiz y el contexto seleccionado.',
        ];
    }

    private function finalPrompt(string $contextId, string $contextPrompt): string
    {
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
- The artwork must have a believable real-world size relative to furniture, doors, windows, ceiling height, pedestals and any human figure.
- Do not make the artwork monumentally large unless the provided physical dimensions justify it.
- Do not use arbitrary decorative sizing; the artwork is a physical object with real dimensions.
- Use the explicit size category from the curatorial direction: small, modest medium, medium, large statement or monumental.
- Err slightly smaller rather than larger when judging scale, unless the prompt explicitly says the work is large or monumental.

MOCKUP QUALITY:
- Create a fully integrated scene, not a pasted photo on a stock background.
- Add believable shadows, contact shadows, scale, wall interaction, depth, edge detail and subtle environmental light on the physical canvas.
- The setting must feel European or American, sophisticated, collector-grade, emotionally compelling and original.
- Avoid kitchens, common bedrooms, cheap decor, generic sofas, mass-market interiors, stock-photo styling, visible logos and text.
- Make the environment amplify what the artwork emotionally suggests.

SELECTED CONTEXT:
{$contextId}

CURATORIAL DIRECTION:
{$contextPrompt}

OUTPUT:
Realistic, premium, emotionally persuasive art mockup. The artwork should feel placed, collected and desired, not copied and pasted.
PROMPT;
    }

    private function callImageEdit(string $imagePath, string $prompt): string
    {
        $fields = [
            'model' => ProviderSettings::openAIImageModel(),
            'prompt' => $prompt,
            'size' => ProviderSettings::openAIImageSize(),
            'quality' => ProviderSettings::openAIImageQuality(),
            'n' => '1',
            'image[0]' => new CURLFile($imagePath, $this->mime($imagePath), basename($imagePath)),
        ];

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
