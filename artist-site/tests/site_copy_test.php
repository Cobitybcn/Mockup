<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inc/SiteCopy.php';

/** @return list<string> */
function site_copy_leaf_keys(array $catalog, string $prefix = ''): array
{
    $keys = [];
    foreach ($catalog as $key => $value) {
        $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
        if (is_array($value)) {
            if (array_is_list($value)) {
                foreach ($value as $index => $item) {
                    if (is_array($item)) {
                        $keys = array_merge($keys, site_copy_leaf_keys($item, $path . '.' . $index));
                    } else {
                        $keys[] = $path . '.' . $index;
                    }
                }
            } else {
                $keys = array_merge($keys, site_copy_leaf_keys($value, $path));
            }
            continue;
        }
        $keys[] = $path;
    }
    sort($keys);
    return $keys;
}

$english = SiteCopy::catalog('en');
$spanish = SiteCopy::catalog('es');
$englishKeys = site_copy_leaf_keys($english);
$spanishKeys = site_copy_leaf_keys($spanish);

if ($englishKeys !== $spanishKeys) {
    fwrite(STDERR, "FAIL: EN and ES page-copy catalogs do not expose the same fields.\n");
    exit(1);
}
foreach (['en' => $english, 'es' => $spanish] as $locale => $catalog) {
    foreach (site_copy_leaf_keys($catalog) as $key) {
        $value = SiteCopy::text($locale, $key);
        if (trim($value) === '' || str_starts_with($value, '[missing ')) {
            fwrite(STDERR, "FAIL: {$locale}.{$key} is empty or unresolved.\n");
            exit(1);
        }
    }
}
if (SiteCopy::text('en', 'home.hero.title') === SiteCopy::text('es', 'home.hero.title')
    || !str_contains(SiteCopy::text('es', 'home.meta_description'), 'STRATA')
    || !str_contains(SiteCopy::text('es', 'home.meta_description'), 'VORTEX')) {
    fwrite(STDERR, "FAIL: localized home content is incomplete.\n");
    exit(1);
}

echo "PASS: institutional EN/ES catalogs are complete and structurally identical.\n";
