<?php
declare(strict_types=1);

final class VideoMediaStorage
{
    public function prepareReferenceImage(string $storedFile): array
    {
        $file = basename(str_replace('\\', '/', $storedFile));
        if ($file === '') throw new InvalidArgumentException('The scene reference has no file.');
        $path = RESULTS_DIR . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) StorageService::downloadFile('results/' . $file, $path);
        if (!is_file($path)) throw new RuntimeException('The selected scene reference could not be loaded.');
        if (filesize($path) > 20 * 1024 * 1024) throw new InvalidArgumentException('The scene reference exceeds the 20 MB Veo limit.');

        $mime = @mime_content_type($path) ?: '';
        if (in_array($mime, ['image/jpeg','image/png'], true)) return ['path' => $path, 'mimeType' => $mime, 'temporary' => false];
        if (!in_array($mime, ['image/webp','image/gif'], true)) throw new InvalidArgumentException('The scene reference must be a JPEG, PNG, WebP or GIF image.');

        $target = $this->temporaryFile('reference_', '.png');
        $source = match ($mime) {
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'image/gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            default => false,
        };
        if (!$source || !function_exists('imagepng') || !imagepng($source, $target, 6)) {
            if (is_resource($source) || (class_exists('GdImage', false) && $source instanceof GdImage)) imagedestroy($source);
            @unlink($target);
            throw new RuntimeException('Could not convert the scene reference to PNG for Veo.');
        }
        imagedestroy($source);
        return ['path' => $target, 'mimeType' => 'image/png', 'temporary' => true];
    }

    public function storeGeneratedOutput(array $output, array $job): array
    {
        $temp = $this->temporaryFile('generation_', '.mp4');
        try {
            $type = (string)($output['type'] ?? '');
            $value = (string)($output['value'] ?? '');
            if ($type === 'base64') {
                $bytes = base64_decode($value, true);
                if ($bytes === false || file_put_contents($temp, $bytes) === false) throw new RuntimeException('Could not decode the generated video.');
            } elseif ($type === 'uri') {
                $this->downloadUri($value, $temp);
            } else {
                throw new RuntimeException('Unsupported Veo output format.');
            }
            if (!is_file($temp) || filesize($temp) < 1024) throw new RuntimeException('The generated video file is empty or incomplete.');

            $key = sprintf('video/generations/%d/%d/generation_%d.mp4', (int)$job['user_id'], (int)$job['video_project_id'], (int)$job['id']);
            if (!StorageService::uploadFile($key, $temp)) throw new RuntimeException('Could not store the generated clip.');

            $thumbKey = '';
            $thumb = $this->temporaryFile('generation_thumb_', '.jpg');
            if (VideoFfmpeg::thumbnail($temp, $thumb)) {
                $candidate = sprintf('video/generations/%d/%d/generation_%d.jpg', (int)$job['user_id'], (int)$job['video_project_id'], (int)$job['id']);
                if (StorageService::uploadFile($candidate, $thumb)) $thumbKey = $candidate;
            }
            @unlink($thumb);
            $duration = VideoFfmpeg::duration($temp);
            if ($duration <= 0) $duration = (float)$job['requested_duration_seconds'];
            return ['path' => $key, 'thumbnailPath' => $thumbKey, 'durationSeconds' => $duration, 'bytes' => filesize($temp) ?: 0];
        } finally {
            @unlink($temp);
        }
    }

    public function materializeObject(string $key, string $targetPath): void
    {
        if (!StorageService::downloadFile($key, $targetPath) || !is_file($targetPath)) {
            throw new RuntimeException('A video clip required for export is unavailable.');
        }
    }

    public function localObjectPath(string $key): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $key), DIRECTORY_SEPARATOR);
    }

    private function downloadUri(string $uri, string $target): void
    {
        if (str_starts_with($uri, 'gs://')) {
            $withoutScheme = substr($uri, 5);
            $slash = strpos($withoutScheme, '/');
            if ($slash === false) throw new RuntimeException('Invalid GCS video output URI.');
            $bucket = substr($withoutScheme, 0, $slash);
            $key = substr($withoutScheme, $slash + 1);
            if ($bucket !== app_env('GCS_BUCKET_NAME', '')) throw new RuntimeException('Veo returned output in an unexpected GCS bucket.');
            if (!StorageService::downloadFile($key, $target)) throw new RuntimeException('Could not download the generated video from GCS.');
            return;
        }
        if (!preg_match('#^https://#i', $uri)) throw new RuntimeException('Veo returned an unsupported output URI.');
        $file = fopen($target, 'wb');
        if ($file === false) throw new RuntimeException('Could not create a local video file.');
        $handle = curl_init($uri);
        if ($handle === false) { fclose($file); throw new RuntimeException('Could not initialize the video download.'); }
        curl_setopt_array($handle, [CURLOPT_FILE => $file, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300]);
        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($file);
        if (!$ok || $status < 200 || $status >= 300) throw new RuntimeException('Generated video download failed: ' . ($error ?: 'HTTP ' . $status));
    }

    private function temporaryFile(string $prefix, string $suffix): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'artworkmockups-video';
        VideoFfmpeg::ensureDirectory($directory);
        return $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(10)) . $suffix;
    }
}
