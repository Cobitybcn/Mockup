<?php
declare(strict_types=1);

use Google\Auth\ApplicationDefaultCredentials;

final class VertexGeminiOmniProvider implements VideoGenerationProvider
{
    private const MODELS = ['gemini-omni-flash-preview'];

    private string $projectId;
    private string $modelId;

    public function __construct(?string $model = null)
    {
        $this->projectId = trim(app_env(
            'VIDEO_VERTEX_PROJECT_ID',
            app_env('GCP_PROJECT_ID', defined('VERTEX_PROJECT_ID') ? (string)VERTEX_PROJECT_ID : '')
        ));
        $requested = trim((string)($model ?? app_env('VIDEO_OMNI_MODEL', 'gemini-omni-flash-preview')));
        if (!in_array($requested, self::MODELS, true)) {
            throw new InvalidArgumentException('Unsupported Gemini Omni video model.');
        }
        $this->modelId = $requested;
    }

    public function name(): string { return 'vertex_gemini_omni'; }
    public function model(): string { return $this->modelId; }

    public function generateFromImage(array $payload): array
    {
        $this->assertConfigured();
        $duration = (int)($payload['durationSeconds'] ?? 0);
        if ($duration < 3 || $duration > 10) {
            throw new InvalidArgumentException('Gemini Omni duration must be between 3 and 10 seconds.');
        }
        $aspectRatio = (string)($payload['aspectRatio'] ?? '');
        if (!in_array($aspectRatio, ['9:16','16:9'], true)) {
            throw new InvalidArgumentException('Gemini Omni supports 9:16 or 16:9 video in this studio.');
        }

        $responseFormat = $this->videoResponseFormat(
            $aspectRatio,
            $duration,
            (string)($payload['storageUri'] ?? '')
        );

        $input = [];
        $declarations = [];
        $guidance = [];
        $imageNumber = 1;

        $firstFrame = is_array($payload['firstFrame'] ?? null) ? $payload['firstFrame'] : null;
        if ($firstFrame === null && !empty($payload['imagePath'])) {
            $firstFrame = ['path' => $payload['imagePath'], 'mimeType' => $payload['mimeType'] ?? ''];
        }
        if ($firstFrame !== null) {
            $input[] = $this->encodedImageInput($firstFrame, 'Gemini Omni first frame');
            $declarations[] = '[# Sources <FIRST_FRAME>@Image' . $imageNumber . ']';
            $guidance[] = 'Use Image' . $imageNumber . ' as the exact starting frame.';
            $imageNumber++;
        }

        $referenceTags = [];
        $referenceDirections = [];
        $referenceIndex = 0;
        foreach ((array)($payload['referenceImages'] ?? []) as $referenceImage) {
            if (!is_array($referenceImage)) continue;
            if ($imageNumber > VideoReferencePolicy::MAX_IMAGES) {
                throw new InvalidArgumentException('Gemini Omni accepts up to 10 images per prompt.');
            }
            $input[] = $this->encodedImageInput($referenceImage, 'Gemini Omni visual reference');
            $referenceTags[] = '<IMAGE_REF_' . $referenceIndex . '>@Image' . $imageNumber;
            $instruction = trim((string)($referenceImage['instruction'] ?? ''));
            if ($instruction !== '') {
                $referenceDirections[] = sprintf(
                    '<IMAGE_REF_%d> (%s): %s',
                    $referenceIndex,
                    $this->roleLabel((string)($referenceImage['role'] ?? 'reference')),
                    $instruction
                );
            }
            $referenceIndex++;
            $imageNumber++;
        }
        if ($referenceTags !== []) {
            $declarations[] = '[# References ' . implode(' ', $referenceTags) . ']';
            $guidance[] = 'Use the remaining images as visual references according to the prompt, not as mandatory initial frames.';
        }

        $prompt = trim((string)($payload['prompt'] ?? ''));
        if ($declarations !== []) $prompt = implode(' ', $declarations) . "\n" . $prompt;
        if ($referenceDirections !== []) $prompt .= "\n\nREFERENCE PURPOSES IN PRIORITY ORDER\n" . implode("\n", $referenceDirections);
        if ($guidance !== []) $prompt .= "\n\n" . implode(' ', $guidance);
        $prompt .= "\nGenerate one continuous shot with no scene cuts unless the prompt explicitly requests them.";
        $input[] = ['type' => 'text', 'text' => trim($prompt)];

        $task = $firstFrame !== null
            ? 'image_to_video'
            : ($referenceTags !== [] ? 'reference_to_video' : 'text_to_video');

        $response = $this->request($this->interactionsEndpoint(), [
            'model' => $this->modelId,
            'input' => $input,
            'response_format' => $responseFormat,
            'background' => true,
            'store' => true,
            'generation_config' => [
                'video_config' => [
                    'task' => $task,
                ],
            ],
        ]);

        $interactionId = trim((string)($response['id'] ?? ''));
        if ($interactionId === '') {
            throw new RuntimeException('Gemini Omni did not return an interaction ID.');
        }
        return ['jobId' => $interactionId, 'status' => 'processing', 'response' => $response];
    }

