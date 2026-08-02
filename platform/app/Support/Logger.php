<?php
declare(strict_types=1);

class Logger
{
    public static function log(string $message, string $category = 'info'): void
    {
        self::writeCloudLog($message, $category);

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

    /**
     * Records an operational failure with enough context to correlate a Cloud
     * Tasks request without exposing the exception to the HTTP response.
     */
    public static function exception(string $operation, Throwable $error, array $context = []): string
    {
        $errorId = bin2hex(random_bytes(8));
        $payload = array_filter([
            'event' => 'operation_failed',
            'operation' => $operation,
            'error_id' => $errorId,
            'exception' => get_class($error),
            'message' => $error->getMessage(),
            'job_id' => isset($context['job_id']) ? (int)$context['job_id'] : null,
            'task_name' => self::requestHeader('HTTP_X_CLOUDTASKS_TASKNAME'),
            'queue_name' => self::requestHeader('HTTP_X_CLOUDTASKS_QUEUENAME'),
            'trace' => self::requestHeader('HTTP_X_CLOUD_TRACE_CONTEXT'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        self::log(
            (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'error'
        );

        return $errorId;
    }

    private static function writeCloudLog(string $message, string $category): void
    {
        if (strtolower((string)getenv('APP_ENV')) !== 'production' && getenv('K_SERVICE') === false) {
            return;
        }

        $severity = match (strtolower($category)) {
            'error', 'fatal' => 'ERROR',
            'warning', 'warn' => 'WARNING',
            default => 'INFO',
        };
        $entry = [
            'severity' => $severity,
            'message' => $message,
            'category' => $category,
            'service' => (string)(getenv('K_SERVICE') ?: 'artworkmockups'),
            'revision' => (string)(getenv('K_REVISION') ?: ''),
        ];
        $structuredMessage = json_decode($message, true);
        if (is_array($structuredMessage)) {
            $entry = array_merge($entry, $structuredMessage);
        }
        error_log((string)json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function requestHeader(string $name): ?string
    {
        $value = $_SERVER[$name] ?? null;
        return is_string($value) && $value !== '' ? substr($value, 0, 500) : null;
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
