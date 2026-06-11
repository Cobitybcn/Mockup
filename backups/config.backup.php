<?php
declare(strict_types=1);

function app_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false ? $default : trim((string)$value);
}

define('APP_MODE', app_env('APP_MODE', 'mock'));
define('ALLOW_REAL_API', strtolower(app_env('ALLOW_REAL_API', 'false')) === 'true');
define('OPENAI_API_KEY', app_env('OPENAI_API_KEY', ''));
define('OPENAI_IMAGE_MODEL', app_env('OPENAI_IMAGE_MODEL', 'gpt-image-1'));
define('OPENAI_ANALYSIS_MODEL', app_env('OPENAI_ANALYSIS_MODEL', 'gpt-4.1-mini'));
define('OPENAI_IMAGE_QUALITY', app_env('OPENAI_IMAGE_QUALITY', 'low'));
define('OPENAI_IMAGE_SIZE', app_env('OPENAI_IMAGE_SIZE', '1024x1024'));
define('IMAGE_PROVIDER', app_env('IMAGE_PROVIDER', 'openai'));
define('GEMINI_API_KEY', app_env('GEMINI_API_KEY', ''));
define('GEMINI_IMAGE_MODEL', app_env('GEMINI_IMAGE_MODEL', 'gemini-2.5-flash-image'));
define('ADMIN_EMAILS', app_env('ADMIN_EMAILS', ''));
