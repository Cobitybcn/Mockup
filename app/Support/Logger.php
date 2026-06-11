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
}
