<?php
declare(strict_types=1);

final class SiteCopy
{
    /** @var array<string,array<string,mixed>> */
    private static array $catalogs = [];

    public static function text(string $locale, string $key, array $replacements = []): string
    {
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';
        $value = self::value(self::catalog($locale), $key);
        if (!is_string($value) || trim($value) === '') {
            error_log("Artist site translation missing: {$locale}.{$key}");
            $fallback = self::value(self::catalog('en'), $key);
            $value = is_string($fallback) ? $fallback : '';
            if (self::isDevelopment()) {
                $value = "[missing {$locale}.{$key}]" . ($value !== '' ? ' ' . $value : '');
            }
        }

        if ($replacements !== []) {
            $tokens = [];
            foreach ($replacements as $name => $replacement) {
                $tokens['{' . $name . '}'] = (string)$replacement;
            }
            $value = strtr($value, $tokens);
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function catalog(string $locale): array
    {
        $locale = in_array($locale, ['en', 'es'], true) ? $locale : 'en';
        if (isset(self::$catalogs[$locale])) return self::$catalogs[$locale];

        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'locales' . DIRECTORY_SEPARATOR . $locale . '.php';
        $catalog = is_file($path) ? require $path : [];
        return self::$catalogs[$locale] = is_array($catalog) ? $catalog : [];
    }

    private static function value(array $catalog, string $key): mixed
    {
        $value = $catalog;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return null;
            $value = $value[$segment];
        }
        return $value;
    }

    private static function isDevelopment(): bool
    {
        return in_array(strtolower(trim((string)(getenv('APP_ENV') ?: ''))), [
            'local',
            'development',
            'testing',
        ], true);
    }
}
