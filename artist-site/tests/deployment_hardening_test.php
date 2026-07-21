<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dockerfile = (string)file_get_contents($root . '/Dockerfile');
$cloudBuild = (string)file_get_contents($root . '/cloudbuild.hardening.yaml');
$dockerIgnore = (string)file_get_contents($root . '/.dockerignore');
$cloudIgnore = (string)file_get_contents($root . '/.gcloudignore');

$checks = [
    [str_contains($dockerfile, 'artist-site-transport-security'), 'production TLS termination emits HSTS'],
    [str_contains($cloudBuild, 'candidate-smoke'), 'candidate revision is smoke-tested before promotion'],
    [str_contains($cloudBuild, '/admin-v2/'), 'both retired Cloud Run admin routes are verified'],
    [str_contains($cloudBuild, '--clear-tags'), 'promotion retires stale tagged revisions'],
    [str_contains($cloudBuild, 'serviceAccounts/mockups-cicd-sa@'), 'artist releases use the dedicated CI/CD identity'],
    [substr_count($cloudBuild, 'BUILD_ID="$BUILD_ID"') === 2, 'Cloud Build ID is substituted before shell substring expansion'],
    [str_contains($dockerIgnore, 'assets/uploads/') && str_contains($cloudIgnore, 'assets/uploads/'), 'runtime uploads never enter build contexts'],
    [str_contains($dockerIgnore, 'assets/tenants/') && str_contains($cloudIgnore, 'assets/tenants/'), 'tenant runtime data never enters build contexts'],
];

foreach ($checks as [$passed, $description]) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
}

echo "PASS: artist deployment hardening\n";
