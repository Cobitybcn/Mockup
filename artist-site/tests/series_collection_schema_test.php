<?php
declare(strict_types=1);

$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$footer = (string)file_get_contents(dirname(__DIR__) . '/inc/footer.php');

$checks = [
    [
        str_contains($index, "'@type' => 'CollectionPage'"),
        'series detail pages emit CollectionPage schema'
    ],
    [
        str_contains($index, "'@type' => 'ItemList'"),
        'series detail pages list dependent artworks in ItemList schema'
    ],
    [
        str_contains($footer, "knowsAbout"),
        'global Person schema includes knowsAbout expertise attributes'
    ],
    [
        str_contains($index, "case 'paintings':") && str_contains($index, "301"),
        'legacy /paintings/ route issues 301 permanent redirect to /artworks/'
    ],
];

foreach ($checks as [$passed, $message]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$message}.\n");
        exit(1);
    }
}

echo "PASS: CollectionPage series schema, Person knowsAbout, and legacy 301 redirects verified.\n";
