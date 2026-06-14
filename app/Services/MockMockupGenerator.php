<?php
declare(strict_types=1);

class MockMockupGenerator implements MockupGeneratorInterface
{
    public function generate(string $imagePath, string $contextId, string $prompt, array $metadata = []): array
    {
        if (!is_file($imagePath)) {
            throw new RuntimeException('No se encontro la imagen raiz para el mockup.');
        }

        $resultsDir = RESULTS_DIR;
        $promptsDir = PROMPTS_DIR;

        if (!is_dir($resultsDir)) {
            mkdir($resultsDir, 0775, true);
        }
        if (!is_dir($promptsDir)) {
            mkdir($promptsDir, 0775, true);
        }

        $t0 = microtime(true);
        Logger::log("Iniciando generacion de mockup MOCK. Contexto: {$contextId}, Obra: " . basename($imagePath), 'mock');

        $seoParams = $metadata['seo_params'] ?? null;
        $ext = 'png';
        if ($seoParams) {
            // Respect the extension request (usually jpg or png)
            $ext = trim((string)($seoParams['extension'] ?? 'png'), '.');
            if ($ext === 'svg') {
                $ext = 'png';
                $seoParams['extension'] = 'png';
            }
            $outputName = Display::generateSeoImageFilename($seoParams, $resultsDir);
        } else {
            $stamp = time() . '_' . random_int(1000, 9999);
            $outputName = 'mockup_mock_' . $stamp . '.png';
        }
        
        $promptName = pathinfo($outputName, PATHINFO_FILENAME) . '.txt';
        $outputPath = $resultsDir . DIRECTORY_SEPARATOR . $outputName;

        // Save prompt text
        file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $prompt);

        // Determine mime type to draw mockup image
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';

        // Draw and save the mock raster image
        $this->drawMockPng($contextId, basename($imagePath), $prompt, $outputPath, $mime);

        // Apply ImageResizer to scale the generated mockup proportionally to 2200 px on shortest side
        ImageResizer::resize($outputPath);

        $elapsed = round(microtime(true) - $t0, 2);
        Logger::log("Mockup MOCK generado y redimensionado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'mock');

        return [
            'file' => $outputName,
            'path' => $outputPath,
            'prompt_file' => $promptName,
            'mock' => true,
            'message' => 'Mockup placeholder generated locally and resized proportionally.',
        ];
    }

    private function drawMockPng(string $contextId, string $rootFile, string $prompt, string $filePath, string $mime): void
    {
        // 1536x1024 is the standard mockup canvas size before resizing
        $im = imagecreatetruecolor(1536, 1024);
        
        // Editorial background
        $bg = imagecolorallocate($im, 243, 243, 240);
        imagefill($im, 0, 0, $bg);

        // Draw outer canvas border
        $white = imagecolorallocate($im, 255, 255, 255);
        $border = imagecolorallocate($im, 213, 213, 206);
        imagefilledrectangle($im, 120, 96, 1416, 928, $white);
        imagerectangle($im, 120, 96, 1416, 928, $border);

        // Draw frame placeholder representing the artwork
        $frameBg = imagecolorallocate($im, 236, 232, 223);
        $frameBorder = imagecolorallocate($im, 17, 17, 17);
        imagefilledrectangle($im, 468, 210, 1068, 590, $frameBg);
        imagerectangle($im, 468, 210, 1068, 590, $frameBorder);

        // Draw perspective lines
        $gold = imagecolorallocate($im, 199, 185, 140);
        $blue = imagecolorallocate($im, 139, 154, 174);
        imageline($im, 468, 210, 1068, 590, $gold);
        imageline($im, 1068, 210, 468, 590, $blue);

        // Render mockup details text onto the image
        $textColor = imagecolorallocate($im, 17, 17, 17);
        $mutedText = imagecolorallocate($im, 68, 68, 68);
        
        imagestring($im, 5, 680, 640, "MOCKUP SIMULADO", $textColor);
        imagestring($im, 4, 480, 690, "Contexto: " . substr($contextId, 0, 60), $mutedText);
        imagestring($im, 4, 480, 720, "Obra Raiz: " . substr($rootFile, 0, 60), $mutedText);
        
        $wrappedPrompt = wordwrap($prompt, 95, "\n");
        $lines = explode("\n", $wrappedPrompt);
        $y = 760;
        foreach (array_slice($lines, 0, 5) as $line) {
            imagestring($im, 3, 250, $y, trim($line), $mutedText);
            $y += 20;
        }

        if ($mime === 'image/jpeg') {
            imagejpeg($im, $filePath, 90);
        } else {
            imagepng($im, $filePath, 6);
        }
        
        imagedestroy($im);
    }
}
