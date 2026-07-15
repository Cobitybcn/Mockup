<?php
declare(strict_types=1);

final class VideoExportBuilder
{
    public function __construct(private VideoMediaStorage $storage) {}

    public function build(array $export): array
    {
        if (!VideoFfmpeg::available()) throw new RuntimeException('FFmpeg is required to build video exports. Configure FFMPEG_BINARY_PATH.');
        $snapshot = json_decode((string)$export['timeline_snapshot_json'], true);
        if (!is_array($snapshot) || empty($snapshot['scenes']) || !is_array($snapshot['scenes'])) throw new RuntimeException('The export timeline snapshot is invalid.');
        [$width,$height] = $this->dimensions((string)$export['aspect_ratio']);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'artworkmockups-video' . DIRECTORY_SEPARATOR . 'export_' . (int)$export['id'] . '_' . bin2hex(random_bytes(6));
        VideoFfmpeg::ensureDirectory($directory);

        try {
            $normalized = [];
            $durations = [];
            foreach (array_values($snapshot['scenes']) as $index => $scene) {
                $source = $directory . DIRECTORY_SEPARATOR . sprintf('source_%03d.mp4', $index);
                $target = $directory . DIRECTORY_SEPARATOR . sprintf('normalized_%03d.mp4', $index);
                $this->storage->materializeObject((string)$scene['outputPath'], $source);
                $requested = max(0.5, (float)$scene['durationSeconds']);
                $crf = (string)(($snapshot['kind'] ?? 'final') === 'preview' ? 25 : 20);
                $filter = sprintf('scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:color=black,fps=30,format=yuv420p', $width,$height,$width,$height);
                VideoFfmpeg::run([
                    VideoFfmpeg::binary(),'-y','-i',$source,'-t',(string)$requested,'-an','-vf',$filter,
                    '-c:v','libx264','-preset','medium','-crf',$crf,'-movflags','+faststart',$target,
                ]);
                if (!is_file($target) || filesize($target) < 1024) throw new RuntimeException('FFmpeg could not normalize a scene clip.');
                $actual = VideoFfmpeg::duration($target);
                $normalized[] = $target;
                $durations[] = $actual > 0 ? $actual : $requested;
            }

            $output = $directory . DIRECTORY_SEPARATOR . 'export.mp4';
            $this->join($normalized, $durations, $snapshot['scenes'], $output, ($snapshot['kind'] ?? 'final') === 'preview');
            if (!is_file($output) || filesize($output) < 1024) throw new RuntimeException('FFmpeg did not create a valid MP4 export.');
            $key = sprintf('video/exports/%d/%d/export_%d.mp4', (int)$export['user_id'], (int)$export['video_project_id'], (int)$export['id']);
            if (!StorageService::uploadFile($key, $output)) throw new RuntimeException('Could not store the MP4 export.');
            $duration = VideoFfmpeg::duration($output);
            return ['path' => $key, 'durationSeconds' => $duration, 'bytes' => filesize($output) ?: 0];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function join(array $files, array $durations, array $scenes, string $output, bool $preview): void
    {
        $arguments = [VideoFfmpeg::binary(),'-y'];
        foreach ($files as $file) array_push($arguments, '-i', $file);
        $total = array_sum($durations);
        $allCuts = true;
        for ($i = 0; $i < count($files) - 1; $i++) {
            $transition = $scenes[$i]['transition'] ?? [];
            if (($transition['type'] ?? 'cut') !== 'cut' && (float)($transition['durationSeconds'] ?? 0) > 0) $allCuts = false;
        }

        if (count($files) === 1) {
            $filter = '[0:v]setpts=PTS-STARTPTS[v]';
        } elseif ($allCuts) {
            $inputs = '';
            foreach ($files as $index => $_) $inputs .= '[' . $index . ':v]';
            $filter = $inputs . 'concat=n=' . count($files) . ':v=1:a=0[v]';
        } else {
            $filterParts = [];
            $cumulative = $durations[0];
            $previous = '[0:v]';
            for ($i = 1; $i < count($files); $i++) {
                $transition = $scenes[$i - 1]['transition'] ?? [];
                $type = $this->xfadeType((string)($transition['type'] ?? 'cut'));
                $requested = (float)($transition['durationSeconds'] ?? 0);
                $duration = ($transition['type'] ?? 'cut') === 'cut' ? 0.04 : ($requested > 0 ? $requested : 0.5);
                $duration = max(0.04, min($duration, $durations[$i - 1] / 2, $durations[$i] / 2));
                $offset = max(0.01, $cumulative - $duration);
                $out = '[vx' . $i . ']';
                $filterParts[] = sprintf('%s[%d:v]xfade=transition=%s:duration=%.3F:offset=%.3F%s', $previous,$i,$type,$duration,$offset,$out);
                $cumulative += $durations[$i] - $duration;
                $total -= $duration;
                $previous = $out;
            }
            $filterParts[] = $previous . 'format=yuv420p[v]';
            $filter = implode(';', $filterParts);
        }

        array_push($arguments, '-f','lavfi','-t',(string)max(0.1,$total),'-i','anullsrc=channel_layout=stereo:sample_rate=48000');
        $audioIndex = count($files);
        $crf = $preview ? '25' : '20';
        array_push($arguments,
            '-filter_complex',$filter,'-map','[v]','-map',$audioIndex . ':a',
            '-c:v','libx264','-preset','medium','-crf',$crf,'-pix_fmt','yuv420p',
            '-c:a','aac','-b:a','192k','-shortest','-movflags','+faststart',$output
        );
        VideoFfmpeg::run($arguments);
    }

    private function dimensions(string $aspect): array
    {
        return match ($aspect) {
            '16:9' => [1920,1080],
            '1:1' => [1080,1080],
            '4:5' => [1080,1350],
            default => [1080,1920],
        };
    }

    private function xfadeType(string $type): string
    {
        return match ($type) {
            'dip_black' => 'fadeblack',
            'dip_white' => 'fadewhite',
            'fade','cross_dissolve','ai_transition' => 'fade',
            default => 'fade',
        };
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            if (is_file($path)) @unlink($path);
        }
        @rmdir($directory);
    }
}
