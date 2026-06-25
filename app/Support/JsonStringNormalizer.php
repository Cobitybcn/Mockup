<?php
declare(strict_types=1);

class JsonStringNormalizer
{
    /**
     * Base string cleaning helper.
     *
     * - Accepts null
     * - Trims whitespace and peripheral punctuation
     * - Converts empty strings to null
     * - Normalizes multiple spaces to a single space
     * - Eliminates redundant newlines (merges whitespace)
     */
    public static function normalizeRawString(?string $val): ?string
    {
        if ($val === null) {
            return null;
        }

        // Normalize multiple spaces and newlines to a single space
        $val = preg_replace('/\s+/u', ' ', $val);
        if ($val === null) {
            return null;
        }

        // Trim whitespace and common peripheral punctuation
        $val = trim($val, " \t\n\r\0\x0B.,:;!?()[]{}'\"`„“”‘’");
        
        return $val !== '' ? $val : null;
    }

    /**
     * Converts a string into a comparable internal form (token format).
     *
     * - Uses normalizeRawString
     * - Converts to lowercase
     * - Transliterates common Spanish accents to base characters
     * - Replaces dashes, slashes, and underscores with spaces
     * - Normalizes spaces to a single space
     */
    public static function normalizeTokenString(?string $val): ?string
    {
        $val = self::normalizeRawString($val);
        if ($val === null) {
            return null;
        }

        // Convert to lowercase
        $val = mb_strtolower($val, 'UTF-8');

        // Transliterate accents
        $val = self::removeAccents($val);

        // Replace separators with spaces
        $val = str_replace(['-', '/', '_'], ' ', $val);

        // Compact multiple spaces created by separator replacements
        $val = preg_replace('/\s+/u', ' ', $val);
        if ($val === null) {
            return null;
        }
        
        $val = trim($val);

        return $val !== '' ? $val : null;
    }

    /**
     * Normalizes a value against an explicit map of aliases.
     *
     * - Normalizes input to token format
     * - Normalizes each alias to token format
     * - Performs exact matching first
     * - Performs substring matching only if allowed explicitly
     */
    public static function normalizeEnumString(?string $val, array $aliases, ?string $default = null, bool $allowSubstring = false): ?string
    {
        $token = self::normalizeTokenString($val);
        if ($token === null) {
            return $default;
        }

        // 1. Exact match on normalized token
        foreach ($aliases as $canonical => $list) {
            foreach ($list as $alias) {
                $aliasToken = self::normalizeTokenString($alias);
                if ($aliasToken === $token) {
                    return $canonical;
                }
            }
        }

        // 2. Controlled substring matching (disabled by default)
        if ($allowSubstring) {
            foreach ($aliases as $canonical => $list) {
                foreach ($list as $alias) {
                    $aliasToken = self::normalizeTokenString($alias);
                    if ($aliasToken !== null) {
                        if (str_contains($token, $aliasToken) || str_contains($aliasToken, $token)) {
                            return $canonical;
                        }
                    }
                }
            }
        }

        return $default;
    }

    /**
     * Standardizes a root view identifier.
     *
     * Returns: "three_quarter_left", "frontal", "three_quarter_right", or null.
     */
    public static function normalizeRootView(?string $val): ?string
    {
        $aliases = [
            'three_quarter_left' => [
                'three_quarter_left',
                'three quarter left',
                '3/4 left',
                '3 4 left',
                'left',
                'left view',
                'left root view',
                'three-quarter left root view',
                'izquierda',
                '3/4 izquierda',
                'tres cuartos izquierda',
                'v2',
                'version 2',
                'candidate 2'
            ],
            'frontal' => [
                'frontal',
                'front',
                'front view',
                'frontal root view',
                'center',
                'centre',
                'centro',
                'frente',
                'v1',
                'version 1',
                'candidate 1'
            ],
            'three_quarter_right' => [
                'three_quarter_right',
                'three quarter right',
                '3/4 right',
                '3 4 right',
                'right',
                'right view',
                'right root view',
                'three-quarter right root view',
                'derecha',
                '3/4 derecha',
                'tres cuartos derecha',
                'v3',
                'version 3',
                'candidate 3'
            ],
        ];

        return self::normalizeEnumString($val, $aliases, null, false);
    }

    /**
     * Standardizes orientation options.
     *
     * Returns: "horizontal", "vertical", "square", or null.
     */
    public static function normalizeOrientation(?string $val): ?string
    {
        $aliases = [
            'horizontal' => [
                'horizontal',
                'landscape',
                'apaisado',
                'ancho',
                'wide'
            ],
            'vertical' => [
                'vertical',
                'portrait',
                'retrato',
                'alto',
                'tall'
            ],
            'square' => [
                'square',
                'cuadrado',
                '1:1',
                'equal',
                'equal sides'
            ]
        ];

        return self::normalizeEnumString($val, $aliases, null, false);
    }

    /**
     * Standardizes scale categories.
     *
     * Returns: "extra_large", "large", "medium", "small", or null.
     */
    public static function normalizeScaleCategory(?string $val): ?string
    {
        $aliases = [
            'extra_large' => [
                'extra large',
                'extra_large',
                'xl',
                'x large',
                'monumental',
                'oversized',
                'muy grande',
                'enorme'
            ],
            'large' => [
                'large',
                'big',
                'grande',
                'grande formato',
                'large format'
            ],
            'medium' => [
                'medium',
                'mediano',
                'medium format',
                'formato medio'
            ],
            'small' => [
                'small',
                'pequeño',
                'chico',
                'mini',
                'compact',
                'small format'
            ]
        ];

        return self::normalizeEnumString($val, $aliases, null, false);
    }

    /**
     * Normalizes a collection to a flat list of trimmed, unique, non-empty strings.
     * Supports CSV string, newline separated string, flat array, or array of objects with standard fields.
     */
    public static function normalizeArrayOfStrings($val): array
    {
        if ($val === null) {
            return [];
        }

        $items = [];

        if (is_string($val)) {
            // Split by commas and/or newlines
            $parts = preg_split('/[\n,\r]+/', $val);
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    $items[] = $part;
                }
            }
        } elseif (is_array($val)) {
            foreach ($val as $item) {
                if (is_array($item)) {
                    // Extract values from known associative keys
                    foreach (['name', 'value', 'label', 'title', 'color', 'description'] as $key) {
                        if (isset($item[$key]) && (is_string($item[$key]) || is_numeric($item[$key]))) {
                            $items[] = (string)$item[$key];
                            break;
                        }
                    }
                } elseif (is_string($item) || is_numeric($item)) {
                    $items[] = (string)$item;
                }
            }
        }

        // Trim and remove empty elements
        $cleaned = [];
        foreach ($items as $item) {
            $trimmed = trim((string)$item);
            if ($trimmed !== '') {
                $cleaned[] = $trimmed;
            }
        }

        // Preserving order and filtering out duplicates
        return array_values(array_unique($cleaned));
    }

    /**
     * Accents removal helper for Spanish characters.
     */
    private static function removeAccents(string $string): string
    {
        $replace = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n'
        ];
        return strtr($string, $replace);
    }
}
