<?php
declare(strict_types=1);

/** Creates a curatorial social video from existing mockups only; it never invokes AI. */
class MockupSequenceVideoService
{
    public function generate(array $files, array $options = []): array
    {
        $files = array_values($files);
        if (count($files) < 2) { throw new InvalidArgumentException('Se necesitan al menos dos mockups.'); }
        if (count($files) > 12) { throw new InvalidArgumentException('El límite para un video social es de 12 mockups.'); }
        $aspect = (string)($options['aspectRatio'] ?? '9:16');
        [$width, $height] = $this->dimensions($aspect, $options);
        $fps = max(24, min(60, (int)($options['fps'] ?? 30)));
        $imageDuration = max(3.2, min(4.0, (float)($options['imageDuration'] ?? 3.8)));
        $transitionDuration = max(0.8, min(1.2, (float)($options['transitionDuration'] ?? 1.0)));
        $frames = (int)round($imageDuration * $fps);
        $transFrames = (int)round($transitionDuration * $fps);
        $paths = [];
        foreach ($files as $file) {
            $path = RESULTS_DIR . DIRECTORY_SEPARATOR . basename((string)$file);
            if (!is_file($path)) { throw new RuntimeException('No se encontró uno de los mockups seleccionados.'); }
            $paths[] = $path;
        }
        $directory = RESULTS_DIR . DIRECTORY_SEPARATOR . 'social-video';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new RuntimeException('No se pudo crear el directorio de video.'); }
        $artworkId = (int)($options['artworkId'] ?? 0);
        $name = 'social-video-' . $artworkId . '-' . time() . '.mp4';
        $output = $directory . DIRECTORY_SEPARATOR . $name;
        $ffmpeg = trim(ProviderSettings::ffmpegBinaryPath());
        if ($ffmpeg === '' || !is_file($ffmpeg)) { $ffmpeg = 'ffmpeg'; }

        $command = escapeshellarg($ffmpeg) . ' -y';
        foreach ($paths as $path) { $command .= ' -loop 1 -t ' . $imageDuration . ' -i ' . escapeshellarg($path); }
        $movements = ['slow_zoom_in_center','slow_zoom_out_center','gallery_pan_left','gallery_pan_right','vertical_breath','collector_gaze','architectural_slide'];
        $transitions = ['fade','smoothleft','smoothright','smoothup','smoothdown','wipeleft','wiperight','circleopen'];
        $filters = [];
        $usedMovements = [];
        foreach ($paths as $i => $_) {
            $movement = $movements[$i % count($movements)];
            $usedMovements[] = $movement;
            $foregroundWidth = (int)round($width * 0.90);
            $foregroundHeight = (int)round($height * 0.90);

            $isFirst = ($i === 0);
            $isLast = ($i === count($paths) - 1);
            $startFrame = $isFirst ? 0 : $transFrames;
            $endFrame = $isLast ? $frames : ($frames - $transFrames);
            $animFrames = $endFrame - $startFrame;

            $zoom = $this->zoompanParameters($movement, $startFrame, $endFrame, $animFrames);
            $filters[] = "[{$i}:v]trim=start_frame=0:end_frame=1,split=2[src{$i}][back{$i}];[back{$i}]scale={$width}:{$height}:force_original_aspect_ratio=increase,crop={$width}:{$height},boxblur=20:10,eq=brightness=-0.10[bg{$i}];[src{$i}]scale={$foregroundWidth}:{$foregroundHeight}:force_original_aspect_ratio=decrease[fg{$i}];[bg{$i}][fg{$i}]overlay=(W-w)/2:(H-h)/2,zoompan={$zoom}:d={$frames}:s={$width}x{$height}:fps={$fps},setsar=1,format=yuv420p,setpts=PTS-STARTPTS[v{$i}]";
        }
        $usedTransitions = [];
        $previous = 'v0';
        for ($i = 1; $i < count($paths); $i++) {
            $next = $i === count($paths) - 1 ? 'outv' : 'x' . $i;
            $transition = $transitions[($i - 1) % count($transitions)];
            $usedTransitions[] = $transition;
            $offset = number_format(($imageDuration - $transitionDuration) * $i, 3, '.', '');
            $filters[] = "[{$previous}][v{$i}]xfade=transition={$transition}:duration={$transitionDuration}:offset={$offset}[{$next}]";
            $previous = $next;
        }
        $command .= ' -filter_complex ' . escapeshellarg(implode(';', $filters)) . ' -map [outv] -c:v libx264 -preset medium -crf 18 -pix_fmt yuv420p -movflags +faststart ' . escapeshellarg($output);
        exec($command . ' 2>&1', $log, $code);
        if ($code !== 0 || !is_file($output) || filesize($output) === 0) { throw new RuntimeException('FFmpeg no pudo crear el video social: ' . implode("\n", $log)); }
        return ['file'=>'social-video/'.$name,'bytes'=>filesize($output),'mockupCount'=>count($paths),'transitionCount'=>count($usedTransitions),'durationSeconds'=>round(count($paths)*$imageDuration-(count($paths)-1)*$transitionDuration,2),'aspectRatio'=>$aspect,'width'=>$width,'height'=>$height,'fps'=>$fps,'movementsUsed'=>$usedMovements,'transitionsUsed'=>$usedTransitions];
    }

    private function dimensions(string $aspect, array $options): array
    {
        if ($aspect === '1:1') return [(int)($options['width'] ?? 1080), (int)($options['height'] ?? 1080)];
        if ($aspect === '16:9') return [(int)($options['width'] ?? 1920), (int)($options['height'] ?? 1080)];
        return [(int)($options['width'] ?? 1080), (int)($options['height'] ?? 1920)];
    }

    private function zoompanParameters(string $movement, int $startFrame, int $endFrame, int $animFrames): string
    {
        $p = "if(lt(on,{$startFrame}),0,if(gt(on,{$endFrame}),1,(on-{$startFrame})/{$animFrames}))";

        $zoomExpr = match ($movement) {
            'slow_zoom_out_center' => "1.05-0.05*{$p}",
            'slow_zoom_in_center' => "1.0+0.05*{$p}",
            'collector_gaze' => "1.0+0.02*{$p}",
            default => "1.0+0.05*{$p}"
        };

        $panXExpr = match ($movement) {
            'gallery_pan_left' => "(iw-iw/zoom)*(1-{$p})",
            'gallery_pan_right', 'architectural_slide' => "(iw-iw/zoom)*{$p}",
            default => "iw/2-iw/zoom/2"
        };

        $panYExpr = match ($movement) {
            'vertical_breath' => "(ih-ih/zoom)*(0.25+0.5*{$p})",
            default => "ih/2-ih/zoom/2"
        };

        return "z='{$zoomExpr}':x='{$panXExpr}':y='{$panYExpr}'";
    }
}
