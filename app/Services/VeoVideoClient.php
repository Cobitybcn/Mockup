<?php
declare(strict_types=1);

class VeoVideoClient
{
    public function generateSingleSegment(array $segment, array $specs, string $referenceImagePath): array
    {
        $duration = $this->duration((int)($segment['duration_seconds'] ?? 6));
        $prompt = trim((string)($segment['segment_prompt'] ?? ''));
        if ($prompt === '') { throw new RuntimeException('Video segment prompt is empty.'); }
        if (!is_file($referenceImagePath)) { throw new RuntimeException('The root artwork reference image is not available for Veo.'); }
        $python = (new GeminiImageClient())->getPythonExecutable();
        $bridge = __DIR__ . '/veo_bridge.py';
        $outputDir = RESULTS_DIR . DIRECTORY_SEPARATOR . 'social-video';
        if (!is_dir($outputDir)) { mkdir($outputDir, 0775, true); }
        $token = 'social_video_' . time() . '_' . bin2hex(random_bytes(4));
        $promptPath = $outputDir . DIRECTORY_SEPARATOR . $token . '.txt';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $token . '.mp4';
        file_put_contents($promptPath, $prompt);
        $model = ProviderSettings::socialVideoVeoModel();
        $command = '"' . $python . '" ' . escapeshellarg($bridge)
            . ' --project ' . escapeshellarg((string)VERTEX_PROJECT_ID)
            . ' --region ' . escapeshellarg(ProviderSettings::socialVideoVeoRegion())
            . ' --model ' . escapeshellarg($model)
            . ' --storage-uri ' . escapeshellarg(ProviderSettings::socialVideoVeoStorageUri())
            . ' --prompt-file ' . escapeshellarg($promptPath)
            . ' --duration ' . $duration
            . ' --aspect-ratio ' . escapeshellarg((string)($specs['aspect_ratio'] ?? '9:16'))
            . ' --resolution ' . escapeshellarg(ProviderSettings::socialVideoVeoResolution())
            . ' --output ' . escapeshellarg($outputPath);
        $command .= ' --image ' . escapeshellarg($referenceImagePath);
        $output = []; $code = 0;
        exec($command . ' 2>&1', $output, $code);
        @unlink($promptPath);
        $events = [];
        foreach ($output as $line) { $item = json_decode($line, true); if (is_array($item)) { $events[] = $item; } }
        $last = $events === [] ? [] : $events[count($events) - 1];
        if ($code !== 0 || ($last['event'] ?? '') !== 'ready' || !is_file($outputPath)) { throw new RuntimeException((string)($last['error'] ?? implode("\n", $output) ?: 'Veo generation failed.')); }
        return ['file' => 'social-video/' . basename($outputPath), 'bytes' => filesize($outputPath), 'operation_name' => (string)($last['operation_name'] ?? ''), 'video_uri' => (string)($last['video_uri'] ?? ''), 'reference_image' => basename($referenceImagePath)];
    }

    private function duration(int $value): int
    {
        $supported = [4, 6, 8]; $nearest = 6; $distance = PHP_INT_MAX;
        foreach ($supported as $candidate) { if (abs($candidate - $value) < $distance) { $nearest = $candidate; $distance = abs($candidate - $value); } }
        return $nearest;
    }

    public function extractFinalFrame(string $videoFile): string
    {
        if (!is_file($videoFile)) { throw new RuntimeException('Video segment was not found for continuity extraction.'); }
        $ffmpeg = $this->ffmpegBinary();
        $frame = preg_replace('/\.mp4$/i', '-final-frame.png', $videoFile) ?: ($videoFile . '.png');
        $command = escapeshellarg($ffmpeg) . ' -y -sseof -0.5 -i ' . escapeshellarg($videoFile) . ' -frames:v 1 ' . escapeshellarg($frame);
        exec($command . ' 2>&1', $output, $code);
        if ($code !== 0 || !is_file($frame) || filesize($frame) === 0) { throw new RuntimeException('FFmpeg could not extract the continuity frame: ' . implode("\n", $output)); }
        return $frame;
    }

    public function generateSequence(array $segments, array $specs, string $rootArtworkPath, ?callable $progress = null): array
    {
        if ($segments === [] || count($segments) > 5) { throw new RuntimeException('A Social Video sequence must contain between 1 and 5 segments.'); }
        $reference = $rootArtworkPath;
        $results = [];
        foreach ($segments as $index => $segment) {
            if ($progress) { $progress($index + 1, count($segments), 'generating'); }
            $result = $this->generateSingleSegment((array)$segment, $specs, $reference);
            $result['segment_number'] = $index + 1;
            $absolute = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $result['file']);
            $results[] = $result;
            if ($index < count($segments) - 1) {
                if ($progress) { $progress($index + 1, count($segments), 'extracting continuity frame'); }
                $reference = $this->extractFinalFrame($absolute);
            }
        }
        $finalFile = count($results) === 1 ? $results[0]['file'] : $this->concatenate($results);
        return ['file' => $finalFile, 'segments' => $results];
    }

    private function concatenate(array $segments): string
    {
        $ffmpeg = $this->ffmpegBinary();
        $directory = RESULTS_DIR . DIRECTORY_SEPARATOR . 'social-video';
        $token = 'social_video_final_' . time() . '_' . bin2hex(random_bytes(4));
        $list = $directory . DIRECTORY_SEPARATOR . $token . '.txt';
        $output = $directory . DIRECTORY_SEPARATOR . $token . '.mp4';
        $lines = [];
        foreach ($segments as $segment) {
            $path = RESULTS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$segment['file']);
            $lines[] = "file '" . str_replace("'", "'\\''", $path) . "'";
        }
        file_put_contents($list, implode(PHP_EOL, $lines));
        $command = escapeshellarg($ffmpeg) . ' -y -f concat -safe 0 -i ' . escapeshellarg($list) . ' -c copy ' . escapeshellarg($output);
        exec($command . ' 2>&1', $log, $code);
        @unlink($list);
        if ($code !== 0 || !is_file($output) || filesize($output) === 0) { throw new RuntimeException('FFmpeg could not concatenate generated segments: ' . implode("\n", $log)); }
        return 'social-video/' . basename($output);
    }

    private function ffmpegBinary(): string
    {
        $configured = trim(ProviderSettings::ffmpegBinaryPath());
        if ($configured !== '' && is_file($configured)) { return $configured; }
        exec('ffmpeg -version 2>&1', $output, $code);
        if ($code === 0) { return 'ffmpeg'; }
        throw new RuntimeException('FFmpeg is unavailable. Add its executable path in API Settings.');
    }
}