    public function generateFromFrames(array $payload): array
    {
        throw new DomainException('Gemini Omni does not support first-to-last-frame interpolation. Use one main image.');
    }

    public function extendVideo(array $payload): array
    {
        throw new DomainException('Gemini Omni video extension is not supported.');
    }

    public function editVideo(array $payload): array
    {
        $this->assertConfigured();
        [, , $responseFormat] = $this->validatedVideoOutput($payload, true);
        $mime = (string)($payload['videoMimeType'] ?? 'video/mp4');
        $videoUri = trim((string)($payload['videoUri'] ?? ''));
        $input = [];
        if ($videoUri !== '') {
            $input[] = ['type' => 'video', 'uri' => $videoUri, 'mime_type' => $mime];
        } else {
            $input[] = $this->encodedVideoInput((string)($payload['videoPath'] ?? ''), $mime);
        }

        $declarations = [];
        $directions = [];
        $tags = [];
        $referenceIndex = 0;
        $imageNumber = 1;
        foreach ((array)($payload['referenceImages'] ?? []) as $referenceImage) {
            if (!is_array($referenceImage)) continue;
            if ($referenceIndex >= VideoReferencePolicy::MAX_IMAGES) {
                throw new InvalidArgumentException('Gemini Omni accepts up to 10 images per prompt.');
            }
            $input[] = $this->encodedImageInput($referenceImage, 'Gemini Omni visual reference');
            $tags[] = '<IMAGE_REF_' . $referenceIndex . '>@Image' . $imageNumber;
            $instruction = trim((string)($referenceImage['instruction'] ?? ''));
            if ($instruction !== '') {
                $directions[] = sprintf('<IMAGE_REF_%d> (%s): %s', $referenceIndex, $this->roleLabel((string)($referenceImage['role'] ?? 'reference')), $instruction);
            }
            $referenceIndex++;
            $imageNumber++;
        }
        if ($tags !== []) $declarations[] = '[# References ' . implode(' ', $tags) . ']';

        $prompt = trim((string)($payload['prompt'] ?? ''));
        if ($declarations !== []) $prompt = implode(' ', $declarations) . "\n" . $prompt;
        if ($directions !== []) $prompt .= "\n\nREFERENCE PURPOSES IN PRIORITY ORDER\n" . implode("\n", $directions);
        $prompt .= "\n\nKeep everything else in the source video the same unless the prompt explicitly requests a change.";
        $input[] = ['type' => 'text', 'text' => trim($prompt)];

        return $this->submitInteraction([
            'model' => $this->modelId,
            'input' => $input,
            'response_format' => $responseFormat,
            'background' => true,
            'store' => true,
            'generation_config' => ['video_config' => ['task' => 'edit']],
        ]);
    }

