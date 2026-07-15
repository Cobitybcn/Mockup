<?php
declare(strict_types=1);

use Google\Auth\ApplicationDefaultCredentials;

final class VertexVeoProvider implements VideoGenerationProvider
{
    private const MODELS = ['veo-3.1-generate-001','veo-3.1-fast-generate-001'];
    private string $projectId;
    private string $location;
    private string $modelId;

    public function __construct(?string $model = null)
    {
        $this->projectId = trim(app_env('VIDEO_VERTEX_PROJECT_ID', app_env('GCP_PROJECT_ID', defined('VERTEX_PROJECT_ID') ? (string)VERTEX_PROJECT_ID : '')));
        $this->location = trim(app_env('VIDEO_VERTEX_LOCATION', 'us-central1')) ?: 'us-central1';
        $requested = trim((string)($model ?? app_env('VIDEO_VEO_MODEL', 'veo-3.1-fast-generate-001')));
        if (!in_array($requested, self::MODELS, true)) {
            throw new InvalidArgumentException('Unsupported Veo model.');
        }
        $this->modelId = $requested;
    }

    public function name(): string { return 'vertex_veo'; }
    public function model(): string { return $this->modelId; }

    public function generateFromImage(array $payload): array
    {
        $this->assertConfigured();
        $path = (string)($payload['imagePath'] ?? '');
        if ($path === '' || !is_file($path)) throw new InvalidArgumentException('The scene reference image is unavailable.');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Could not read the scene reference image.');
        if (strlen($bytes) > 20 * 1024 * 1024) throw new InvalidArgumentException('Veo image references must be 20 MB or smaller.');
        $mime = (string)($payload['mimeType'] ?? (@mime_content_type($path) ?: ''));
        if (!in_array($mime, ['image/jpeg','image/png'], true)) {
            throw new InvalidArgumentException('Veo accepts JPEG or PNG scene references in this phase.');
        }

        $parameters = [
            'sampleCount' => 1,
            'durationSeconds' => (int)$payload['durationSeconds'],
            'aspectRatio' => (string)$payload['aspectRatio'],
            'resolution' => (string)($payload['resolution'] ?? '720p'),
            'resizeMode' => 'pad',
        ];
        $storageUri = trim((string)($payload['storageUri'] ?? ''));
        if ($storageUri !== '') $parameters['storageUri'] = rtrim($storageUri, '/') . '/';

        $response = $this->request($this->modelEndpoint('predictLongRunning'), [
            'instances' => [[
                'prompt' => (string)$payload['prompt'],
                'image' => [
                    'bytesBase64Encoded' => base64_encode($bytes),
                    'mimeType' => $mime,
                ],
            ]],
            'parameters' => $parameters,
        ]);
        $operation = trim((string)($response['name'] ?? ''));
        if ($operation === '') throw new RuntimeException('Vertex AI did not return a generation operation ID.');
        return ['jobId' => $operation, 'status' => 'processing', 'response' => $response];
    }

    public function generateFromFrames(array $payload): array
    {
        $this->assertConfigured();
        $start = $this->encodedImage(
            (string)($payload['startImagePath'] ?? ''),
            (string)($payload['startMimeType'] ?? ''),
            'Start Frame'
        );
        $end = $this->encodedImage(
            (string)($payload['endImagePath'] ?? ''),
            (string)($payload['endMimeType'] ?? ''),
            'End Frame'
        );
        $parameters = [
            'sampleCount' => 1,
            'durationSeconds' => (int)$payload['durationSeconds'],
            'aspectRatio' => (string)$payload['aspectRatio'],
            'resolution' => (string)($payload['resolution'] ?? '720p'),
            'resizeMode' => 'pad',
        ];
        $storageUri = trim((string)($payload['storageUri'] ?? ''));
        if ($storageUri !== '') $parameters['storageUri'] = rtrim($storageUri, '/') . '/';

        $response = $this->request($this->modelEndpoint('predictLongRunning'), [
            'instances' => [[
                'prompt' => (string)$payload['prompt'],
                'image' => $start,
                'lastFrame' => $end,
            ]],
            'parameters' => $parameters,
        ]);
        $operation = trim((string)($response['name'] ?? ''));
        if ($operation === '') throw new RuntimeException('Vertex AI did not return a generation operation ID.');
        return ['jobId' => $operation, 'status' => 'processing', 'response' => $response];
    }

    public function extendVideo(array $payload): array
    {
        throw new DomainException('Video extension is prepared but not connected in this phase.');
    }

