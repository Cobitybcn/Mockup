<?php
declare(strict_types=1);

/**
 * One music track per video project: the upload, the point of the track where
 * it comes in, and how long it fades out before the montage ends.
 *
 * The file is stored in video_reference_assets so it reuses the existing
 * storage and serving path, but it is never attached to a scene: music belongs
 * to the project as a whole, not to any one sequence.
 */
final class VideoMusicService
{
    private const MAX_BYTES = 40 * 1024 * 1024;
    private const MAX_SECONDS = 1800.0;
    public const MAX_FADE_SECONDS = 4.0;
    public const MAX_VOLUME = 4.0;
    private const PEAK_COUNT = 1800;

    private const MIME_TYPES = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/flac' => 'flac',
        'audio/x-flac' => 'flac',
    ];

    public function __construct(private VideoStudioRepository $repository) {}

    /** @param array<string,mixed> $file */
    public function upload(int $userId, int $projectId, int $version, array $file): array
    {
        $project = $this->requireProject($userId, $projectId);
        $asset = $this->inspect($file);

        $asset['filePath'] = sprintf(
            'storage/video/music/%d/%s.%s',
            $userId,
            bin2hex(random_bytes(18)),
            $asset['extension']
        );
        if (!StorageService::uploadFile($asset['filePath'], $asset['temporaryPath'])) {
            throw new RuntimeException('Could not store “' . $asset['originalName'] . '”.');
        }

        $peaks = $this->renderPeaks($asset['temporaryPath']);

        try {
            $assetId = $this->repository->createReferenceAsset($userId, $asset);
        } catch (Throwable $e) {
            StorageService::delete($asset['filePath']);
            throw $e;
        }

        // A new track invalidates a position chosen against the old one.
        $this->write($userId, $projectId, $version, [
            'music_asset_id' => $assetId,
            'music_offset_seconds' => 0,
            'music_fade_in_seconds' => (float)($project['music']['fadeInSeconds'] ?? 0),
            'music_fade_out_seconds' => (float)($project['music']['fadeOutSeconds'] ?? 0.5),
            'music_duration_seconds' => (float)$asset['durationSeconds'],
            'music_peaks_json' => $peaks === [] ? null : json_encode($peaks),
        ]);
        return $this->payload($userId, $projectId);
    }

    /** @param array<string,mixed> $input */
    public function update(int $userId, int $projectId, int $version, array $input): array
    {
        $project = $this->requireProject($userId, $projectId);
        $changes = [];

        if (!empty($input['clear'])) {
            $changes = [
                'music_asset_id' => null,
                'music_offset_seconds' => 0,
                'music_fade_in_seconds' => 0,
                'music_fade_out_seconds' => 0.5,
                'music_duration_seconds' => 0,
                'music_peaks_json' => null,
            ];
        } else {
            if (($project['music']['assetId'] ?? 0) <= 0) {
                throw new DomainException('Upload a music track before adjusting it.');
            }
            if (array_key_exists('offsetSeconds', $input)) {
                // Dragging left runs into the track; dragging right delays it.
                // Either way at least a second of music has to land on the video.
                $track = (float)($project['music']['durationSeconds'] ?? 0);
                $video = $this->videoSeconds($project['id']);
                $changes['music_offset_seconds'] = $this->clamp(
                    (float)$input['offsetSeconds'],
                    -max(0.0, $track - 1.0),
                    max(0.0, $video - 1.0)
                );
            }
            if (array_key_exists('fadeInSeconds', $input)) {
                $changes['music_fade_in_seconds'] = $this->clamp((float)$input['fadeInSeconds'], 0.0, self::MAX_FADE_SECONDS);
            }
            if (array_key_exists('fadeOutSeconds', $input)) {
                $changes['music_fade_out_seconds'] = $this->clamp((float)$input['fadeOutSeconds'], 0.0, self::MAX_FADE_SECONDS);
            }
            if (array_key_exists('volume', $input)) {
                // Tracks arrive mastered at wildly different levels, so the montage
                // needs its own gain rather than inheriting whatever the file had.
                $changes['master_volume'] = $this->clamp((float)$input['volume'], 0.0, self::MAX_VOLUME);
            }
        }

        if ($changes !== []) $this->write($userId, $projectId, $version, $changes);
        return $this->payload($userId, $projectId);
    }

    /**
     * Peak amplitudes across the track, so the timeline can draw the waveform on
     * a canvas at any zoom without downloading the audio. A failure here is not
     * worth losing the upload over: the clip simply renders without its wave.
     *
     * @return list<float>
     */
    private function renderPeaks(string $sourcePath): array
    {
        $local = tempnam(sys_get_temp_dir(), 'vdspcm');
        if ($local === false) return [];
        try {
            // Mono 8 kHz 16-bit is far more resolution than a few thousand peaks
            // need, and keeps the decode cheap even for a half-hour track.
            VideoFfmpeg::run([
                VideoFfmpeg::binary(), '-y', '-i', $sourcePath,
                '-ac', '1', '-ar', '8000', '-f', 's16le', '-acodec', 'pcm_s16le', $local,
            ], false);
            $bytes = is_file($local) ? (int)filesize($local) : 0;
            if ($bytes < 4) return [];

            $samples = intdiv($bytes, 2);
            $buckets = self::PEAK_COUNT;
            $per = max(1, intdiv($samples, $buckets));
            $handle = fopen($local, 'rb');
            if ($handle === false) return [];

            $peaks = [];
            try {
                for ($i = 0; $i < $buckets; $i++) {
                    $chunk = fread($handle, $per * 2);
                    if ($chunk === false || $chunk === '') break;
                    $values = unpack('v*', $chunk) ?: [];
                    $loudest = 0;
                    foreach ($values as $raw) {
                        $signed = $raw >= 32768 ? $raw - 65536 : $raw;
                        $magnitude = $signed < 0 ? -$signed : $signed;
                        if ($magnitude > $loudest) $loudest = $magnitude;
                    }
                    $peaks[] = round($loudest / 32768, 4);
                }
            } finally {
                fclose($handle);
            }
            return $peaks;
        } catch (Throwable) {
            return [];
        } finally {
            if (is_file($local)) @unlink($local);
        }
    }

    /** @return array<string,mixed> */
    private function requireProject(int $userId, int $projectId): array
    {
        $project = $this->repository->findProject($userId, $projectId);
        if (!$project) throw new OutOfBoundsException('Video project not found.');
        return $project;
    }

    private function videoSeconds(int $projectId): float
    {
        $total = 0.0;
        foreach ($this->repository->scenes($projectId) as $scene) {
            $total += (float)($scene['active_generation']['durationSeconds'] ?? $scene['durationSeconds'] ?? 0);
        }
        return $total;
    }

    /** @param array<string,mixed> $changes */
    private function write(int $userId, int $projectId, int $version, array $changes): void
    {
        if (!$this->repository->updateProject($userId, $projectId, $version, $changes)) {
            throw new DomainException('This project changed in another session. Reload before saving.');
        }
    }

    private function payload(int $userId, int $projectId): array
    {
        return (new VideoStudioService($this->repository))->studioPayload($userId, $projectId);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (!is_finite($value)) return $min;
        return round(max($min, min($max, $value)), 3);
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function inspect(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The music file could not be received.');
        $path = (string)($file['tmp_name'] ?? '');
        if ($path === '' || !is_file($path) || (PHP_SAPI !== 'cli' && !is_uploaded_file($path))) {
            throw new InvalidArgumentException('The file received is not a valid upload.');
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes <= 0) throw new InvalidArgumentException('The file is empty.');
        if ($bytes > self::MAX_BYTES) throw new InvalidArgumentException('Music files can be up to 40 MB.');

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($path));
        $extension = self::MIME_TYPES[$mime] ?? null;
        if ($extension === null) throw new InvalidArgumentException('Unsupported format. Use MP3, M4A, AAC, WAV, OGG or FLAC.');

        $duration = VideoFfmpeg::duration($path);
        if ($duration <= 0) throw new InvalidArgumentException('The track duration could not be read.');
        if ($duration > self::MAX_SECONDS) throw new InvalidArgumentException('Music tracks can last up to 30 minutes.');

        $name = trim(preg_replace('/[^\pL\pN\s._-]+/u', '', (string)($file['name'] ?? 'music')) ?? '');

        return [
            'temporaryPath' => $path,
            'originalName' => $name !== '' ? mb_substr($name, 0, 255) : 'music',
            'mimeType' => $mime,
            'mediaType' => 'audio',
            'extension' => $extension,
            'byteSize' => (int)$bytes,
            'width' => null,
            'height' => null,
            'durationSeconds' => $duration,
        ];
    }
}
