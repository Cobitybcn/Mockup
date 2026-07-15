<?php
declare(strict_types=1);

final class AssistantOpenAIClient
{
    public function __construct(private AssistantConfig $config) {}

    public function create(array $payload): array
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
}
