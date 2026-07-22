<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dockerfile = (string)file_get_contents($root . '/Dockerfile');
$cloudBuild = str_replace("\r\n", "\n", (string)file_get_contents($root . '/cloudbuild.hardening.yaml'));
$dockerIgnore = (string)file_get_contents($root . '/.dockerignore');
$cloudIgnore = (string)file_get_contents($root . '/.gcloudignore');

$checks = [
    [str_contains($dockerfile, 'artist-site-transport-security'), 'production TLS termination emits HSTS'],
    [str_contains($dockerfile, 'composer install --no-dev') && str_contains($dockerfile, '/app/vendor'), 'production image installs the contact mail transport'],
    [str_contains($cloudBuild, 'candidate-smoke'), 'candidate revision is smoke-tested before promotion'],
    [str_contains($cloudBuild, '/admin-v2/'), 'both retired Cloud Run admin routes are verified'],
    [str_contains($cloudBuild, '--clear-tags'), 'promotion retires stale tagged revisions'],
    [str_contains($cloudBuild, 'serviceAccounts/mockups-cicd-sa@'), 'artist releases use the dedicated CI/CD identity'],
    [str_contains($cloudBuild, 'pull-cache') && str_contains($cloudBuild, '--cache-from'), 'artist releases reuse the previous production image as a build cache'],
    [str_contains($cloudBuild, 'artist-site/Dockerfile') && str_contains($cloudBuild, "- artist-site\n"), 'artist trigger builds only the artist-site context from the monorepo root'],
    [str_contains($cloudBuild, '_PRODUCTION_BRANCH: main') && str_contains($cloudBuild, '$BRANCH_NAME'), 'artist releases accept only the production branch'],
    [str_contains($cloudBuild, 'git lfs pull --include="artist-site/assets/images/**" --exclude=""') && str_contains($cloudBuild, 'hydrate-active-lfs-assets'), 'artist releases hydrate only active Git LFS images before building'],
    [str_contains($cloudBuild, 'waitFor: [verify-production-target, pull-cache, hydrate-active-lfs-assets]'), 'artist image build waits for active Git LFS assets'],
    [str_contains($cloudBuild, 'git-lfs.github.com/spec/v1'), 'artist image smoke test rejects unresolved Git LFS pointers'],
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
