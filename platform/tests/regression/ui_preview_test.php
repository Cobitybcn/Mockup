<?php
declare(strict_types=1);

function run_ui_preview_regression_tests(): void
{
    $admin = [
        'id' => 1,
        'email' => 'admin@example.test',
        'is_admin' => 1,
    ];
    $artist = [
        'id' => 2,
        'email' => 'artist@example.test',
        'is_admin' => 0,
    ];

    $hadFlag = array_key_exists('UI_VISUAL_CONSISTENCY_PREVIEW', $GLOBALS['APP_ENV_VALUES']);
    $previousFlag = $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW'] ?? null;
    $hadEnvironment = array_key_exists('APP_ENV', $GLOBALS['APP_ENV_VALUES']);
    $previousEnvironment = $GLOBALS['APP_ENV_VALUES']['APP_ENV'] ?? null;
    $hadReviewers = array_key_exists('UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS', $GLOBALS['APP_ENV_VALUES']);
    $previousReviewers = $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS'] ?? null;
    $hadQuery = array_key_exists('design_preview', $_GET);
    $previousQuery = $_GET['design_preview'] ?? null;

    try {
        $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW'] = 'false';
        $_GET['design_preview'] = 'artworks-kpi';
        TestHarness::assertSame(false, UiPreview::isActive($admin, 'artworks-kpi'), 'Preview requires its master flag');

        $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW'] = 'true';
        $GLOBALS['APP_ENV_VALUES']['APP_ENV'] = 'production';
        $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS'] = 'artist@example.test';
        TestHarness::assertSame(false, UiPreview::isActive($artist, 'artworks-kpi'), 'Non-local preview is restricted to admins');

        $GLOBALS['APP_ENV_VALUES']['APP_ENV'] = 'local';
        TestHarness::assertTrue(UiPreview::isActive($artist, 'artworks-kpi'), 'Explicit local reviewer can inspect the preview');

        $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS'] = '';
        TestHarness::assertSame(false, UiPreview::isActive($artist, 'artworks-kpi'), 'Local users are not reviewers by default');

        unset($_GET['design_preview']);
        TestHarness::assertSame(false, UiPreview::isActive($admin, 'artworks-kpi'), 'Normal URL does not activate the preview');

        $_GET['design_preview'] = 'unknown-scope';
        TestHarness::assertSame(false, UiPreview::isActive($admin, 'artworks-kpi'), 'Unknown preview scopes fail closed');

        $_GET['design_preview'] = ['artworks-kpi'];
        TestHarness::assertSame(false, UiPreview::isActive($admin, 'artworks-kpi'), 'Non-string preview input fails closed');

        $_GET['design_preview'] = 'artworks-kpi';
        TestHarness::assertTrue(UiPreview::isActive($admin, 'artworks-kpi'), 'Admin can explicitly activate the allowed preview');
        TestHarness::assertSame(false, UiPreview::isActive($admin, 'unregistered'), 'Unregistered code scopes cannot activate');

        $_GET['design_preview'] = 'series-catalog';
        TestHarness::assertTrue(UiPreview::isActive($admin, 'series-catalog'), 'Series catalog is an allowed independent preview scope');
    } finally {
        if ($hadFlag) {
            $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW'] = $previousFlag;
        } else {
            unset($GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW']);
        }
        if ($hadEnvironment) {
            $GLOBALS['APP_ENV_VALUES']['APP_ENV'] = $previousEnvironment;
        } else {
            unset($GLOBALS['APP_ENV_VALUES']['APP_ENV']);
        }
        if ($hadReviewers) {
            $GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS'] = $previousReviewers;
        } else {
            unset($GLOBALS['APP_ENV_VALUES']['UI_VISUAL_CONSISTENCY_PREVIEW_REVIEWERS']);
        }
        if ($hadQuery) {
            $_GET['design_preview'] = $previousQuery;
        } else {
            unset($_GET['design_preview']);
        }
    }

    $page = file_get_contents(__DIR__ . '/../../root_album.php') ?: '';
    $seriesPage = file_get_contents(__DIR__ . '/../../series.php') ?: '';
    $styles = file_get_contents(__DIR__ . '/../../visual-consistency-preview.css') ?: '';
    TestHarness::assertContains('UiPreview::isActive', $page, 'ArtWorks page uses the guarded preview helper');
    TestHarness::assertContains('visual-consistency-preview.css', $page, 'Preview styles are loaded separately');
    TestHarness::assertContains('data-ui-preview="artworks-kpi"', $page, 'Preview styles have an explicit page scope');
    TestHarness::assertContains('.ui-visual-consistency-preview[data-ui-preview~="artworks-kpi"]', $styles, 'Every preview rule is rooted in its isolated scope');
    TestHarness::assertContains("UiPreview::isActive(\$user, 'series-catalog')", $seriesPage, 'Series uses its independent guarded preview scope');
    TestHarness::assertContains('data-ui-preview="series-catalog"', $seriesPage, 'Series renders an explicit preview scope');
    TestHarness::assertContains('.ui-visual-consistency-preview[data-ui-preview~="series-catalog"]', $styles, 'Series preview rules stay inside their isolated scope');
}
