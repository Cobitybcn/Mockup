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

                // A block can be a fragment of its clip, and several blocks can be
                // fragments of the same one. Seeking before the input keeps that
                // cheap even when the piece sits late in the source.
                $arguments = [VideoFfmpeg::binary(),'-y'];
                $from = max(0.0, (float)($scene['startSeconds'] ?? 0));
                if ($from > 0.001) array_push($arguments, '-ss', sprintf('%.3F', $from));
                array_push($arguments,
                    '-i',$source,'-t',(string)$requested,'-an','-vf',$filter,
                    '-c:v','libx264','-preset','medium','-crf',$crf,'-movflags','+faststart',$target
                );
                VideoFfmpeg::run($arguments);
                if (!is_file($target) || filesize($target) < 1024) throw new RuntimeException('FFmpeg could not normalize a scene clip.');
                $actual = VideoFfmpeg::duration($target);
                $normalized[] = $target;
                $durations[] = $actual > 0 ? $actual : $requested;
            }

            $music = null;
            if (is_array($snapshot['music'] ?? null) && (string)($snapshot['music']['filePath'] ?? '') !== '') {
                $music = $snapshot['music'];
                $music['localPath'] = $directory . DIRECTORY_SEPARATOR . 'music.audio';
                $this->storage->materializeObject((string)$music['filePath'], $music['localPath']);
            }

            $output = $directory . DIRECTORY_SEPARATOR . 'export.mp4';
            if (is_array($snapshot['multitrack'] ?? null)) {
                $audioFiles = [];
                foreach (array_values((array)($snapshot['multitrack']['audioBlocks'] ?? [])) as $index => $block) {
                    $path = $directory . DIRECTORY_SEPARATOR . sprintf('audio_source_%03d.media', $index);
                    $this->storage->materializeObject((string)$block['outputPath'], $path);
                    $audioFiles[] = $path;
                }
                $this->joinMultitrack(
                    $normalized, $durations, (array)$snapshot['scenes'],
                    $audioFiles, (array)($snapshot['multitrack']['audioBlocks'] ?? []),
                    $output, ($snapshot['kind'] ?? 'final') === 'preview', $music, $width, $height
                );
            } else {
                $this->join($normalized, $durations, $snapshot['scenes'], $output, ($snapshot['kind'] ?? 'final') === 'preview', $music);
            }
            if (!is_file($output) || filesize($output) < 1024) throw new RuntimeException('FFmpeg did not create a valid MP4 export.');
            $duration = VideoFfmpeg::duration($output);
            $expected = 0.0;
            foreach ((array)($snapshot['multitrack']['videoBlocks'] ?? []) as $block) {
                $expected = max($expected, (float)($block['timelineStart'] ?? 0) + (float)($block['durationSeconds'] ?? 0));
            }
            if ($expected > 0 && abs($duration - $expected) > 0.35) {
                throw new RuntimeException(sprintf('The exported duration (%.2fs) does not match the timeline (%.2fs).', $duration, $expected));
            }
            $key = sprintf('video/exports/%d/%d/export_%d.mp4', (int)$export['user_id'], (int)$export['video_project_id'], (int)$export['id']);
            if (!StorageService::uploadFile($key, $output)) throw new RuntimeException('Could not store the MP4 export.');

            // Without a poster the montage shows up as a black card everywhere it
            // is listed, while an uploaded video shows its first frame.
            $thumbnailKey = '';
            $poster = $directory . DIRECTORY_SEPARATOR . 'poster.jpg';
            if (VideoFfmpeg::thumbnail($output, $poster)) {
                $candidate = sprintf('video/exports/%d/%d/export_%d.jpg', (int)$export['user_id'], (int)$export['video_project_id'], (int)$export['id']);
                if (StorageService::uploadFile($candidate, $poster)) $thumbnailKey = $candidate;
            }

            return ['path' => $key, 'durationSeconds' => $duration, 'bytes' => filesize($output) ?: 0, 'thumbnailPath' => $thumbnailKey];
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function join(array $files, array $durations, array $scenes, string $output, bool $preview, ?array $music = null): void
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

        $total = max(0.1, $total);
        $audioIndex = count($files);
        if ($music !== null) {
            // The offset is where the track's own start sits on the video: negative
            // means seek into it, positive means hold silence first. Then pad and
            // trim so the music always ends exactly with the picture, and fade out
            // just before that instead of cutting.
            $offset = (float)($music['offsetSeconds'] ?? 0);
            if ($offset < 0) array_push($arguments, '-ss', sprintf('%.3F', -$offset));
            array_push($arguments, '-i', (string)$music['localPath']);

            $fadeOut = max(0.0, min((float)($music['fadeOutSeconds'] ?? 0), $total));
            $audioFilter = sprintf('[%d:a]', $audioIndex);
            if ($offset > 0) {
                $delay = (int)round(min($offset, $total) * 1000);
                $audioFilter .= sprintf('adelay=%d:all=1,', $delay);
            }
            $audioFilter .= sprintf('apad,atrim=0:%.3F,asetpts=PTS-STARTPTS', $total);
            $volume = (float)($music['volume'] ?? 1);
            if ($volume > 0 && abs($volume - 1.0) > 0.001) $audioFilter .= sprintf(',volume=%.3F', $volume);

            // The fade in starts where the music actually enters the montage,
            // which is the offset when it is delayed and the first frame otherwise.
            $entry = max(0.0, min($offset, $total));
            $fadeIn = max(0.0, min((float)($music['fadeInSeconds'] ?? 0), max(0.0, $total - $entry)));
            if ($fadeIn > 0.01) $audioFilter .= sprintf(',afade=t=in:st=%.3F:d=%.3F', $entry, $fadeIn);
            if ($fadeOut > 0.01) $audioFilter .= sprintf(',afade=t=out:st=%.3F:d=%.3F', max(0.0, $total - $fadeOut), $fadeOut);
            $filter .= ';' . $audioFilter . '[a]';
            $audioMap = '[a]';
        } else {
            array_push($arguments, '-f','lavfi','-t',(string)$total,'-i','anullsrc=channel_layout=stereo:sample_rate=48000');
            $audioMap = $audioIndex . ':a';
        }

        $crf = $preview ? '25' : '20';
        array_push($arguments,
            '-filter_complex',$filter,'-map','[v]','-map',$audioMap,
            '-c:v','libx264','-preset','medium','-crf',$crf,'-pix_fmt','yuv420p',
            '-c:a','aac','-b:a','192k','-shortest','-movflags','+faststart',$output
        );
        VideoFfmpeg::run($arguments);
    }

    private function joinMultitrack(
        array $videoFiles,
        array $durations,
        array $videoBlocks,
        array $audioFiles,
        array $audioBlocks,
        string $output,
        bool $preview,
        ?array $music,
        int $width,
        int $height
    ): void {
        $total = 0.0;
        foreach ($videoBlocks as $index => $block) {
            $total = max($total, (float)($block['timelineStart'] ?? 0) + (float)($durations[$index] ?? $block['durationSeconds'] ?? 0));
        }
        $total = max(0.2, $total);

        $arguments = [VideoFfmpeg::binary(), '-y'];
        foreach ($videoFiles as $file) array_push($arguments, '-i', $file);
        foreach ($audioFiles as $index => $file) {
            $block = $audioBlocks[$index] ?? [];
            array_push($arguments, '-ss', sprintf('%.3F', max(0.0, (float)($block['sourceStart'] ?? 0))));
            array_push($arguments, '-t', sprintf('%.3F', max(0.2, (float)($block['durationSeconds'] ?? 0.2))), '-i', $file);
        }
        $musicIndex = count($videoFiles) + count($audioFiles);
        if ($music !== null) {
            $offset = (float)($music['offsetSeconds'] ?? 0);
            if ($offset < 0) array_push($arguments, '-ss', sprintf('%.3F', -$offset));
            array_push($arguments, '-i', (string)$music['localPath']);
        }

        $filter = [];
        $filter[] = sprintf('color=c=black:s=%dx%d:r=30:d=%.3F[base]', $width, $height, $total);
        $order = array_keys($videoBlocks);
        usort($order, static function (int $a, int $b) use ($videoBlocks): int {
            $track = ((int)($videoBlocks[$a]['track'] ?? 1)) <=> ((int)($videoBlocks[$b]['track'] ?? 1));
            return $track !== 0 ? $track : $a <=> $b;
        });
        $previous = '[base]';
        foreach ($order as $step => $inputIndex) {
            $block = $videoBlocks[$inputIndex];
            $start = max(0.0, (float)($block['timelineStart'] ?? 0));
            $end = $start + (float)($durations[$inputIndex] ?? $block['durationSeconds'] ?? 0);
            $filter[] = sprintf('[%d:v]setpts=PTS-STARTPTS+%.3F/TB[mv%d]', $inputIndex, $start, $step);
            $out = '[vo' . $step . ']';
            $filter[] = sprintf("%s[mv%d]overlay=eof_action=pass:shortest=0:enable='between(t,%.3F,%.3F)'%s", $previous, $step, $start, $end, $out);
            $previous = $out;
        }
        $filter[] = $previous . 'format=yuv420p[v]';

        $audioLabels = [];
        foreach ($audioFiles as $index => $_) {
            $block = $audioBlocks[$index] ?? [];
            $input = count($videoFiles) + $index;
            $delay = (int)round(max(0.0, (float)($block['timelineStart'] ?? 0)) * 1000);
            $volume = max(0.0, min(4.0, (float)($block['volume'] ?? 1)));
            $label = '[ma' . $index . ']';
            $filter[] = sprintf('[%d:a]asetpts=PTS-STARTPTS,adelay=%d:all=1,apad,atrim=0:%.3F,volume=%.3F%s', $input, $delay, $total, $volume, $label);
            $audioLabels[] = $label;
        }
        if ($music !== null) {
            $offset = (float)($music['offsetSeconds'] ?? 0);
            $delay = (int)round(max(0.0, min($offset, $total)) * 1000);
            $volume = max(0.0, min(4.0, (float)($music['volume'] ?? 1)));
            $chain = sprintf('[%d:a]asetpts=PTS-STARTPTS', $musicIndex);
            if ($delay > 0) $chain .= sprintf(',adelay=%d:all=1', $delay);
            $chain .= sprintf(',apad,atrim=0:%.3F,volume=%.3F', $total, $volume);
            $fadeIn = max(0.0, min((float)($music['fadeInSeconds'] ?? 0), $total));
            $fadeOut = max(0.0, min((float)($music['fadeOutSeconds'] ?? 0), $total));
            if ($fadeIn > 0.01) $chain .= sprintf(',afade=t=in:st=%.3F:d=%.3F', max(0.0, $offset), $fadeIn);
            if ($fadeOut > 0.01) $chain .= sprintf(',afade=t=out:st=%.3F:d=%.3F', max(0.0, $total - $fadeOut), $fadeOut);
            $filter[] = $chain . '[music]';
            $audioLabels[] = '[music]';
        }
        if ($audioLabels === []) {
            array_push($arguments, '-f', 'lavfi', '-t', sprintf('%.3F', $total), '-i', 'anullsrc=channel_layout=stereo:sample_rate=48000');
            $audioMap = (count($videoFiles) + count($audioFiles) + ($music !== null ? 1 : 0)) . ':a';
        } elseif (count($audioLabels) === 1) {
            $filter[] = $audioLabels[0] . 'anull[a]';
            $audioMap = '[a]';
        } else {
            $filter[] = implode('', $audioLabels) . 'amix=inputs=' . count($audioLabels) . ':duration=longest:normalize=0,atrim=0:' . sprintf('%.3F', $total) . '[a]';
            $audioMap = '[a]';
        }

        $crf = $preview ? '25' : '20';
        array_push($arguments, '-filter_complex', implode(';', $filter), '-map', '[v]', '-map', $audioMap,
            '-t', sprintf('%.3F', $total), '-c:v', 'libx264', '-preset', 'medium', '-crf', $crf,
            '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-movflags', '+faststart', $output);
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
