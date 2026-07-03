<?php
declare(strict_types=1);

class GeminiImageClient
{
    public function generateText(array $parts, string $model = 'gemini-2.5-flash'): string
    {
        $prompt = '';
        $imagePaths = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $prompt .= $part['text'] . "\n";
            } elseif (isset($part['__local_path'])) {
                $imagePaths[] = $part['__local_path'];
            }
        }

        $python = $this->getPythonExecutable();
        $bridgeScript = __DIR__ . '/vertex_bridge.py';
        
        $cmd = '"' . $python . '" ' . escapeshellarg($bridgeScript) . ' generate-text --model ' . escapeshellarg($model);
        foreach ($imagePaths as $imagePath) {
            $cmd .= ' --image ' . escapeshellarg($imagePath);
        }

        return $this->runCommand($cmd, $prompt, 180); // aumentado de 90s a 180s para permitir reintentos
    }

    /**
     * @param array<string,string> $envOverrides Per-call overrides applied on top of the
     *        process-wide env (pythonProcessEnv()). Used to force a stricter setting than
     *        the global PHP constants for one specific caller without changing the default
     *        for every other caller of vertex_bridge.py (see docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md,
     *        Fase 5).
     */
    public function generateImage(array $parts, ?string $model = null, array $envOverrides = []): string
    {
        $prompt = '';
        $imagePaths = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $prompt .= $part['text'] . "\n";
            } elseif (isset($part['__local_path'])) {
                $imagePaths[] = $part['__local_path'];
            }
        }

        $python = $this->getPythonExecutable();
        $bridgeScript = __DIR__ . '/vertex_bridge.py';

        // Generate a temporary file path for the output image
        $tempOutput = $this->getTempDir() . DIRECTORY_SEPARATOR . 'vertex_gen_' . uniqid() . '.png';

        $model = $model ?: ProviderSettings::geminiImageModel();

        $cmd = '"' . $python . '" ' . escapeshellarg($bridgeScript) . ' generate-image --output ' . escapeshellarg($tempOutput);
        foreach ($imagePaths as $imagePath) {
            $cmd .= ' --image ' . escapeshellarg($imagePath);
        }
        if ($model !== '') {
            $cmd .= ' --model ' . escapeshellarg($model);
        }

        $this->runCommand($cmd, $prompt, 200, $envOverrides); // aumentado de 150s a 200s para permitir reintentos

        if (!is_file($tempOutput)) {
            throw new RuntimeException("Vertex bridge did not create output image file at: " . $tempOutput);
        }

        $bytes = file_get_contents($tempOutput);
        @unlink($tempOutput);

        if ($bytes === false) {
            throw new RuntimeException("Failed to read output image from Vertex bridge.");
        }

        return base64_encode($bytes);
    }

    private function runCommand(string $cmd, string $promptText, int $timeout = 90, array $envOverrides = []): string
    {
        $tempDir = $this->getTempDir();
        $tempPromptFile = tempnam($tempDir, 'gemini_prompt_');
        if ($tempPromptFile === false) {
            throw new RuntimeException("Failed to create temporary prompt file.");
        }

        if (file_put_contents($tempPromptFile, $promptText) === false) {
            @unlink($tempPromptFile);
            throw new RuntimeException("Failed to write prompt to temporary file.");
        }

        $cmd .= ' --prompt-file ' . escapeshellarg($tempPromptFile);

        $tempOutFile = tempnam($tempDir, 'gemini_out_');
        $tempErrFile = tempnam($tempDir, 'gemini_err_');

        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["file", $tempOutFile, "w"], // stdout
            2 => ["file", $tempErrFile, "w"]  // stderr
        ];

        $processEnv = $envOverrides ? array_merge($this->pythonProcessEnv(), $envOverrides) : $this->pythonProcessEnv();
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $processEnv);
        if (!is_resource($process)) {
            @unlink($tempPromptFile);
            @unlink($tempOutFile);
            @unlink($tempErrFile);
            throw new RuntimeException("Failed to start Vertex bridge process.");
        }

        // Close stdin immediately as we are using --prompt-file
        fclose($pipes[0]);

        // Wait for the process to finish with timeout check
        $startTime = time();
        $status = proc_get_status($process);
        while ($status['running']) {
            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                proc_close($process);
                @unlink($tempPromptFile);
                @unlink($tempOutFile);
                @unlink($tempErrFile);
                throw new RuntimeException("Vertex bridge process timed out after {$timeout} seconds.");
            }
            usleep(50000); // 50ms
            $status = proc_get_status($process);
        }

        $exitCode = proc_close($process);

        $stdout = (string)@file_get_contents($tempOutFile);
        $stderr = (string)@file_get_contents($tempErrFile);

        @unlink($tempPromptFile);
        @unlink($tempOutFile);
        @unlink($tempErrFile);

        if ($exitCode !== 0) {
            throw new RuntimeException("Vertex bridge failed (exit code $exitCode): " . trim($stderr));
        }

        return $stdout;
    }

    public function imagePart(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('No se encontro imagen para Gemini: ' . $path);
        }

        return [
            'inline_data' => [
                'mime_type' => $this->mime($path),
                'data' => base64_encode((string)file_get_contents($path)),
            ],
            '__local_path' => $path,
        ];
    }

    public function textPart(string $text): array
    {
        return ['text' => $text];
    }

    private function mime(string $path): string
    {
        $mime = @mime_content_type($path);
        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? $mime : 'image/png';
    }

    public function getPythonExecutable(): string
    {
        // Punto #3: PYTHON_BINARY_PATH del .env tiene prioridad total
        $envPath = defined('PYTHON_BINARY_PATH') ? trim((string)PYTHON_BINARY_PATH) : '';
        if ($envPath !== '' && is_file($envPath)) {
            return $envPath;
        }

        // Candidatos de auto-detección (sin rutas específicas de usuario)
        $localAppData = rtrim((string)getenv('LOCALAPPDATA'), '/\\');
        $userPythonCandidates = [];
        if ($localAppData !== '') {
            foreach (['Python313', 'Python312', 'Python311'] as $versionDir) {
                $userPythonCandidates[] = $localAppData . '\\Programs\\Python\\' . $versionDir . '\\python.exe';
            }
        }

        $candidates = array_merge($userPythonCandidates, [
            'C:\laragon\bin\python\python-3.13\python.exe',
            'C:\laragon\bin\python\python-3.12\python.exe',
            'C:\laragon\bin\python\python-3.11\python.exe',
            'python',
        ]);

        // Primero buscar candidatos que tengan google.genai instalado
        foreach ($candidates as $cand) {
            $output = [];
            $exitCode = -1;
            if ($cand !== 'python' && !is_file($cand)) {
                continue;
            }
            $cmd = ($cand === 'python') ? 'python' : '"' . $cand . '"';
            @exec($cmd . ' -c "import google.genai" 2>&1', $output, $exitCode);
            if ($exitCode === 0) {
                return $cand;
            }
        }

        // Si ninguno tiene google.genai, devolver el primero que exista
        foreach ($candidates as $cand) {
            if ($cand !== 'python' && is_file($cand)) {
                return $cand;
            }
        }

        return 'python'; // Fallback
    }

    public function runCommandsParallel(array $cmds, array $prompts, int $timeout = 150): array
    {
        $tempDir = $this->getTempDir();
        $processEnv = $this->pythonProcessEnv();
        $processes = [];
        $tempFiles = [];

        foreach ($cmds as $index => $cmd) {
            $promptText = $prompts[$index] ?? '';
            $tempPromptFile = tempnam($tempDir, 'gemini_prompt_p_');
            if ($tempPromptFile === false) {
                throw new RuntimeException("Failed to create temporary prompt file for parallel index {$index}.");
            }
            file_put_contents($tempPromptFile, $promptText);
            $tempFiles[] = $tempPromptFile;

            // Append --prompt-file to the command
            $fullCmd = $cmd . ' --prompt-file ' . escapeshellarg($tempPromptFile);

            $tempOutFile = tempnam($tempDir, 'gemini_out_p_');
            $tempErrFile = tempnam($tempDir, 'gemini_err_p_');
            $tempFiles[] = $tempOutFile;
            $tempFiles[] = $tempErrFile;

            $descriptorspec = [
                0 => ["pipe", "r"], // stdin
                1 => ["file", $tempOutFile, "w"], // stdout
                2 => ["file", $tempErrFile, "w"]  // stderr
            ];

            $process = proc_open($fullCmd, $descriptorspec, $pipes, null, $processEnv);
            if (!is_resource($process)) {
                // Clean up what we created so far and throw
                foreach ($tempFiles as $f) { @unlink($f); }
                throw new RuntimeException("Failed to start parallel process index {$index}.");
            }
            fclose($pipes[0]); // Close stdin immediately

            $processes[$index] = [
                'process' => $process,
                'out_file' => $tempOutFile,
                'err_file' => $tempErrFile,
                'cmd' => $fullCmd,
            ];
        }

        // Wait for all processes to finish
        $startTime = time();
        $activeCount = count($processes);

        while ($activeCount > 0) {
            if (time() - $startTime > $timeout) {
                // Terminate all active processes
                foreach ($processes as $index => $pData) {
                    $status = proc_get_status($pData['process']);
                    if ($status['running']) {
                        proc_terminate($pData['process'], 9);
                    }
                    proc_close($pData['process']);
                }
                foreach ($tempFiles as $f) { @unlink($f); }
                throw new RuntimeException("Parallel processes timed out after {$timeout} seconds.");
            }

            $activeCount = 0;
            foreach ($processes as $index => $pData) {
                $status = proc_get_status($pData['process']);
                if ($status['running']) {
                    $activeCount++;
                }
            }
            if ($activeCount > 0) {
                usleep(100000); // 100ms
            }
        }

        // Collect exit codes and outputs
        $results = [];
        foreach ($processes as $index => $pData) {
            $exitCode = proc_close($pData['process']);
            $stdout = (string)@file_get_contents($pData['out_file']);
            $stderr = (string)@file_get_contents($pData['err_file']);

            $results[$index] = [
                'exit_code' => $exitCode,
                'stdout' => $stdout,
                'stderr' => $stderr,
            ];
        }

        // Clean up temp files
        foreach ($tempFiles as $f) {
            @unlink($f);
        }

        return $results;
    }

    private function pythonProcessEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? array_map('strval', $env) : [];
        $env['PYTHONIOENCODING'] = 'utf-8';
        $env['PYTHONUTF8'] = '1';

        if (defined('VERTEX_PROJECT_ID') && VERTEX_PROJECT_ID !== '') {
            $env['VERTEX_PROJECT_ID'] = (string)VERTEX_PROJECT_ID;
        }

        if (defined('MOCKUP_PROMPT_FIRST_MODE')) {
            $env['MOCKUP_PROMPT_FIRST_MODE'] = MOCKUP_PROMPT_FIRST_MODE ? 'true' : 'false';
        }
        if (defined('MOCKUP_PROMPT_FIRST_NO_MASK_MODE')) {
            $env['MOCKUP_PROMPT_FIRST_NO_MASK_MODE'] = MOCKUP_PROMPT_FIRST_NO_MASK_MODE ? 'true' : 'false';
        }
        if (defined('MOCKUP_USE_PRECOMPOSITION')) {
            $env['MOCKUP_USE_PRECOMPOSITION'] = MOCKUP_USE_PRECOMPOSITION ? 'true' : 'false';
        }
        if (defined('MOCKUP_USE_BACKGROUND_EDIT')) {
            $env['MOCKUP_USE_BACKGROUND_EDIT'] = MOCKUP_USE_BACKGROUND_EDIT ? 'true' : 'false';
        }

        return $env;
    }

    private function getTempDir(): string
    {
        $dir = __DIR__ . '/../../storage/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return realpath($dir) ?: $dir;
    }
}
