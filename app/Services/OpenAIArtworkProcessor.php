<?php
declare(strict_types=1);

class OpenAIArtworkProcessor implements ArtworkProcessorInterface
{
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

        $outputNameTemplate = 'base_artwork_ai_' . $jobId . '_v';
        $prompt = $this->buildPrompt($status, $source);
        $targetSize = $this->targetSize($status, $source);

        file_put_contents($jobDir . '/prompt.txt', $prompt);
        file_put_contents($jobDir . '/target_size.txt', $targetSize);

        $images = $this->callImageEditCandidates($jobDir, $source, $status, $prompt, $targetSize);

        $files = [];
        $paths = [];
        $i = 1;
        foreach ($images as $imageData) {
            $outputName = $outputNameTemplate . $i . '.png';
            $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;
            file_put_contents($outputPath, $imageData);
            $files[] = $outputName;
            $paths[] = $outputPath;
            $i++;
        }

        return [
            'files' => $files,
            'paths' => $paths,
            'mock' => false,
            'ai_root_enhancement' => true,
            'message' => 'Root image enhanced (3 versions). Please select one.',
            'meta' => $this->imageMeta($paths[0]),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        return PromptSettings::rootArtworkRules();
    }

    private function callImageEditCandidates(string $jobDir, string $source, array $status, string $prompt, string $targetSize): array
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
            'n' => '3',
            'response_format' => 'b64_json',
            'image' => new CURLFile($mainApiPath, $this->mime($mainApiPath), basename($mainApiPath)),
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
                
                $images = [];
                if (isset($decoded['data']) && is_array($decoded['data'])) {
                    foreach ($decoded['data'] as $item) {
                        $b64 = $item['b64_json'] ?? null;
                        if ($b64) {
                            $decodedB64 = base64_decode($b64);
                            if ($decodedB64) {
                                $images[] = $decodedB64;
                            }
                        }
                    }
                }

                if (count($images) === 0) {
                    throw new RuntimeException('OpenAI no devolvio imagenes validas para la obra raiz. Respuesta: ' . $lastRaw);
                }

                return $images;
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
