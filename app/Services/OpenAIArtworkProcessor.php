<?php
declare(strict_types=1);

class OpenAIArtworkProcessor implements ArtworkProcessorInterface
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

        $outputName = 'base_artwork_ai_' . time() . '_' . random_int(1000, 9999) . '.png';
        $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;
        $prompt = $this->buildPrompt($status, $source);
        $targetSize = $this->targetSize($status, $source);

        file_put_contents($jobDir . '/prompt.txt', $prompt);
        file_put_contents($jobDir . '/target_size.txt', $targetSize);

        $b64 = $this->callImageEdit($jobDir, $source, $status, $prompt, $targetSize);
        $imageData = base64_decode($b64);

        if ($imageData === false) {
            throw new RuntimeException('OpenAI no devolvio una imagen base64 valida.');
        }

        file_put_contents($outputPath, $imageData);

        return [
            'file' => $outputName,
            'path' => $outputPath,
            'mock' => false,
            'ai_root_enhancement' => true,
            'message' => 'Imagen raiz mejorada con IA. Revisala antes de crear mockups.',
            'meta' => $this->imageMeta($outputPath),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        $artistNotes = trim((string)($status['artist_notes'] ?? ''));
        $extraCount = count($status['extra_files'] ?? []);
        $dimensionText = $this->dimensionText($status['measurements'] ?? [], $source);
        $notesBlock = $artistNotes !== '' ? "\n\nARTIST NOTES:\n{$artistNotes}" : '';

        $references = $extraCount > 0
            ? "\nUsa las imagenes secundarias solo como referencia de textura, color real, incisiones, pincelada, espatula, bordes y bastidor. No deben cambiar la composicion de la imagen principal."
            : '';

        return <<<PROMPT
Crea una foto de lujo de primer plano frontal con esta pintura adjunta.
La obra esta apoyada en el suelo y contra la pared.
La pintura esta perfectamente tensada sobre un bastidor de madera.
La obra esta totalmente visible.
Ilumina el producto con luz de estudio compuesta: luz suave de relleno de ambos lados y destellos direccionales, con separacion tonal tipo HDR y bordes impecables.
Sin logotipos, textos ni marcas visibles.
Todo el producto debe estar nitido, sin desenfoque de fondo, con los detalles de las incisiones, texturas, pinceladas, espatula y bloques para que el publico pueda observarlos y disfrutarlos.
El resultado debe verse realista, limpio, premium y detalladamente retocado.

Respeta la obra original: no redibujes, no cambies composicion, no cambies colores artisticamente, no modifiques la vibracion del trazo del artista ni las texturas creadas por el artista.
La imagen principal manda sobre composicion, proporcion e identidad visual.{$references}

Medidas reales de la obra, no de la foto ni del fondo:
{$dimensionText}{$notesBlock}
PROMPT;
    }

    private function callImageEdit(string $jobDir, string $source, array $status, string $prompt, string $targetSize): string
    {
        $apiDir = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . 'api_inputs';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0775, true);
        }

        $mainApiPath = $this->prepareApiImage($source, $apiDir, 'main');

        $fields = [
            'model' => ProviderSettings::openAIImageModel(),
            'prompt' => $prompt,
            'size' => $targetSize,
            'quality' => ProviderSettings::openAIImageQuality(),
            'n' => '1',
            'image[0]' => new CURLFile($mainApiPath, $this->mime($mainApiPath), basename($mainApiPath)),
        ];

        $i = 1;
        foreach (($status['extra_files'] ?? []) as $extraFile) {
            if ($i >= 16) {
                break;
            }

            $extraPath = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . basename((string)$extraFile);

            if (!is_file($extraPath)) {
                continue;
            }

            $extraApiPath = $this->prepareApiImage($extraPath, $apiDir, 'extra_' . $i);
            $fields['image[' . $i . ']'] = new CURLFile($extraApiPath, $this->mime($extraApiPath), basename($extraApiPath));
            $i++;
        }

        $lastRaw = '';
        $lastStatus = 0;
        $lastErr = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->postImageEdit($fields);
            $lastRaw = $response['raw'];
            $lastStatus = $response['status'];
            $lastErr = $response['err'];

            if ($lastErr === '' && $lastStatus >= 200 && $lastStatus < 300) {
                $decoded = json_decode((string)$lastRaw, true);
                $b64 = $decoded['data'][0]['b64_json'] ?? null;

                if (!$b64) {
                    throw new RuntimeException('OpenAI no devolvio b64_json para la obra raiz. Respuesta: ' . $lastRaw);
                }

                return $b64;
            }

            if (!in_array($lastStatus, [429, 500, 502, 503, 504], true)) {
                break;
            }

            sleep($attempt * 3);
        }

        if ($lastErr !== '') {
            throw new RuntimeException('Error CURL OpenAI: ' . $lastErr);
        }

        throw new RuntimeException('Error OpenAI HTTP ' . $lastStatus . ': ' . $lastRaw);
    }

    private function postImageEdit(array $fields): array
    {
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
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'raw' => (string)$raw,
            'err' => (string)$err,
            'status' => $statusCode,
        ];
    }

    private function prepareApiImage(string $sourcePath, string $apiDir, string $name): string
    {
        if (!extension_loaded('gd')) {
            $fallback = $apiDir . DIRECTORY_SEPARATOR . $name . '_' . basename($sourcePath);
            copy($sourcePath, $fallback);
            return $fallback;
        }

        $image = $this->loadGdImage($sourcePath);

        if (!$image) {
            $fallback = $apiDir . DIRECTORY_SEPARATOR . $name . '_' . basename($sourcePath);
            copy($sourcePath, $fallback);
            return $fallback;
        }

        imagepalettetotruecolor($image);
        $width = imagesx($image);
        $height = imagesy($image);
        $maxSide = 1800;
        $largest = max($width, $height);

        if ($largest > $maxSide) {
            $scale = $maxSide / $largest;
            $newWidth = max(1, (int)round($width * $scale));
            $newHeight = max(1, (int)round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        $targetPath = $apiDir . DIRECTORY_SEPARATOR . $name . '.jpg';
        imagejpeg($image, $targetPath, 86);
        imagedestroy($image);

        return $targetPath;
    }

    private function loadGdImage(string $path): mixed
    {
        $mime = @mime_content_type($path);

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
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

    private function targetSize(array $status, string $source): string
    {
        $configuredSize = ProviderSettings::openAIImageSize();

        if ($configuredSize !== '') {
            return $configuredSize;
        }

        $m = $status['measurements'] ?? [];
        $width = (float)str_replace(',', '.', (string)($m['width'] ?? '0'));
        $height = (float)str_replace(',', '.', (string)($m['height'] ?? '0'));

        if ($width > 0 && $height > 0) {
            $ratio = $width / $height;
        } else {
            $meta = $this->imageMeta($source);
            $ratio = (float)($meta['aspect_ratio'] ?? 1);
        }

        if ($ratio > 1.18) {
            return '1536x1024';
        }

        if ($ratio < 0.85) {
            return '1024x1536';
        }

        return '1024x1024';
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

    private function mime(string $path): string
    {
        $mime = @mime_content_type($path);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/jpeg';
    }
}
