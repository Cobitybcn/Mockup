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
        $jobId = basename($jobDir);

        if (!$mainFile || !is_file($source)) {
            throw new RuntimeException('No se encontro la imagen principal del job.');
        }
        $source = ManualArtworkFrameCropper::cropIfAvailable($source, $status, $jobDir);

        $resultsDir = RESULTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }

        $rootCount = !empty($status['user_scene_flow'])
            ? RootArtworkViewSetService::requiredCount()
            : PromptSettings::rootArtworkCount();

        $promptsMap = [];
        for ($i = 1; $i <= $rootCount; $i++) {
            $promptsMap[$i] = $this->buildPromptForVersion($i, $status, $source);
        }

        // Write all prompts to prompt.txt for reference/UI
        $promptSummary = '';
        foreach ($promptsMap as $i => $vPrompt) {
            $promptSummary .= "=== VERSION {$i} ===\n{$vPrompt}\n\n";
        }
        file_put_contents($jobDir . '/prompt.txt', trim($promptSummary));

        $model = ProviderSettings::geminiImageModel();

        file_put_contents($jobDir . '/target_size.txt', 'gemini-native model=' . $model);

        $files = [];
        $paths = [];

        $python = $this->client->getPythonExecutable();
        $bridgeScript = __DIR__ . '/vertex_bridge.py';

        // Build base command for generating image
        $baseCmd = '"' . $python . '" ' . escapeshellarg($bridgeScript) . ' generate-image';
        // Add main file
        $baseCmd .= ' --image ' . escapeshellarg($source);
        // Add extra files if any
        foreach (($status['extra_files'] ?? []) as $extraFile) {
            $extraPath = rtrim($jobDir, '/\\') . DIRECTORY_SEPARATOR . basename((string)$extraFile);
            if (is_file($extraPath)) {
                $baseCmd .= ' --image ' . escapeshellarg($extraPath);
            }
        }
        if ($model !== '') {
            $baseCmd .= ' --model ' . escapeshellarg($model);
        }

        $cmds = [];
        $prompts = [];

        for ($i = 1; $i <= $rootCount; $i++) {
            $outputName = 'base_artwork_gemini_' . $jobId . '_v' . $i . '.png';
            $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;
            
            $cmd = $baseCmd . ' --output ' . escapeshellarg($outputPath);
            
            $cmds[] = $cmd;
            $prompts[] = $promptsMap[$i];
            $files[] = $outputName;
            $paths[] = $outputPath;

            // Log prompt details for auditability
            $this->logRootPromptInfo($i, $promptsMap[$i]);
        }
        // Root preparation can be slower on Cloud Run cold starts and mobile uploads.
        $rootTimeout = !empty($status['user_scene_flow']) ? 360 : 240;
        $results = $this->client->runCommandsParallel($cmds, $prompts, $rootTimeout);

        // Validate results
        foreach ($results as $index => $res) {
            $v = $index + 1;
            $outputExists = is_file($paths[$index]);
            if ($res['exit_code'] !== 0 && !$outputExists) {
                $rawError = trim((string)($res['stderr'] ?? ''));
                file_put_contents($jobDir . '/gemini_root_v' . $v . '_stderr.log', $rawError);
                throw new RuntimeException($this->friendlyRootError($rawError, $v));
            }
            if ($res['exit_code'] !== 0 && $outputExists) {
                $rawError = trim((string)($res['stderr'] ?? ''));
                file_put_contents($jobDir . '/gemini_root_v' . $v . '_stderr_warning.log', $rawError);
            }
            if (!$outputExists) {
                throw new RuntimeException("La version {$v} de la imagen base no fue creada por el subproceso.");
            }
        }

        return [
            'files' => $files,
            'paths' => $paths,
            'mock' => false,
            'gemini_root_enhancement' => true,
            'message' => "Root image enhanced ({$rootCount} versions). Please select one.",
            'meta' => $this->imageMeta($paths[0]),
        ];
    }

    private function friendlyRootError(string $rawError, int $version): string
    {
        $error = strtolower($rawError);
        if (str_contains($error, 'permission_denied') || str_contains($error, 'iam_permission_denied') || str_contains($error, 'aiplatform.endpoints.predict')) {
            return 'Artwork preparation is not authorized for the configured Vertex AI project. Please verify the active Google Cloud account and Vertex AI permissions.';
        }
        if (str_contains($error, 'resource_exhausted') || str_contains($error, '429') || str_contains($error, 'quota')) {
            return 'Artwork preparation is temporarily limited by image generation capacity. Please try again in a few minutes.';
        }
        if (str_contains($error, 'timed out') || str_contains($error, 'deadline') || str_contains($error, 'timeout')) {
            return 'Artwork preparation took too long. Please try again with a clearer or smaller photo.';
        }
        if (str_contains($error, 'no candidates') || str_contains($error, 'blocked') || str_contains($error, 'safety')) {
            return 'Artwork preparation could not create a usable base image from this photo. Please try a clearer, well-lit image.';
        }
        return 'Artwork preparation could not be completed. Please try again with a clearer photo.';
    }

    private function buildPromptForVersion(int $version, array $status, string $source): string
    {
        if ($version === 1) {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesFrontal()), $status);
        }

        if ($version === 2) {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesLeft()), $status);
        }

        if ($version === 3) {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesRight()), $status);
        }

        // Fallback for version > 3
        if ($version % 3 === 1) {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesFrontal()), $status);
        } elseif ($version % 3 === 2) {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesLeft()), $status);
        } else {
            return $this->withGeometryDirective(trim(PromptSettings::rootArtworkRulesRight()), $status);
        }
    }

    private function withGeometryDirective(string $prompt, array $status): string
    {
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

    private function logRootPromptInfo(int $version, string $finalPrompt): void
    {
        $model = ProviderSettings::geminiImageModel();
        $timestamp = date('Y-m-d H:i:s');

        $viewNames = [
            1 => 'frontal root view',
            2 => 'three-quarter left root view',
            3 => 'three-quarter right root view',
        ];
        $rootView = $viewNames[$version] ?? ('view variant ' . $version);

        $keys = [
            1 => 'root_artwork_rules_frontal',
            2 => 'root_artwork_rules_left',
            3 => 'root_artwork_rules_right',
        ];
        $specificKey = $keys[$version] ?? 'root_artwork_rules_frontal';

        $allSettings = PromptSettings::all();
        $dbValue = trim($allSettings[$specificKey] ?? '');
        $source = $dbValue !== '' ? 'Admin' : 'Built-in default';

        $logMessage = "----------------------------------------\n";
        $logMessage .= "Timestamp: {$timestamp}\n";
        $logMessage .= "Root Image Number: {$version}\n";
        $logMessage .= "Root View: {$rootView}\n";
        $logMessage .= "Admin Key: {$specificKey}\n";
        $logMessage .= "Source: {$source}\n";
        $logMessage .= "Model: {$model}\n";
        $logMessage .= "Final Prompt Sent to Vertex:\n";
        $logMessage .= "{$finalPrompt}\n";
        $logMessage .= "----------------------------------------\n\n";

        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        @file_put_contents($logDir . '/root_prompts.log', $logMessage, FILE_APPEND);
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
