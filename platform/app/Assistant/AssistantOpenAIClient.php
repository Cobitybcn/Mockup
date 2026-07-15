<?php
declare(strict_types=1);

final class AssistantOpenAIClient
{
    public function __construct(private AssistantConfig $config) {}

    public function create(array $payload): array
    {
        $provider = strtolower(trim((string)($payload['provider'] ?? $this->config->provider())));
        if (!in_array($provider, ['gemini', 'openai'], true)) {
            $provider = $this->config->provider();
        }
        unset($payload['provider']);

        if ($provider === 'gemini') {
            return $this->createGemini($payload);
        }

        return $this->createOpenAi($payload);
    }

    private function createGemini(array $payload): array
    {
        if (!class_exists('GeminiImageClient')) {
            require_once __DIR__ . '/../Services/GeminiImageClient.php';
        }
        $geminiClient = new GeminiImageClient();
        $python = $geminiClient->getPythonExecutable();
        $bridgeScript = realpath(__DIR__ . '/../Services/vertex_bridge.py');
        if ($bridgeScript === false) {
            throw new AssistantException('No se encontró el script vertex_bridge.py.', 'gemini_bridge_missing');
        }

        $tempDir = $this->getTempDir();
        $tempPayloadFile = tempnam($tempDir, 'gemini_assistant_payload_');
        $tempOutFile = tempnam($tempDir, 'gemini_assistant_out_');
        $tempErrFile = tempnam($tempDir, 'gemini_assistant_err_');

        if ($tempPayloadFile === false || $tempOutFile === false || $tempErrFile === false) {
            throw new AssistantException('No se pudieron crear archivos temporales.', 'temp_file_error');
        }

        // Adjust model if it looks like a placeholder
        $model = $payload['model'] ?? 'gemini-2.5-flash';
        if (str_starts_with($model, 'gpt-') || $model === 'gpt-5.6-terra') {
            $model = 'gemini-2.5-flash';
        }
        $payload['model'] = $model;

        if (file_put_contents($tempPayloadFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            $this->cleanup([$tempPayloadFile, $tempOutFile, $tempErrFile]);
            throw new AssistantException('No se pudo escribir la solicitud del asistente.', 'temp_file_error');
        }

        $cmd = '"' . $python . '" ' . escapeshellarg($bridgeScript) . ' assistant-chat --payload-file ' . escapeshellarg($tempPayloadFile);

        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["file", $tempOutFile, "w"], // stdout
            2 => ["file", $tempErrFile, "w"]  // stderr
        ];

        $processEnv = $this->pythonProcessEnv();
        $process = proc_open($cmd, $descriptorspec, $pipes, null, $processEnv);
        if (!is_resource($process)) {
            $this->cleanup([$tempPayloadFile, $tempOutFile, $tempErrFile]);
            throw new AssistantException('No se pudo iniciar el proceso del asistente de Gemini.', 'gemini_start_error');
        }

        fclose($pipes[0]);

        $timeout = 120;
        $startTime = time();
        $status = proc_get_status($process);
        while ($status['running']) {
            if (time() - $startTime > $timeout) {
                proc_terminate($process, 9);
                proc_close($process);
                $this->cleanup([$tempPayloadFile, $tempOutFile, $tempErrFile]);
                throw new AssistantException('La consulta al asistente de Gemini excedió el tiempo límite.', 'gemini_timeout');
            }
            usleep(100000); // 100ms
            $status = proc_get_status($process);
        }

        $exitCode = (int)$status['exitcode'];
        $stdout = (string)@file_get_contents($tempOutFile);
        $stderr = (string)@file_get_contents($tempErrFile);

        proc_close($process);
        $this->cleanup([$tempPayloadFile, $tempOutFile, $tempErrFile]);

        if ($exitCode !== 0) {
            $errorText = strtolower($stderr);
            if (str_contains($errorText, 'permission_denied') || str_contains($errorText, 'aiplatform.endpoints.predict')) {
                throw new AssistantException('Gemini no tiene permisos para responder en el proyecto configurado.', 'gemini_permission_denied');
            }
            if (str_contains($errorText, 'resource_exhausted') || str_contains($errorText, '429')) {
                throw new AssistantException('Gemini está aplicando un límite temporal. Intenta dentro de un momento.', 'gemini_rate_limit');
            }
            if (str_contains($errorText, 'defaultcredentials') || str_contains($errorText, 'credentials')) {
                throw new AssistantException('Las credenciales de Gemini no están disponibles para el asistente.', 'gemini_credentials');
            }
            throw new AssistantException('Gemini no pudo completar esta solicitud.', 'gemini_execution_error');
        }

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded)) {
            throw new AssistantException('La respuesta del asistente de Gemini no es válida.', 'gemini_invalid_response');
        }

        return $decoded;
    }

    private function createOpenAi(array $payload): array
    {
        $apiKey = $this->config->apiKey();
        if ($apiKey === '') {
            throw new AssistantException('La conexión con OpenAI todavía no está configurada para esta plataforma.', 'openai_not_configured');
        }
        $handle = curl_init($this->config->apiBase() . '/responses');
        if ($handle === false) {
            throw new AssistantException('No se pudo iniciar la conexión con OpenAI.', 'openai_transport');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $transportError = curl_error($handle);
        curl_close($handle);
        if ($body === false || $transportError !== '') {
            throw new AssistantException('OpenAI no respondió a tiempo. Intenta nuevamente.', 'openai_transport');
        }
        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $error = is_array($decoded) ? (array)($decoded['error'] ?? []) : [];
            $type = (string)($error['code'] ?? $error['type'] ?? 'invalid_response');
            $message = match ($status) {
                401, 403 => 'La configuración de OpenAI no está autorizada.',
                429 => $type === 'insufficient_quota'
                    ? 'La cuenta de OpenAI no tiene cuota disponible.'
                    : 'OpenAI está aplicando un límite temporal. Intenta dentro de un momento.',
                default => 'OpenAI no pudo completar esta solicitud.',
            };
            throw new AssistantException($message, 'openai_' . preg_replace('/[^a-z0-9_]+/i', '_', $type));
        }
        return $decoded;
    }

    private function pythonProcessEnv(): array
    {
        $env = getenv();
        $env = is_array($env) ? array_map('strval', $env) : [];
        $env['PYTHONIOENCODING'] = 'utf-8';
        $env['PYTHONUTF8'] = '1';
        $env['PYTHONUNBUFFERED'] = '1';

        // Override standard Vertex env variables with Assistant-specific ones if configured
        $assistantProject = app_env('ASSISTANT_VERTEX_PROJECT_ID', '');
        if ($assistantProject === '') {
            $assistantProject = defined('VERTEX_PROJECT_ID') ? (string)VERTEX_PROJECT_ID : '';
        }
        if ($assistantProject !== '') {
            $env['VERTEX_PROJECT_ID'] = $assistantProject;
        }

        $assistantLocation = app_env('ASSISTANT_VERTEX_LOCATION', '');
        if ($assistantLocation === '') {
            $assistantLocation = defined('VERTEX_LOCATION') ? (string)VERTEX_LOCATION : '';
        }
        if ($assistantLocation !== '') {
            $env['VERTEX_LOCATION'] = $assistantLocation;
        }

        $assistantCredentials = app_env('ASSISTANT_GOOGLE_APPLICATION_CREDENTIALS', '');
        if ($assistantCredentials !== '') {
            $env['GOOGLE_APPLICATION_CREDENTIALS'] = $assistantCredentials;
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

    private function cleanup(array $files): void
    {
        foreach ($files as $file) {
            if ($file && is_file($file)) {
                @unlink($file);
            }
        }
    }
}
