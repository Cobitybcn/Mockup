<?php
declare(strict_types=1);

class Logger
{
    public static function log(string $message, string $category = 'info'): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n");
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'app.log';
        $timestamp = date('Y-m-d H:i:s');
        $formatted = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($category), $message);
        @file_put_contents($logFile, $formatted, FILE_APPEND);
    }

    public static function logMockupGeneration(int $mockupId, int $artworkId, string $contextId, string $finalPrompt, string $cameraView, string $humanPresence): void
    {
        $precomposition = (strtolower(app_env('MOCKUP_USE_PRECOMPOSITION', 'false')) === 'true') ? 'active' : 'inactive';
        $maskInpainting = ($precomposition === 'active') ? 'active' : 'inactive';
        
        $legacyRulesDetected = [];
        $legacyTerms = ['50-70%', 'occupy roughly', 'filling at least', 'artwork-dominant', 'artwork dominant', 'close, cropped, and intimate', 'large statement', 'monumental piece'];
        foreach ($legacyTerms as $term) {
            if (stripos($finalPrompt, $term) !== false) {
                $legacyRulesDetected[] = $term;
            }
        }
        $legacyStatus = count($legacyRulesDetected) > 0 ? 'DETECTED (' . implode(', ', $legacyRulesDetected) . ')' : 'NONE';
        
        $logMessage = sprintf(
            "Mockup generated - Artwork ID: %d, Mockup ID: %d, Precomposition: %s, Inpainting: %s, MD5: %s, Legacy terms: %s, Camera view: %s, Human presence: %s",
            $artworkId,
            $mockupId,
            $precomposition,
            $maskInpainting,
            md5($finalPrompt),
            $legacyStatus,
            $cameraView,
            $humanPresence
        );
        self::log($logMessage, 'mockup_audit');

        // Guardar el prompt final exacto
        $debugDir = __DIR__ . '/../../logs/prompt_debug';
        if (!is_dir($debugDir)) {
            @mkdir($debugDir, 0775, true);
        }
        @file_put_contents($debugDir . DIRECTORY_SEPARATOR . 'mockup_' . $mockupId . '_final_prompt.txt', $finalPrompt);
    }
}
