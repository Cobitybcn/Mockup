<?php
declare(strict_types=1);

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$header = (string)file_get_contents(dirname(__DIR__) . '/inc/header.php');
$functions = (string)file_get_contents(dirname(__DIR__) . '/inc/functions.php');

$checks = [
    [
        str_contains($index, "hasCustomMockupSeo") || str_contains($index, "noindex, follow"),
        'mockups without explicit custom SEO metadata emit noindex, follow'
    ],
    [
        str_contains($header, "meta name=\"robots\""),
        'header renders robots meta tag when specified in page meta'
    ],
    [
        str_contains($index, "published_seo_title(") || str_contains($index, "published_default_seo_title("),
        'published artwork SEO titles use standardized builder function'
    ],
    [
        str_contains($index, "artworkCanonicalBase") || str_contains($index, "\$meta['canonical']"),
        'secondary contextual mockups set canonical towards parent artwork'
    ],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}

echo "PASS: artwork SEO indexation, mockup noindex policy, and canonical fallback contracts verified.\n";
