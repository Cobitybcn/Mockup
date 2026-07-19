<?php
declare(strict_types=1);

function run_feature_access_regression_tests(): void
{
    $studio = [
        'id' => 0,
        'email' => 'studio@example.test',
        'status' => 'active',
        'is_admin' => 0,
        'plan_code' => FeatureAccess::PLAN_ARTIST_STUDIO,
    ];
    $pro = $studio;
    $pro['email'] = 'pro@example.test';
    $pro['plan_code'] = FeatureAccess::PLAN_ARTIST_PRO;
    $admin = $studio;
    $admin['email'] = 'admin@example.test';
    $admin['is_admin'] = 1;

    TestHarness::assertTrue(
        FeatureAccess::allows($studio, FeatureAccess::MOCKUPS_GENERATE),
        'Artist Studio can generate mockups'
    );
    TestHarness::assertTrue(
        FeatureAccess::allows($studio, FeatureAccess::MOCKUPS_LAB),
        'Artist Studio can use Mockup Lab'
    );
    TestHarness::assertSame(
        false,
        FeatureAccess::allows($studio, FeatureAccess::WEBSITE_MANAGE),
        'Artist Studio cannot manage Website'
    );
    TestHarness::assertSame(
        false,
        FeatureAccess::allows($studio, FeatureAccess::SOCIAL_MANAGE),
        'Artist Studio cannot manage Social Media'
    );
    TestHarness::assertSame(
        false,
        FeatureAccess::allows($studio, FeatureAccess::VIDEO_MANAGE),
        'Artist Studio cannot use Video Studio'
    );

    foreach ([FeatureAccess::WEBSITE_MANAGE, FeatureAccess::SOCIAL_MANAGE, FeatureAccess::VIDEO_MANAGE] as $feature) {
        TestHarness::assertTrue(
            FeatureAccess::allows($pro, $feature),
            'Artist Pro receives ' . $feature
        );
    }
    TestHarness::assertSame(
        false,
        FeatureAccess::allows($pro, FeatureAccess::ADMIN_SYSTEM),
        'Artist Pro cannot access system administration'
    );
    TestHarness::assertTrue(
        FeatureAccess::allows($admin, FeatureAccess::ADMIN_SYSTEM),
        'Admin receives system administration access'
    );
    TestHarness::assertSame(
        false,
        FeatureAccess::allows($admin, 'unknown.feature'),
        'Unknown feature keys fail closed for every role'
    );

    $pdo = Database::connection();
    $pdo->query('SELECT plan_code FROM users WHERE 1 = 0');
    $pdo->query('SELECT feature_key, allowed FROM user_feature_overrides WHERE 1 = 0');
    TestHarness::assertTrue(true, 'Access-control schema is available');

    $labEndpoint = file_get_contents(__DIR__ . '/../../generate_mockup_variation_lab.php') ?: '';
    TestHarness::assertContains(
        'FeatureAccess::MOCKUPS_LAB',
        $labEndpoint,
        'Mockup Lab generation is protected by its capability'
    );
    TestHarness::assertContains(
        'Database::deductCredit',
        $labEndpoint,
        'Every Mockup Lab AI generation keeps the credit debit'
    );

    $socialBoard = file_get_contents(__DIR__ . '/../../social_media_board.php') ?: '';
    TestHarness::assertContains(
        'FeatureAccess::SOCIAL_MANAGE',
        $socialBoard,
        'Social access is plan based'
    );
    TestHarness::assertSame(
        false,
        str_contains($socialBoard, "['credits']"),
        'Social access no longer depends on credit balance'
    );

    $sidebar = file_get_contents(__DIR__ . '/../../sidebar.php') ?: '';
    TestHarness::assertContains(
        'if ($sidebarIsAdmin || $sidebarCanUseVideo)',
        $sidebar,
        'Artist Pro receives the Video Studio navigation entry'
    );
    foreach (['Video' => 'Video', 'Website' => 'Website', 'Social' => 'Social'] as $featureLabel => $accessVariable) {
        TestHarness::assertContains(
            'if ($sidebarCanUse' . $accessVariable . '):',
            $sidebar,
            'Artist Studio does not receive the ' . $featureLabel . ' navigation entry'
        );
    }

    TestHarness::assertContains(
        'FeatureAccess::planForUser($sidebarUser) === FeatureAccess::PLAN_ARTIST_STUDIO',
        $sidebar,
        'Artist Studio receives the compact basic navigation'
    );
    TestHarness::assertContains(
        '<section class="sidebar-tab-group sidebar-basic-library" aria-label="Library">',
        $sidebar,
        'The basic library tabs form their own group beside the creation tabs'
    );
    TestHarness::assertContains(
        '<section class="sidebar-account sidebar-basic-profile" aria-label="Artist account">',
        $sidebar,
        'Artist Profile remains beside the Admin menu for the basic plan'
    );
    $sidebarStyles = file_get_contents(__DIR__ . '/../../style.css') ?: '';
    TestHarness::assertContains(
        '.sidebar-basic-library',
        $sidebarStyles,
        'The basic library menu is contained between its two separators'
    );
    TestHarness::assertContains(
        '.sidebar-basic-profile',
        $sidebarStyles,
        'Artist Profile keeps the publishing area pastel green treatment'
    );

    TestHarness::assertContains(
        'app-environment-badge',
        $sidebar,
        'The local environment is visibly identified in the application'
    );

    $databaseSupport = file_get_contents(__DIR__ . '/../../app/Support/Database.php') ?: '';
    TestHarness::assertContains(
        'assertEnvironmentDatabaseBoundary',
        $databaseSupport,
        'Database connections enforce the environment boundary'
    );
    TestHarness::assertContains(
        "APP_ENV=local requiere una base cuyo nombre contenga 'local'",
        $databaseSupport,
        'The local environment cannot connect to a production-named database'
    );

    $boundaryMethod = new ReflectionMethod(Database::class, 'assertEnvironmentDatabaseBoundary');
    $boundaryMethod->setAccessible(true);
    $hadEnvironment = array_key_exists('APP_ENV', $GLOBALS['APP_ENV_VALUES']);
    $previousEnvironment = $GLOBALS['APP_ENV_VALUES']['APP_ENV'] ?? null;
    $GLOBALS['APP_ENV_VALUES']['APP_ENV'] = 'local';
    $blockedProductionDatabase = false;
    try {
        $boundaryMethod->invoke(null, 'mockups');
    } catch (RuntimeException $e) {
        $blockedProductionDatabase = str_contains($e->getMessage(), 'Se rechazo la conexion');
    } finally {
        if ($hadEnvironment) {
            $GLOBALS['APP_ENV_VALUES']['APP_ENV'] = $previousEnvironment;
        } else {
            unset($GLOBALS['APP_ENV_VALUES']['APP_ENV']);
        }
    }
    TestHarness::assertTrue(
        $blockedProductionDatabase,
        'APP_ENV=local actively rejects a production database name'
    );
}