    public function editInteraction(array $payload): array
    {
        $this->assertConfigured();
        [, , $responseFormat] = $this->validatedVideoOutput($payload, true);
        $previousInteractionId = trim((string)($payload['previousInteractionId'] ?? ''));
        if ($previousInteractionId === '') throw new InvalidArgumentException('Missing previous Gemini Omni interaction ID.');
        $prompt = trim((string)($payload['prompt'] ?? ''));
        if ($prompt === '') throw new InvalidArgumentException('Describe the adjustment to apply.');
        $prompt .= "\n\nKeep everything else the same.";
        return $this->submitInteraction([
            'model' => $this->modelId,
            'previous_interaction_id' => $previousInteractionId,
            'input' => $prompt,
            'response_format' => $responseFormat,
            'background' => true,
            'store' => true,
            'generation_config' => ['video_config' => ['task' => 'edit']],
        ]);
    }

    public function getJobStatus(string $jobId): array
    {
        $this->assertConfigured();
        $jobId = trim($jobId);
        if ($jobId === '') throw new InvalidArgumentException('Missing Gemini Omni interaction ID.');

        $response = $this->request($this->interactionsEndpoint() . '/' . rawurlencode($jobId), null);
        $status = strtolower(trim((string)($response['status'] ?? '')));
        if (in_array($status, ['in_progress','queued','running'], true)) {
            return ['status' => 'processing', 'response' => $response];
        }
        if (in_array($status, ['failed','cancelled','canceled','expired'], true)) {
            return [
                'status' => 'failed',
                'error' => $this->errorMessage($response),
                'response' => $response,
            ];
        }
        if ($status !== 'completed') {
            return ['status' => 'processing', 'response' => $response];
        }

        $output = $this->findVideoOutput($response);
        if ($output === null) {
            return [
                'status' => 'failed',
                'error' => 'Gemini Omni completed without a readable video output.',
                'response' => $response,
            ];
        }
        return ['status' => 'succeeded', 'output' => $output, 'response' => $response];
    }

    private function assertConfigured(): void
    {
        if (!ProviderSettings::allowRealApi()) {
            throw new DomainException('Real API generation is disabled in API Settings.');
        }
        if ($this->projectId === '') {
            throw new RuntimeException('VIDEO_VERTEX_PROJECT_ID or GCP_PROJECT_ID is required for Gemini Omni.');
        }
    }

