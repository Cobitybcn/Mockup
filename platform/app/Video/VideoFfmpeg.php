<?php
declare(strict_types=1);

final class VideoFfmpeg
{
    public static function binary(): string
    {
        $configured = trim(app_env('FFMPEG_BINARY_PATH', ProviderSettings::ffmpegBinaryPath()));
        return $configured !== '' ? $configured : 'ffmpeg';
    }

    public static function ffprobeBinary(): string
    {
        $ffmpeg = self::binary();
        if (is_file($ffmpeg)) {
            $candidate = dirname($ffmpeg) . DIRECTORY_SEPARATOR . (DIRECTORY_SEPARATOR === '\\' ? 'ffprobe.exe' : 'ffprobe');
            if (is_file($candidate)) return $candidate;
        }
        return 'ffprobe';
    }

    public static function available(): bool
    {
        try {
            self::run([self::binary(), '-version'], false);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function thumbnail(string $videoPath, string $targetPath): bool
    {
        try {
            self::ensureDirectory(dirname($targetPath));
            self::run([self::binary(), '-y', '-ss', '0.5', '-i', $videoPath, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '3', $targetPath]);
            return is_file($targetPath) && filesize($targetPath) > 0;
        } catch (Throwable $e) {
            Logger::log('Video thumbnail failed: ' . $e->getMessage(), 'warning');
            return false;
        }
    }

    public static function lastFrame(string $videoPath, string $targetPath): bool
    {
        try {
            self::ensureDirectory(dirname($targetPath));
            self::run([self::binary(), '-y', '-sseof', '-0.12', '-i', $videoPath, '-frames:v', '1', '-vf', 'scale=1280:-2', '-q:v', '2', $targetPath]);
            return is_file($targetPath) && filesize($targetPath) > 0;
        } catch (Throwable $e) {
            Logger::log('Video last-frame extraction failed: ' . $e->getMessage(), 'warning');
            return false;
        }
    }

    public static function duration(string $videoPath): float
    {
        try {
            $output = self::run([self::ffprobeBinary(), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $videoPath], false);
            $duration = (float)trim($output);
            return $duration > 0 ? round($duration, 3) : 0.0;
        } catch (Throwable) {
            return 0.0;
        }
    }

    /** @param list<string> $arguments */
    public static function run(array $arguments, bool $requireOutput = true): string
    {
        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $lines = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $lines, $exitCode);
        $output = implode("\n", $lines);
        if ($exitCode !== 0) throw new RuntimeException('FFmpeg command failed: ' . mb_substr($output, -1800));
        return $output;
    }

    public static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the video working directory.');
        }
    }
}
