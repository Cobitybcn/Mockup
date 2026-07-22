<?php
declare(strict_types=1);

function run_deployment_pipeline_regression_tests(): void
{
    $root = dirname(__DIR__, 2);
    $cloudBuild = (string) file_get_contents($root . '/cloudbuild.ci.yaml');
    $setupScript = (string) file_get_contents($root . '/scripts/setup_cloud_build_cicd.ps1');

    TestHarness::assertTrue(
        str_contains($cloudBuild, 'APP_RELEASE_COMMIT=$COMMIT_SHA'),
        'production revisions record the immutable commit used for release scope detection'
    );
    TestHarness::assertTrue(
        str_contains($cloudBuild, 'platform/migrations/schema/*')
            && str_contains($cloudBuild, 'MIGRATIONS_REQUIRED'),
        'database migration jobs are limited to schema migration changes'
    );
    TestHarness::assertTrue(
        str_contains($cloudBuild, 'WORKER_REQUIRED')
            && str_contains($cloudBuild, 'Skipping worker deployment'),
        'web-only releases skip worker build and deployment work'
    );
    TestHarness::assertTrue(
        str_contains($setupScript, "'platform/**', 'site-admin/**'")
            && str_contains($setupScript, "'artist-site/**'")
            && str_contains($setupScript, 'artist-site-main-deploy'),
        'main branch triggers route app and artist-site changes independently'
    );
}

