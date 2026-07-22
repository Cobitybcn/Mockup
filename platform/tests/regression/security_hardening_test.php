<?php
declare(strict_types=1);

function run_security_hardening_regression_tests(): void
{
    TestHarness::group('Production security hardening');

    $platformRoot = dirname(__DIR__, 2);
    $repositoryRoot = dirname($platformRoot);

    foreach (['reset_admin.php', 'run_git.php', 'set_local_mock.php', 'test_post.php', 'auth_mockup_image.php', 'check_jobs.php', 'cloud_diag.php', 'login_bypass.php', 'poc_trigger.php', 'poc_worker.php'] as $retired) {
        TestHarness::assertTrue(!is_file($platformRoot . '/' . $retired), $retired . ' is absent from the web artifact');
    }

    $apache = (string)file_get_contents($platformRoot . '/apache-security.conf');
    TestHarness::assertContains('check_', $apache, 'diagnostic scripts are denied by Apache');
    TestHarness::assertContains('cleanup_jobs', $apache, 'maintenance scripts are denied by Apache');
    TestHarness::assertContains('/(?:analysis|app|docs|jobs|logs|migrations', $apache, 'internal directories are denied by Apache');
    TestHarness::assertContains(
        'SetEnvIf Request_URI "^/(?:platform/)?(?:create_scenes_wait|mockup_combinations_review)\\.php$" artwork_same_origin_frame=1',
        $apache,
        'only the authenticated compact progress pages may be framed'
    );
    TestHarness::assertContains(
        'X-Frame-Options "SAMEORIGIN" env=artwork_same_origin_frame',
        $apache,
        'compact progress framing remains restricted to the same origin'
    );
    TestHarness::assertContains(
        'frame-ancestors \'self\'" env=artwork_same_origin_frame',
        $apache,
        'the CSP independently restricts compact progress to the same origin'
    );
    TestHarness::assertContains(
        'X-Frame-Options "DENY" env=!artwork_same_origin_frame',
        $apache,
        'every other production page remains impossible to frame'
    );

    $auth = (string)file_get_contents($platformRoot . '/app/Support/Auth.php');
    TestHarness::assertTrue(!str_contains($auth, 'password_reset_links.log'), 'password reset links are never written below the document root');
    TestHarness::assertContains('PUBLIC_REGISTRATION_ENABLED', $auth, 'public production registration is explicitly gated');
    TestHarness::assertContains('session_version = session_version + 1', $auth, 'password changes invalidate other sessions');

    $registerPage = (string)file_get_contents($platformRoot . '/register.php');
    $loginPage = (string)file_get_contents($platformRoot . '/login.php');
    TestHarness::assertContains('http_response_code(404)', $registerPage, 'disabled public registration is not exposed as a production page');
    TestHarness::assertContains('$publicRegistrationEnabled', $loginPage, 'login hides the registration invitation when registration is disabled');

    $patternMethod = new ReflectionMethod(RequestSecurity::class, 'protectedEndpointPattern');
    $protectedPattern = (string)$patternMethod->invoke(null);
    foreach (['admin_users.php', 'delete_mockup.php', 'merge_artwork_groups.php', 'upload_external_mockup.php', 'website_board_action.php'] as $protectedRoute) {
        TestHarness::assertSame(1, preg_match($protectedPattern, $protectedRoute), $protectedRoute . ' mutations are covered by centralized CSRF enforcement');
    }

    $previousAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '192.0.2.197';
    $identity = 'security-regression-' . bin2hex(random_bytes(8)) . '@example.test';
    TestHarness::assertTrue(AuthRateLimiter::consume('security_test', $identity, 2, 300), 'rate limiter allows the first attempt');
    TestHarness::assertTrue(AuthRateLimiter::consume('security_test', $identity, 2, 300), 'rate limiter allows the configured final attempt');
    TestHarness::assertTrue(!AuthRateLimiter::consume('security_test', $identity, 2, 300), 'rate limiter blocks attempts above the threshold');
    AuthRateLimiter::clear('security_test', $identity);
    TestHarness::assertTrue(AuthRateLimiter::consume('security_test', $identity, 2, 300), 'rate limiter can be cleared after successful authentication');
    AuthRateLimiter::clear('security_test', $identity);
    if ($previousAddress === null) unset($_SERVER['REMOTE_ADDR']); else $_SERVER['REMOTE_ADDR'] = $previousAddress;

    require_once $repositoryRoot . '/artist-site/inc/functions.php';
    $safeText = safe_rich_text('<p>Studio note</p><script>alert(1)</script><img src=x onerror=alert(2)>');
    TestHarness::assertTrue(!str_contains($safeText, '<script') && !str_contains($safeText, '<img'), 'published rich text cannot emit executable HTML');
    TestHarness::assertContains('Studio note', $safeText, 'published rich text preserves readable content');

    $artistIndex = (string)file_get_contents($repositoryRoot . '/artist-site/index.php');
    TestHarness::assertContains("http_response_code(503)", $artistIndex, 'unresolved artist tenants fail closed');
    TestHarness::assertContains("getenv('K_SERVICE')", $artistIndex, 'ephemeral artist administration is disabled on Cloud Run');

    $queueWorker = (string)file_get_contents($platformRoot . '/mockup_queue_worker.php');
    TestHarness::assertTrue(!str_contains($queueWorker, '@mkdir') && !str_contains($queueWorker, '@fopen'), 'worker lock failures are visible and fail closed');
    $cleanup = (string)file_get_contents($platformRoot . '/cleanup_jobs.php');
    TestHarness::assertContains('realpath($jobsRoot)', $cleanup, 'job cleanup confines recursive deletion to the jobs directory');
    TestHarness::assertTrue(!str_contains($cleanup, '@unlink') && !str_contains($cleanup, '@rmdir'), 'job cleanup reports deletion failures');

    $releaseBuild = (string)file_get_contents($platformRoot . '/cloudbuild.release.yaml');
    TestHarness::assertContains('platform/Dockerfile.web', $releaseBuild, 'release build resolves the web Dockerfile from the repository root');
    TestHarness::assertContains('platform/Dockerfile.worker', $releaseBuild, 'release build resolves the worker Dockerfile from the repository root');
    TestHarness::assertContains('tests/run_regression_tests.php', $releaseBuild, 'release artifacts must pass regression tests before they are published');
    $webDockerfile = (string)file_get_contents($platformRoot . '/Dockerfile.web');
    TestHarness::assertContains('artist-site/inc/functions.php', $webDockerfile, 'the production image carries the complete artist security contract fixture');
    TestHarness::assertContains('apache2ctl configtest', $webDockerfile, 'the production image rejects invalid Apache security configuration during its build');
}
