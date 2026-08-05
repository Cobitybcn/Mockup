<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/SiteCopy.php';

$locales = ['en', 'es'];
$forbiddenSubstrings = [
    'Reading should always begin',
    'La lectura debe partir siempre',
    'Symbols that are not clearly visible should not be introduced',
    'No deben mencionarse símbolos que no sean claramente visibles',
    'Avoid automatically turning each work',
    'Evitar convertir automáticamente cada obra',
];

foreach ($locales as $locale) {
    $catalog = SiteCopy::catalog($locale);
    $json = json_encode($catalog, JSON_UNESCAPED_UNICODE);
    foreach ($forbiddenSubstrings as $forbidden) {
        if (str_contains($json, $forbidden)) {
            fwrite(STDERR, "FAIL: locale '{$locale}' contains prompt leakage string: '{$forbidden}'.\n");
            exit(1);
        }
    }
}

echo "PASS: artist profile locales are clean of prompt leakage and prescriptive instructions.\n";
