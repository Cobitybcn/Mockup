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

        $stamp = time() . '_' . random_int(1000, 9999);
        $promptName = 'mockup_prompt_mock_' . $stamp . '.txt';
        $outputName = 'mockup_mock_' . $stamp . '.svg';

        file_put_contents($promptsDir . DIRECTORY_SEPARATOR . $promptName, $prompt);
        file_put_contents($resultsDir . DIRECTORY_SEPARATOR . $outputName, $this->svg($contextId, basename($imagePath), $prompt));

        $elapsed = round(microtime(true) - $t0, 2);
        Logger::log("Mockup MOCK generado exitosamente en {$elapsed}s. Archivo: {$outputName}", 'mock');

        return [
            'file' => $outputName,
            'path' => $resultsDir . DIRECTORY_SEPARATOR . $outputName,
            'prompt_file' => $promptName,
            'mock' => true,
            'message' => 'Mockup placeholder generado localmente. No se uso API.',
        ];
    }

    private function svg(string $contextId, string $rootFile, string $prompt): string
    {
        $context = htmlspecialchars($contextId ?: 'contexto_mock', ENT_QUOTES, 'UTF-8');
        $root = htmlspecialchars($rootFile, ENT_QUOTES, 'UTF-8');
        $shortPrompt = htmlspecialchars(substr(preg_replace('/\s+/', ' ', $prompt), 0, 220), ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1536" height="1024" viewBox="0 0 1536 1024">
  <rect width="1536" height="1024" fill="#f3f3f0"/>
  <rect x="120" y="96" width="1296" height="832" fill="#ffffff" stroke="#d5d5ce" stroke-width="4"/>
  <rect x="468" y="210" width="600" height="380" fill="#ece8df" stroke="#111111" stroke-width="8"/>
  <line x1="468" y1="210" x2="1068" y2="590" stroke="#c7b98c" stroke-width="10" opacity="0.8"/>
  <line x1="1068" y1="210" x2="468" y2="590" stroke="#8b9aae" stroke-width="10" opacity="0.8"/>
  <text x="768" y="660" font-family="Arial, sans-serif" font-size="42" text-anchor="middle" fill="#111111">MOCKUP SIMULADO</text>
  <text x="768" y="718" font-family="Arial, sans-serif" font-size="28" text-anchor="middle" fill="#444444">{$context}</text>
  <text x="768" y="770" font-family="Arial, sans-serif" font-size="22" text-anchor="middle" fill="#555555">Imagen raiz: {$root}</text>
  <foreignObject x="250" y="810" width="1036" height="90">
    <div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Arial,sans-serif;font-size:20px;line-height:1.35;color:#333;text-align:center;">
      {$shortPrompt}
    </div>
  </foreignObject>
</svg>
SVG;
    }
}
