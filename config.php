<?php
declare(strict_types=1);

// Simple dotenv loader
$APP_ENV_VALUES = [];
$envPath = __DIR__ . DIRECTORY_SEPARATOR . '.env';
if (is_file($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (preg_match('/^"(.*)"$/', $value, $matches) || preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }
                $APP_ENV_VALUES[$name] = $value;
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}

function app_env(string $key, string $default = ''): string
{
    global $APP_ENV_VALUES;

    if (array_key_exists($key, $APP_ENV_VALUES)) {
        return trim((string)$APP_ENV_VALUES[$key]);
    }

    if (array_key_exists($key, $_ENV)) {
        return trim((string)$_ENV[$key]);
    }

    if (array_key_exists($key, $_SERVER)) {
        return trim((string)$_SERVER[$key]);
    }

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
define('GEMINI_IMAGE_MODEL', app_env('GEMINI_IMAGE_MODEL', 'gemini-3.1-flash-image'));
define('ADMIN_EMAILS', app_env('ADMIN_EMAILS', ''));
define('DB_CONNECTION', app_env('DB_CONNECTION', 'sqlite'));
define('DB_HOST', app_env('DB_HOST', '127.0.0.1'));
define('DB_PORT', app_env('DB_PORT', '3306'));
define('DB_DATABASE', app_env('DB_DATABASE', 'mockups'));
define('DB_USERNAME', app_env('DB_USERNAME', 'root'));
define('DB_PASSWORD', app_env('DB_PASSWORD', ''));
define('DB_CHARSET', app_env('DB_CHARSET', 'utf8mb4'));
define('MOCKUP_WORKER_COUNT', app_env('MOCKUP_WORKER_COUNT', '4'));
define('MOCKUP_PROMPT_FIRST_MODE', strtolower(app_env('MOCKUP_PROMPT_FIRST_MODE', 'false')) === 'true');
define('MOCKUP_PROMPT_FIRST_NO_MASK_MODE', strtolower(app_env('MOCKUP_PROMPT_FIRST_NO_MASK_MODE', 'false')) === 'true');
define('MOCKUP_USE_PRECOMPOSITION', strtolower(app_env('MOCKUP_USE_PRECOMPOSITION', 'false')) === 'true');
define('MOCKUP_USE_BACKGROUND_EDIT', strtolower(app_env('MOCKUP_USE_BACKGROUND_EDIT', 'false')) === 'true');
define('LEGACY_MOCKUP_FLOW_ENABLED', strtolower(app_env('LEGACY_MOCKUP_FLOW_ENABLED', 'false')) === 'true');


// Punto #2: ruta configurable al ejecutable PHP (evita rutas hardcodeadas a versiones específicas)
define('PHP_BINARY_PATH', app_env('PHP_BINARY_PATH', ''));

// Punto #3: ruta configurable al ejecutable Python con google.genai instalado
define('PYTHON_BINARY_PATH', app_env('PYTHON_BINARY_PATH', ''));

// Punto #4: ID del proyecto en Google Cloud / Vertex AI
define('VERTEX_PROJECT_ID', app_env('VERTEX_PROJECT_ID', 'project-3c7fb926-f021-47c6-9cc'));

define('ANALYSIS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'analysis');
define('PROMPTS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'mockup-prompts');
define('RESULTS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'results');