    /** @return array{type:string,data:string,mime_type:string} */
    private function encodedImageInput(array $image, string $label): array
    {
        $path = (string)($image['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new InvalidArgumentException($label . ' is unavailable.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Could not read ' . $label . '.');
        if (strlen($bytes) > 20 * 1024 * 1024) {
            throw new InvalidArgumentException($label . ' must be 20 MB or smaller.');
        }
        $mime = (string)($image['mimeType'] ?? (@mime_content_type($path) ?: ''));
        if (!in_array($mime, ['image/jpeg','image/png'], true)) {
            throw new InvalidArgumentException($label . ' must be JPEG or PNG.');
        }
        return ['type' => 'image', 'data' => base64_encode($bytes), 'mime_type' => $mime];
    }

    /** @return array{type:string,data:string,mime_type:string} */
    private function encodedVideoInput(string $path, string $mime): array
    {
        if ($path === '' || !is_file($path)) throw new InvalidArgumentException('Gemini Omni source video is unavailable.');
        if (!in_array($mime, ['video/mp4','video/quicktime','video/webm'], true)) {
            throw new InvalidArgumentException('Gemini Omni source video must be MP4, MOV or WebM.');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('Could not read Gemini Omni source video.');
        if (strlen($bytes) > 200 * 1024 * 1024) throw new InvalidArgumentException('Gemini Omni source video must be 200 MB or smaller.');
        return ['type' => 'video', 'data' => base64_encode($bytes), 'mime_type' => $mime];
    }

    /** @return array{0:int,1:string,2:array} */
    private function validatedVideoOutput(array $payload, bool $editTask = false): array
    {
        $duration = (int)($payload['durationSeconds'] ?? 0);
        if ($duration < 3 || $duration > 10) throw new InvalidArgumentException('Gemini Omni duration must be between 3 and 10 seconds.');
        $aspectRatio = (string)($payload['aspectRatio'] ?? '');
        if (!in_array($aspectRatio, ['9:16','16:9'], true)) throw new InvalidArgumentException('Gemini Omni supports 9:16 or 16:9 video in this studio.');
        return [
            $duration,
            $aspectRatio,
            $this->videoResponseFormat($aspectRatio, $duration, (string)($payload['storageUri'] ?? ''), !$editTask),
        ];
    }

    private function submitInteraction(array $request): array
    {
        $response = $this->request($this->interactionsEndpoint(), $request);
        $interactionId = trim((string)($response['id'] ?? ''));
        if ($interactionId === '') throw new RuntimeException('Gemini Omni did not return an interaction ID.');
        return ['jobId' => $interactionId, 'status' => 'processing', 'response' => $response];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'artwork_fidelity' => 'artwork — highest priority',
            'character_identity' => 'character identity — priority 2',
            'wardrobe_identity' => 'wardrobe identity — priority 3',
            'end_frame' => 'target closing composition',
            default => 'additional visual reference',
        };
    }

    private function interactionsEndpoint(): string
    {
        return sprintf(
            'https://aiplatform.googleapis.com/v1beta1/projects/%s/locations/global/interactions',
            rawurlencode($this->projectId)
        );
    }

    /** @return list<array<string,string>> */
    private function videoResponseFormat(string $aspectRatio, int $duration, string $storageUri, bool $includeGenerationControls = true): array
    {
        $format = ['type' => 'video'];
        if ($includeGenerationControls) {
            $format['aspect_ratio'] = $aspectRatio;
            $format['duration'] = $duration . 's';
        }
        $storageUri = rtrim(trim($storageUri), '/');
        if ($storageUri !== '') {
            $format['delivery'] = 'uri';
            $format['gcs_uri'] = $storageUri . '/';
        }
        return [$format];
    }

    private function request(string $url, ?array $payload): array
    {
        $token = $this->accessToken();
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Could not initialize the Gemini Omni request.');
        $options = [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json; charset=utf-8'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ];
        if ($payload === null) {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false) throw new RuntimeException('Gemini Omni network error: ' . $error);
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Gemini Omni request failed with HTTP ' . $status . '.');
            }
            throw new RuntimeException('Gemini Omni returned an invalid response.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException($this->errorMessage($decoded, 'Gemini Omni request failed with HTTP ' . $status . '.'));
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
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Metadata-Flavor: Google'],
            CURLOPT_CONNECTTIMEOUT_MS => 400,
            CURLOPT_TIMEOUT_MS => 1200,
        ]);
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
        $type = strtolower((string)($node['type'] ?? ''));
        $mime = (string)($node['mime_type'] ?? $node['mimeType'] ?? 'video/mp4');
        if ($type === 'video') {
            foreach (['uri','gcs_uri','gcsUri'] as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return ['type' => 'uri', 'value' => $node[$key], 'mimeType' => $mime];
                }
            }
            foreach (['data','bytesBase64Encoded'] as $key) {
                if (!empty($node[$key]) && is_string($node[$key])) {
                    return ['type' => 'base64', 'value' => $node[$key], 'mimeType' => $mime];
                }
            }
        }
        foreach ($node as $value) {
            $found = $this->findVideoOutput($value);
            if ($found !== null) return $found;
        }
        return null;
    }

    private function errorMessage(array $response, string $fallback = 'Gemini Omni generation failed.'): string
    {
        $candidates = [
            $response['error']['message'] ?? null,
            $response['error'] ?? null,
            $response['message'] ?? null,
            $response['status_message'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') return trim($candidate);
        }
        $nested = $this->nestedErrorMessage($response);
        if ($nested !== '') return $nested;
        return $fallback;
    }

    private function nestedErrorMessage(mixed $node): string
    {
        if (!is_array($node)) return '';
        if (isset($node['error']) && is_array($node['error'])) {
            $message = trim((string)($node['error']['message'] ?? ''));
            if ($message !== '') return $message;
        }
        foreach ($node as $value) {
            $message = $this->nestedErrorMessage($value);
            if ($message !== '') return $message;
        }
        return '';
    }
}
