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

        // Punto #4: pasar VERTEX_PROJECT_ID como variable de entorno al subproceso
        if (defined('VERTEX_PROJECT_ID') && VERTEX_PROJECT_ID !== '') {
            putenv('VERTEX_PROJECT_ID=' . VERTEX_PROJECT_ID);
        }

        return $this->runCommand($cmd, $prompt, 90); // punto #7: 90 s (era 60 s)
    }

    public function generateImage(array $parts, ?string $model = null): string
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

        // Punto #4: pasar VERTEX_PROJECT_ID como variable de entorno al subproceso
        if (defined('VERTEX_PROJECT_ID') && VERTEX_PROJECT_ID !== '') {
            putenv('VERTEX_PROJECT_ID=' . VERTEX_PROJECT_ID);
        }

        $this->runCommand($cmd, $prompt, 150); // punto #7: 150 s (era 120 s)

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

    private function runCommand(string $cmd, string $promptText, int $timeout = 90): string
    {
        putenv('PYTHONIOENCODING=utf-8');
        putenv('PYTHONUTF8=1');

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

        $process = proc_open($cmd, $descriptorspec, $pipes);
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

    private function getPythonExecutable(): string
    {
        // Punto #3: PYTHON_BINARY_PATH del .env tiene prioridad total
        $envPath = defined('PYTHON_BINARY_PATH') ? trim((string)PYTHON_BINARY_PATH) : '';
        if ($envPath !== '' && is_file($envPath)) {
            return $envPath;
        }

        // Candidatos de auto-detección (sin rutas específicas de usuario)
        $candidates = [
            'python',
            'C:\laragon\bin\python\python-3.13\python.exe',
            'C:\laragon\bin\python\python-3.12\python.exe',
            'C:\laragon\bin\python\python-3.11\python.exe',
        ];

        // Primero buscar candidatos que tengan google.genai instalado
        foreach ($candidates as $cand) {
            $output = [];
            $exitCode = -1;
            $cmd = ($cand === 'python') ? 'python' : '"' . $cand . '"';
            @exec($cmd . ' -c "import google.genai" 2>&1', $output, $exitCode);
            if ($exitCode === 0) {
                return $cand;
            }
        }

        // Si ninguno tiene google.genai, devolver el primero que exista
        foreach ($candidates as $cand) {
            if ($cand === 'python' || is_file($cand)) {
                return $cand;
            }
        }

        return 'python'; // Fallback
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

