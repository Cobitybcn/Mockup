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
        
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($bridgeScript) . ' generate-text --model ' . escapeshellarg($model) . ' --prompt -';
        foreach ($imagePaths as $imagePath) {
            $cmd .= ' --image ' . escapeshellarg($imagePath);
        }

        return $this->runCommand($cmd, $prompt);
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
        $tempOutput = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertex_gen_' . uniqid() . '.png';

        $model = $model ?: ProviderSettings::geminiImageModel();
        
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($bridgeScript) . ' generate-image --prompt - --output ' . escapeshellarg($tempOutput);
        foreach ($imagePaths as $imagePath) {
            $cmd .= ' --image ' . escapeshellarg($imagePath);
        }
        if ($model !== '') {
            $cmd .= ' --model ' . escapeshellarg($model);
        }

        $this->runCommand($cmd, $prompt);

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

    private function runCommand(string $cmd, string $stdinInput): string
    {
        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start Vertex bridge process.");
        }

        // Write the prompt to the python script's stdin
        fwrite($pipes[0], $stdinInput);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException("Vertex bridge failed (exit code $status): " . trim($stderr));
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
        $candidates = [
            'C:\Users\MSI\AppData\Local\Programs\Python\Python313\python.exe',
            'C:\Users\MSI\AppData\Local\Programs\Python\Python312\python.exe',
            'C:\Users\MSI\AppData\Local\Programs\Python\Python311\python.exe',
            'C:\laragon\bin\python\python-3.13\python.exe',
        ];

        foreach ($candidates as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }

        return 'python'; // Fallback
    }
}

