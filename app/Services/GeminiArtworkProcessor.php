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

        $resultsDir = RESULTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }

        $rootCount = PromptSettings::rootArtworkCount();

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

        // Run all commands in parallel
        $results = $this->client->runCommandsParallel($cmds, $prompts, 150);

        // Validate results
        foreach ($results as $index => $res) {
            $v = $index + 1;
            if ($res['exit_code'] !== 0) {
                throw new RuntimeException("Error al generar la version {$v} de la imagen base: " . trim($res['stderr']));
            }
            if (!is_file($paths[$index])) {
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

    private function buildPromptForVersion(int $version, array $status, string $source): string
    {
        if ($version === 1) {
            return trim(PromptSettings::rootArtworkRulesFrontal());
        }

        if ($version === 2) {
            return trim(PromptSettings::rootArtworkRulesLeft());
        }

        if ($version === 3) {
            return trim(PromptSettings::rootArtworkRulesRight());
        }

        // Fallback for version > 3
        if ($version % 3 === 1) {
            return trim(PromptSettings::rootArtworkRulesFrontal());
        } elseif ($version % 3 === 2) {
            return trim(PromptSettings::rootArtworkRulesLeft());
        } else {
            return trim(PromptSettings::rootArtworkRulesRight());
        }
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
