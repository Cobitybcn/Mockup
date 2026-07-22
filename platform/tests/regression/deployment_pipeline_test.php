<?php
declare(strict_types=1);

function run_deployment_pipeline_regression_tests(): void
{
    $root = dirname(__DIR__, 2);
    $cloudBuild = (string) file_get_contents($root . '/cloudbuild.ci.yaml');
    $preflightBuild = (string) file_get_contents($root . '/cloudbuild.preflight.yaml');
    $webDockerfile = (string) file_get_contents($root . '/Dockerfile.web');
    $setupScript = (string) file_get_contents($root . '/scripts/setup_cloud_build_cicd.ps1');
    $siteManagerPath = dirname($root) . '/site-admin/app/SiteManagerService.php';
    if (!is_file($siteManagerPath)) {
        $siteManagerPath = $root . '/site-admin/app/SiteManagerService.php';
    }
    $siteManager = (string) file_get_contents($siteManagerPath);

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
            && str_contains($setupScript, 'artist-site-main-deploy')
            && str_contains($setupScript, 'artwork-mockups-preflight')
            && str_contains($setupScript, "-BranchPattern '^codex/.*$'"),
        'main branch triggers route app and artist-site changes independently'
    );
    TestHarness::assertTrue(
        str_contains($webDockerfile, 'artist-site/inc/StripeArtistCredentials.php')
            && str_contains($siteManager, "'/app/Services/StripeArtistCredentials.php'"),
        'Site Manager Stripe dependencies support the production container layout'
    );
    TestHarness::assertTrue(
        str_contains($cloudBuild, 'production-artifact-smoke')
            && str_contains($cloudBuild, 'SiteManagerService.php')
            && str_contains($cloudBuild, 'StripeArtistCredentials.php'),
        'the built web image loads Site Manager dependencies before it can be pushed'
    );
    TestHarness::assertTrue(
        str_contains($cloudBuild, 'web-candidate-smoke')
            && str_contains($cloudBuild, '/site-admin/?area=store&section=orders')
            && str_contains($cloudBuild, 'promote-web'),
        'the candidate web revision is smoke-tested before receiving production traffic'
    );
    TestHarness::assertTrue(
        str_contains($preflightBuild, 'build-production-web-image')
            && str_contains($preflightBuild, 'production-artifact-smoke')
            && str_contains($preflightBuild, 'composer')
            && str_contains($preflightBuild, 'audit'),
        'a deployment preflight reproduces the production image, dependency load, and package audit'
    );
}
