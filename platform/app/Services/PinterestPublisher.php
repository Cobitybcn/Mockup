<?php
declare(strict_types=1);

final class PinterestPublisher
{
    public static function requiredScopes(): array
    {
        return ['user_accounts:read', 'boards:read', 'boards:write', 'pins:read', 'pins:write'];
    }

    public function imagePinPayload(array $variant, array $item, string $boardId, string $absoluteLandingUrl, string $absoluteImageUrl): array
    {
        if ($boardId === '' || !filter_var($absoluteLandingUrl, FILTER_VALIDATE_URL) || !filter_var($absoluteImageUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Pinterest requiere board y URLs públicas absolutas.');
        }
        $payload = [
            'board_id' => $boardId,
            'link' => $absoluteLandingUrl,
            'title' => mb_substr(trim((string)($variant['title'] ?? $item['title'] ?? '')), 0, 100),
            'description' => mb_substr(trim((string)($variant['description'] ?? $item['description'] ?? '')), 0, 500),
            'alt_text' => mb_substr(trim((string)($item['alt_text'] ?? '')), 0, 500),
            'media_source' => ['source_type' => 'image_url', 'url' => $absoluteImageUrl, 'is_standard' => true],
        ];
        $sectionId=trim((string)($item['board_section_id']??''));if($sectionId!=='')$payload['board_section_id']=$sectionId;
        return $payload;
    }

    public function imageBase64PinPayload(array $variant, array $item, string $boardId, string $absoluteLandingUrl, string $imagePath): array
    {
        if ($boardId === '' || !filter_var($absoluteLandingUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Pinterest requiere un tablero y un enlace de destino válido.');
        }
        if (!is_file($imagePath) || !is_readable($imagePath)) {
            throw new RuntimeException('No se encontró la imagen preparada para Pinterest.');
        }
        $mime = strtolower((string)(mime_content_type($imagePath) ?: ''));
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('Pinterest solo admite imágenes JPG o PNG en esta publicación directa.');
        }
        $contents = file_get_contents($imagePath);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('No se pudo leer la imagen preparada para Pinterest.');
        }
        $payload = [
            'board_id' => $boardId,
            'link' => $absoluteLandingUrl,
            'title' => mb_substr(trim((string)($variant['title'] ?? $item['title'] ?? '')), 0, 100),
            'description' => mb_substr(trim((string)($variant['description'] ?? $item['description'] ?? '')), 0, 500),
            'alt_text' => mb_substr(trim((string)($item['alt_text'] ?? '')), 0, 500),
            'media_source' => [
                'source_type' => 'image_base64',
                'content_type' => $mime,
                'data' => base64_encode($contents),
                'is_standard' => true,
            ],
        ];
        $sectionId = trim((string)($item['board_section_id'] ?? ''));
        if ($sectionId !== '') $payload['board_section_id'] = $sectionId;
        return $payload;
    }

    public function createImagePin(string $accessToken, array $payload): array
    {
        if ($accessToken === '') throw new InvalidArgumentException('Falta el access token de Pinterest.');
        $base=app_env('PINTEREST_API_ENVIRONMENT','production')==='sandbox'?'https://api-sandbox.pinterest.com/v5':'https://api.pinterest.com/v5';
        $curl = curl_init($base . '/pins');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 30]);
        $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $error = curl_error($curl); curl_close($curl);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($error !== '' || $status < 200 || $status >= 300 || !is_array($decoded)) {
            throw new RuntimeException('Pinterest API rechazó la publicación (' . $status . '): ' . ($error ?: (string)$body));
        }
        return $decoded;
    }
}
