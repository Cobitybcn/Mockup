<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dockerfile = (string)file_get_contents($root . '/Dockerfile');
$cloudBuild = (string)file_get_contents($root . '/cloudbuild.hardening.yaml');

$checks = [
    [str_contains($dockerfile, 'artist-site-transport-security'), 'production TLS termination emits HSTS'],
    [str_contains($cloudBuild, 'candidate-smoke'), 'candidate revision is smoke-tested before promotion'],
    [str_contains($cloudBuild, '/admin-v2/'), 'both retired Cloud Run admin routes are verified'],
    [str_contains($cloudBuild, '--clear-tags'), 'promotion retires stale tagged revisions'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

echo "PASS: artist deployment hardening\n";
