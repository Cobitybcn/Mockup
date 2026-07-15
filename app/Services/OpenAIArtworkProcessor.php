<?php
declare(strict_types=1);

class OpenAIArtworkProcessor implements ArtworkProcessorInterface
{
    private string $apiKey;
    private string $model;
    private string $quality;

    public function __construct(string $apiKey = '', string $model = '', string $quality = '')
    {
        $this->apiKey = trim($apiKey !== '' ? $apiKey : ProviderSettings::openAIAPIKey());
        $configuredModel = trim($model !== '' ? $model : ProviderSettings::openAIImageModel());
        $this->model = str_starts_with($configuredModel, 'gpt-image-') ? $configuredModel : 'gpt-image-2';
        $configuredQuality = strtolower(trim($quality !== '' ? $quality : ProviderSettings::openAIImageQuality()));
        $this->quality = in_array($configuredQuality, ['low', 'medium', 'high', 'auto'], true)
            ? $configuredQuality
            : 'medium';
    }

    public function createRootImage(string $jobDir, array $status): array
    {
        $mainFile = basename((string)($status['main_file'] ?? ''));
        $source = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . $mainFile;
        $jobId = basename($jobDir);

        if (!$mainFile || !is_file($source)) {
            throw new RuntimeException('No se encontro la imagen principal del job.');
        }
        $source = ManualArtworkFrameCropper::cropIfAvailable($source, $status, $jobDir);

        $resultsDir = RESULTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }

        $outputNameTemplate = 'base_artwork_ai_' . $jobId . '_v';
        $prompt = $this->buildPrompt($status, $source);
        $targetSize = $this->targetSize($status, $source);
        $rootCount = !empty($status['user_scene_flow']) ? 1 : PromptSettings::rootArtworkCount();

        file_put_contents($jobDir . '/prompt.txt', $prompt);
        file_put_contents($jobDir . '/target_size.txt', $targetSize);

        $images = $this->callImageEditCandidates($jobDir, $source, $status, $prompt, $targetSize, $rootCount);

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
            'message' => "Root image enhanced ({$rootCount} versions). Please select one.",
            'meta' => $this->imageMeta($paths[0]),
        ];
    }

    private function buildPrompt(array $status, string $source): string
    {
        $prompt = trim(PromptSettings::rootArtworkRules());
        $m = (array)($status['measurements'] ?? []);
        $shape = (string)($m['artwork_shape'] ?? '');
        $width = trim((string)($m['width'] ?? ''));
        $height = trim((string)($m['height'] ?? ''));
        $unit = trim((string)($m['unit'] ?? 'cm'));

        $lines = [];
        if (in_array($shape, ['portrait', 'landscape', 'square'], true)) {
            $lines[] = 'Resolved artwork format: ' . $shape . '. Preserve this orientation and aspect family. Do not square, rotate, stretch, squeeze, widen, shorten, or reinterpret the artwork format.';
        }
        if ($width !== '' && $height !== '') {
            $lines[] = "Resolved physical artwork dimensions: {$width} {$unit} wide x {$height} {$unit} high. Use these as hidden metadata only; never render measurement text.";
        }
        $lines[] = 'If the uploaded photo includes background, margins, wall, floor, hands, camera perspective, or surrounding objects, treat them only as capture noise. The framed/cropped artwork is the only root artwork authority.';

        return rtrim($prompt) . "\n\nROOT ARTWORK GEOMETRY LOCK\n" . implode("\n", $lines);
    }

    private function callImageEditCandidates(string $jobDir, string $source, array $status, string $prompt, string $targetSize, int $rootCount): array
    {
        $apiDir = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . 'api_inputs';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0775, true);
        }

        $mainApiPath = $this->prepareApiImage($source, $apiDir, 'main');

        $fields = [
            'model' => $this->model,
            'prompt' => $prompt,
            'size' => $targetSize,
            'quality' => $this->quality,
            'n' => (string)$rootCount,
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
        if ($this->apiKey === '') {
            throw new RuntimeException('Falta OPENAI_API_KEY para preparar la obra raiz.');
        }

        $ch = curl_init('https://api.openai.com/v1/images/edits');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
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

    private function targetSize(array $status, string $source): string
    {
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