    public function getJobStatus(string $jobId): array
    {
        $this->assertConfigured();
        if (trim($jobId) === '') throw new InvalidArgumentException('Missing Vertex AI operation ID.');
        $response = $this->request($this->modelEndpoint('fetchPredictOperation'), ['operationName' => $jobId]);
        if (empty($response['done'])) return ['status' => 'processing', 'response' => $response];
        if (isset($response['error'])) {
            $message = (string)($response['error']['message'] ?? 'Veo generation failed.');
            return ['status' => 'failed', 'error' => $message, 'response' => $response];
        }
        $output = $this->findVideoOutput($response['response'] ?? $response);
        if ($output === null) return ['status' => 'failed', 'error' => 'Veo completed without a readable video output.', 'response' => $response];
        return ['status' => 'succeeded', 'output' => $output, 'response' => $response];
    }

    private function assertConfigured(): void
    {
        if (!ProviderSettings::allowRealApi()) throw new DomainException('Real API generation is disabled in API Settings.');
        if ($this->projectId === '') throw new RuntimeException('VIDEO_VERTEX_PROJECT_ID or GCP_PROJECT_ID is required for Veo.');
    }

    /** @return array{bytesBase64Encoded:string,mimeType:string} */
    private function encodedImage(string $path, string $mime, string $label): array
    {
        if ($path === '' || !is_file($path)) throw new InvalidArgumentException($label . ' is unavailable.');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Could not read ' . $label . '.');
        if (strlen($bytes) > 20 * 1024 * 1024) throw new InvalidArgumentException($label . ' must be 20 MB or smaller.');
        if ($mime === '') $mime = (string)(@mime_content_type($path) ?: '');
        if (!in_array($mime, ['image/jpeg','image/png'], true)) throw new InvalidArgumentException($label . ' must be JPEG or PNG.');
        return ['bytesBase64Encoded' => base64_encode($bytes), 'mimeType' => $mime];
    }

    private function modelEndpoint(string $method): string
    {
        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:%s',
            rawurlencode($this->location), rawurlencode($this->projectId), rawurlencode($this->location), rawurlencode($this->modelId), $method
        );
    }

    private function request(string $url, array $payload): array
    {
        $token = $this->accessToken();
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Could not initialize the Vertex AI request.');
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) throw new RuntimeException('Vertex AI network error: ' . $error);
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) throw new RuntimeException('Vertex AI returned an invalid response.');
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException((string)($decoded['error']['message'] ?? ('Vertex AI request failed with HTTP ' . $status . '.')));
        }
        return $decoded;
    }

    private function accessToken(): string
    {
        $explicit = trim(app_env('GOOGLE_OAUTH_ACCESS_TOKEN', ''));
        if ($explicit !== '') return $explicit;

        if (class_exists(ApplicationDefaultCredentials::class)) {
            $credentials = ApplicationDefaultCredentials::getCredentials(['https://www.googleapis.com/auth/cloud-platform']);
            $token = $credentials->fetchAuthToken();
            if (!empty($token['access_token'])) return (string)$token['access_token'];
        }

        $metadata = $this->metadataToken();
        if ($metadata !== '') return $metadata;

        if (PHP_SAPI === 'cli') {
            $command = DIRECTORY_SEPARATOR === '\\'
                ? 'gcloud auth application-default print-access-token 2>NUL'
                : 'gcloud auth application-default print-access-token 2>/dev/null';
            $token = trim((string)@shell_exec($command));
            if ($token !== '') return $token;
        }
        throw new RuntimeException('Google Application Default Credentials are unavailable.');
    }

    private function metadataToken(): string
    {
        $handle = curl_init('http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token');
        if ($handle === false) return '';
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'], CURLOPT_CONNECTTIMEOUT_MS => 400, CURLOPT_TIMEOUT_MS => 1200]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($status !== 200 || !is_string($body)) return '';
        $decoded = json_decode($body, true);
        return is_array($decoded) ? trim((string)($decoded['access_token'] ?? '')) : '';
    }

    private function findVideoOutput(mixed $node): ?array
    {
        if (!is_array($node)) return null;
        foreach (['gcsUri','uri'] as $key) {
            if (!empty($node[$key]) && is_string($node[$key])) return ['type' => 'uri', 'value' => $node[$key], 'mimeType' => (string)($node['mimeType'] ?? 'video/mp4')];
        }
        if (!empty($node['bytesBase64Encoded']) && is_string($node['bytesBase64Encoded'])) {
            return ['type' => 'base64', 'value' => $node['bytesBase64Encoded'], 'mimeType' => (string)($node['mimeType'] ?? 'video/mp4')];
        }
        foreach ($node as $value) {
            $found = $this->findVideoOutput($value);
            if ($found !== null) return $found;
        }
        return null;
    }
}
