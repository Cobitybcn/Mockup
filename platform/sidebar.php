<?php
declare(strict_types=1);

// Resolve current user and admin role
$sidebarUser = isset($user) ? $user : (isset($currentUser) ? $currentUser : Auth::user());
$sidebarIsAdmin = $sidebarUser ? Auth::isAdmin($sidebarUser) : false;
$sidebarEnvironment = strtolower(trim(app_env('APP_ENV', 'production')));
$sidebarIsLocalEnvironment = $sidebarEnvironment === 'local';
$sidebarEnvironmentDatabase = trim(app_env('DB_DATABASE', ''));

$currentPage = basename($_SERVER['PHP_SELF']);
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$currentImageParam = basename((string)($_GET['image'] ?? $_POST['image'] ?? ''));
$currentArtworkIdParam = (int)($_GET['id'] ?? $_GET['artwork_id'] ?? $_POST['id'] ?? $_POST['artwork_id'] ?? 0);
$currentMockupIdParam = (int)($_GET['mockup_id'] ?? $_POST['mockup_id'] ?? ($selectedMockupId ?? 0));
$sidebarContextArtworkId = 0;
$sidebarContextRootFile = '';
$sidebarArtistPhoto = '';

if (!function_exists('sidebar_last_scene_artwork_setting_key')) {
    function sidebar_last_scene_artwork_setting_key(int $userId): string
    {
        return 'last_scene_artwork_id_user_' . $userId;
    }
}

if (!function_exists('sidebar_artwork_context_by_id')) {
    function sidebar_artwork_context_by_id(PDO $db, int $userId, int $artworkId): ?array
    {
        if ($userId <= 0 || $artworkId <= 0) {
            return null;
        }

        $stmt = $db->prepare("
            SELECT id, root_file
            FROM artworks
            WHERE user_id = :user_id
            AND id = :id
            AND status = 'done'
            AND root_file IS NOT NULL
            AND root_file != ''
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'id' => $artworkId,
        ]);
        $artwork = $stmt->fetch();

        return $artwork ?: null;
    }
}

if (!function_exists('sidebar_remember_scene_artwork')) {
    function sidebar_remember_scene_artwork(PDO $db, int $userId, int $artworkId): void
    {
        if ($userId <= 0 || $artworkId <= 0) {
            return;
        }

        $_SESSION[sidebar_last_scene_artwork_setting_key($userId)] = $artworkId;
        $stmt = $db->prepare(Database::appSettingUpsertSql());
        $stmt->execute([
            'key' => sidebar_last_scene_artwork_setting_key($userId),
            'value' => (string)$artworkId,
            'updated_at' => date('c'),
        ]);
    }
}

if (!function_exists('sidebar_remembered_scene_artwork')) {
    function sidebar_remembered_scene_artwork(PDO $db, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $sessionArtworkId = max(0, (int)($_SESSION[sidebar_last_scene_artwork_setting_key($userId)] ?? 0));
        $sessionArtwork = sidebar_artwork_context_by_id($db, $userId, $sessionArtworkId);
        if ($sessionArtwork) {
            return $sessionArtwork;
        }

        $sql = Database::isMysql()
            ? 'SELECT value FROM app_settings WHERE `key` = :key LIMIT 1'
            : 'SELECT value FROM app_settings WHERE key = :key LIMIT 1';
        $stmt = $db->prepare($sql);
        $stmt->execute(['key' => sidebar_last_scene_artwork_setting_key($userId)]);
        $storedArtworkId = max(0, (int)$stmt->fetchColumn());
        $storedArtwork = sidebar_artwork_context_by_id($db, $userId, $storedArtworkId);
        if ($storedArtwork) {
            $_SESSION[sidebar_last_scene_artwork_setting_key($userId)] = (int)$storedArtwork['id'];
            return $storedArtwork;
        }

        return null;
    }
}

if ($sidebarUser) {
    try {
        $db = Database::connection();
        $profileStmt = $db->prepare('SELECT photo_file FROM artist_profiles WHERE user_id = :user_id LIMIT 1');
        $profileStmt->execute(['user_id' => (int)$sidebarUser['id']]);
        $sidebarArtistPhoto = basename((string)($profileStmt->fetchColumn() ?: ''));

        if ($currentArtworkIdParam > 0) {
            $stmt = $db->prepare("
                SELECT id, root_file
                FROM artworks
                WHERE user_id = :user_id
                AND id = :id
                LIMIT 1
            ");
            $stmt->execute([
                'user_id' => (int)$sidebarUser['id'],
                'id' => $currentArtworkIdParam,
            ]);
            $contextArtwork = $stmt->fetch();
        } elseif ($currentMockupIdParam > 0) {
            $mockupStmt = $db->prepare("
                SELECT id, artwork_file, source_artwork_id
                FROM mockups
                WHERE user_id = :user_id
                AND id = :id
                LIMIT 1
            ");
            $mockupStmt->execute([
                'user_id' => (int)$sidebarUser['id'],
                'id' => $currentMockupIdParam,
            ]);
            $contextMockup = $mockupStmt->fetch();
            $contextArtwork = null;

            if ($contextMockup && (int)($contextMockup['source_artwork_id'] ?? 0) > 0) {
                $stmt = $db->prepare("
                    SELECT id, root_file
                    FROM artworks
                    WHERE user_id = :user_id
                    AND id = :id
                    LIMIT 1
                ");
                $stmt->execute([
                    'user_id' => (int)$sidebarUser['id'],
                    'id' => (int)$contextMockup['source_artwork_id'],
                ]);
                $contextArtwork = $stmt->fetch();
            }

            if (!$contextArtwork && $contextMockup) {
                $mockupRootFile = basename((string)($contextMockup['artwork_file'] ?? ''));
                if ($mockupRootFile !== '') {
                    $stmt = $db->prepare("
                        SELECT id, root_file
                        FROM artworks
                        WHERE user_id = :user_id
                        AND root_file = :root_file
                        LIMIT 1
                    ");
                    $stmt->execute([
                        'user_id' => (int)$sidebarUser['id'],
                        'root_file' => $mockupRootFile,
                    ]);
                    $contextArtwork = $stmt->fetch();
                }
            }
        } elseif ($currentImageParam !== '') {
            $stmt = $db->prepare("
                SELECT id, root_file
                FROM artworks
                WHERE user_id = :user_id
                AND root_file = :root_file
                LIMIT 1
            ");
            $stmt->execute([
                'user_id' => (int)$sidebarUser['id'],
                'root_file' => $currentImageParam,
            ]);
            $contextArtwork = $stmt->fetch();
        } else {
            $contextArtwork = null;
        }

        if ($contextArtwork) {
            $sidebarContextArtworkId = (int)($contextArtwork['id'] ?? 0);
            $sidebarContextRootFile = basename((string)($contextArtwork['root_file'] ?? ''));
            sidebar_remember_scene_artwork($db, (int)$sidebarUser['id'], $sidebarContextArtworkId);
        } else {
            $rememberedArtwork = sidebar_remembered_scene_artwork($db, (int)$sidebarUser['id']);
            if ($rememberedArtwork) {
                $sidebarContextArtworkId = (int)($rememberedArtwork['id'] ?? 0);
                $sidebarContextRootFile = basename((string)($rememberedArtwork['root_file'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step active states
$createScenesActive = ($currentPage === 'create_scenes.php');
$step1Active = ($currentPage === 'create_scenes.php' || $currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step2Active = ($currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step3Active = ($currentPage === 'core_review.php');
$step4Active = ($currentPage === 'artwork.php' || $currentPage === 'publish.php' || $currentPage === 'artwork_details.php');
$step5Active = ($currentPage === 'report.php' || $currentPage === 'curated_mockups.php' || $currentPage === 'mockup_batch_wait.php' || $currentPage === 'mockup_branches_review.php' || $currentPage === 'mockup_prompt_drafts_review.php' || $currentPage === 'mockup_combinations_review.php' || $currentPage === 'mockup_combination_results.php' || $currentPage === 'mockup_variation_lab.php' || $currentPage === 'generate_mockup_combination.php' || $currentPage === 'save_mockup_combination_evaluation.php' || $currentPage === 'prompt_inspector.php' || $currentPage === 'approve_mockup_prompt_draft.php' || $currentPage === 'generate_mockup_from_approved_prompt.php');

// Menu active states
$dashboardActive = ($currentPage === 'dashboard.php');
$mockupsActive = ($currentPage === 'mockups.php' || $currentPage === 'viewer.php' || $currentPage === 'mockup_upload.php');
$worldMotherActive = ($currentPage === 'world_mother_studio.php');
$cameraStudioActive = ($currentPage === 'camera_studio.php');
$variationLabActive = ($currentPage === 'mockup_variation_lab.php');
$generatedResultsActive = ($currentPage === 'mockup_combination_results.php');
$rootAlbumActive = ($currentPage === 'root_album.php');
$seriesActive = ($currentPage === 'series.php');
$profileActive = ($currentPage === 'artist_profile.php');
$websiteActive = in_array($currentPage, ['website_board.php', 'website_catalog.php', 'website_studio_notes.php'], true);
$socialMediaCatalogActive = in_array($currentPage, ['social_media_catalog.php', 'social_media_board.php'], true);
$videosActive = ($currentPage === 'videos.php');
$videoStudioActive = ($currentPage === 'video.php');
$usersActive = ($currentPage === 'admin_users.php');
$accountActive = ($currentPage === 'account.php');
$pinterestActive = str_contains(str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? '')), '/integrations/pinterest/');
$metaActive = str_contains(str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? '')), '/integrations/meta/');
$connectionsActive = $pinterestActive || $metaActive;

// Admin active states
$promptsActive = ($currentPage === 'admin_prompts.php');
$apiActive = ($currentPage === 'admin_api_keys.php');
$studioReferencesLabEnabled = filter_var(app_env('STUDIO_REFERENCES_LAB_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN);
$studioReferencesLabActive = $studioReferencesLabEnabled && ($currentPage === 'studio_references_lab.php');
$sidebarCanUseWebsite = $sidebarUser ? FeatureAccess::allows($sidebarUser, FeatureAccess::WEBSITE_MANAGE) : false;
$sidebarCanUseSocial = $sidebarUser ? FeatureAccess::allows($sidebarUser, FeatureAccess::SOCIAL_MANAGE) : false;
$sidebarCanUseVideo = $sidebarUser ? FeatureAccess::allows($sidebarUser, FeatureAccess::VIDEO_MANAGE) : false;
$sidebarUsesCompactBasicNavigation = $sidebarUser
    && !$sidebarIsAdmin
    && FeatureAccess::planForUser($sidebarUser) === FeatureAccess::PLAN_ARTIST_STUDIO;
$sidebarWebsiteUrl = $sidebarCanUseWebsite ? 'website_board.php' : 'account.php?upgrade=artist_pro&feature=website#plan';
$sidebarSocialUrl = $sidebarCanUseSocial ? 'social_media_board.php' : 'account.php?upgrade=artist_pro&feature=social#plan';
$sidebarVideosUrl = $sidebarCanUseVideo ? 'videos.php' : 'account.php?upgrade=artist_pro&feature=video#plan';

// Step 2 link determination (Select Root Artwork)
$step2Url = '#';
$step2Disabled = true;

if ($currentPage === 'root_select.php' || $currentPage === 'waiting.php') {
    $jobId = basename((string)($_GET['job'] ?? ''));
    if ($jobId !== '') {
        $step2Url = 'root_select.php?job=' . urlencode($jobId);
        $step2Disabled = false;
    }
}

if ($step2Disabled && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT job_id FROM artworks WHERE user_id = :user_id AND status = 'awaiting_selection' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['user_id' => $sidebarUser['id']]);
        $latestPending = $stmt->fetch();
        if ($latestPending) {
            $step2Url = 'root_select.php?job=' . urlencode((string)$latestPending['job_id']);
            $step2Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step 4 link determination (Artwork Details)
$step4Url = '#';
$step4Disabled = true;

if ($sidebarContextArtworkId > 0) {
    $step4Url = 'artwork_details.php?id=' . urlencode((string)$sidebarContextArtworkId);
    $step4Disabled = false;
} elseif (($currentPage === 'artwork.php' || $currentPage === 'publish.php' || $currentPage === 'artwork_details.php') && $currentArtworkIdParam > 0) {
    $step4Url = 'artwork_details.php?id=' . urlencode((string)$currentArtworkIdParam);
    $step4Disabled = false;
}

if ($step4Disabled && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("
            SELECT id
            FROM artworks
            WHERE user_id = :user_id
            AND status = 'done'
            AND root_file IS NOT NULL
            AND root_file != ''
            ORDER BY updated_at DESC, created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $sidebarUser['id']]);
        $latestArtworkId = $stmt->fetchColumn();
        if ($latestArtworkId) {
            $step4Url = 'artwork_details.php?id=' . urlencode((string)$latestArtworkId);
            $step4Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step 5 link determination (Mockup Combinations)
$step5Url = '#';
$step5Disabled = true;

if ($sidebarContextArtworkId > 0) {
    $step5Url = 'mockup_combinations_review.php?id=' . urlencode((string)$sidebarContextArtworkId);
    $step5Disabled = false;
} elseif ($sidebarContextRootFile !== '') {
    $step5Url = 'curated_mockups.php?image=' . urlencode($sidebarContextRootFile);
    $step5Disabled = false;
} elseif ($sidebarContextArtworkId > 0) {
    $step5Url = 'curated_mockups.php?id=' . urlencode((string)$sidebarContextArtworkId);
    $step5Disabled = false;
} elseif (($currentPage === 'report.php' || $currentPage === 'curated_mockups.php' || $currentPage === 'mockup_batch_wait.php') && $currentImageParam !== '') {
    if ($currentArtworkIdParam > 0) {
        $step5Url = 'mockup_combinations_review.php?id=' . urlencode((string)$currentArtworkIdParam);
    } else {
        $step5Url = 'curated_mockups.php?image=' . urlencode($currentImageParam);
    }
    $step5Disabled = false;
}

if ($step5Disabled && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT id, root_file FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['user_id' => $sidebarUser['id']]);
        $latestArtwork = $stmt->fetch();
        if ($latestArtwork && !empty($latestArtwork['root_file'])) {
            if ($sidebarContextArtworkId <= 0) {
                $sidebarContextArtworkId = (int)$latestArtwork['id'];
                $sidebarContextRootFile = basename((string)$latestArtwork['root_file']);
            }
            $step5Url = 'mockup_combinations_review.php?id=' . urlencode((string)$latestArtwork['id']);
            $step5Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

if (!$sidebarIsAdmin) {
    $step5Url = 'create_scenes.php';
    $step5Disabled = false;
}

$rootAlbumUrl = 'root_album.php';
$videoStudioUrl = $sidebarContextArtworkId > 0
    ? 'video.php?artwork_id=' . urlencode((string)$sidebarContextArtworkId)
    : 'video.php';
$sidebarVideoStudioUrl = $sidebarCanUseVideo
    ? $videoStudioUrl
    : 'account.php?upgrade=artist_pro&feature=video#plan';

$generatedResultsUrl = $sidebarContextArtworkId > 0
    ? 'mockup_combination_results.php?id=' . urlencode((string)$sidebarContextArtworkId)
    : 'mockups.php';

$variationLabUrl = 'mockup_variation_lab.php';
if ($generatedResultsActive && $sidebarContextArtworkId > 0) {
    // Entering from Art Mockups must not pin an older card. Let Mockup Lab
    // resolve the newest eligible mockup for this artwork itself.
    $variationLabUrl .= '?id=' . urlencode((string)$sidebarContextArtworkId);
} elseif ($currentMockupIdParam > 0) {
    $variationLabUrl .= '?' . ($sidebarContextArtworkId > 0 ? 'id=' . urlencode((string)$sidebarContextArtworkId) . '&' : '') . 'mockup_id=' . urlencode((string)$currentMockupIdParam);
} elseif (($sidebarContextArtworkId > 0 || $sidebarContextRootFile !== '') && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("
            SELECT id
            FROM mockups
            WHERE user_id = :user_id
            AND (
                source_artwork_id = :source_artwork_id
                OR artwork_file = :artwork_file
            )
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => (int)$sidebarUser['id'],
            'source_artwork_id' => $sidebarContextArtworkId,
            'artwork_file' => $sidebarContextRootFile,
        ]);
        $contextMockupId = (int)$stmt->fetchColumn();
        if ($contextMockupId > 0) {
            $variationLabUrl .= '?' . ($sidebarContextArtworkId > 0 ? 'id=' . urlencode((string)$sidebarContextArtworkId) . '&' : '') . 'mockup_id=' . urlencode((string)$contextMockupId);
        } elseif ($sidebarContextArtworkId > 0) {
            $variationLabUrl .= '?id=' . urlencode((string)$sidebarContextArtworkId);
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

?>
<script>
(function () {
    if (document.head.querySelector('link[data-artwork-mockups-favicon]')) return;
    var favicon = document.createElement('link');
    favicon.rel = 'icon';
    favicon.type = 'image/svg+xml';
    favicon.href = 'favicon.svg?v=1';
    favicon.dataset.artworkMockupsFavicon = '1';
    document.head.appendChild(favicon);
})();
</script>
<?php if ($sidebarIsAdmin): ?>
<script>
(function () {
    document.body.dataset.sidebarFlowMode = localStorage.getItem('sidebarFlowMode') === 'normal' ? 'normal' : 'admin';
})();
</script>
<?php endif; ?>
<?php if (!$sidebarIsAdmin): ?>
<style>
@media (max-width: 760px) {
    .main-area > .app-header {
        display: none !important;
    }
}
</style>
<?php endif; ?>
<style>
    .app-environment-badge {
        position: fixed;
        left: 12px;
        bottom: 12px;
        z-index: 2147482000;
        max-width: calc(100vw - 24px);
        padding: 6px 10px;
        overflow: hidden;
        border: 1px solid rgba(157, 119, 62, .28);
        border-radius: 999px;
        background: rgba(244, 234, 215, .96);
        box-shadow: 0 8px 22px rgba(55, 42, 25, .10);
        color: #6f5631;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .08em;
        line-height: 1.25;
        text-overflow: ellipsis;
        white-space: nowrap;
        pointer-events: none;
    }
    .sidebar-tab.is-locked::after,
    .sidebar-mobile-section a.is-locked::after {
        content: 'PRO';
        display: inline-block;
        margin-left: 6px;
        padding: 2px 5px;
        border: 1px solid rgba(183, 127, 134, .32);
        border-radius: 999px;
        background: rgba(235, 211, 214, .38);
        color: #8d6268;
        font-size: 8px;
        font-weight: 800;
        letter-spacing: .08em;
        vertical-align: middle;
    }
    .sidebar-mobile-menu {
        display: block;
        position: fixed;
        top: calc(env(safe-area-inset-top, 0px) + 9px);
        right: 12px;
        width: 46px;
        height: 38px;
        z-index: 2147483600;
        pointer-events: auto;
    }
    .sidebar-mobile-menu[open] {
        width: auto;
        height: auto;
    }
    .sidebar-mobile-menu summary {
        width: 46px;
        height: 38px;
        display: inline-flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 5px;
        border: 1px solid rgba(183, 127, 134, 0.42);
        border-radius: 4px;
        background: rgba(255, 250, 247, 0.96);
        box-shadow: 0 10px 24px rgba(28, 23, 20, 0.10);
        list-style: none;
        cursor: pointer;
    }
    .sidebar-mobile-menu summary::-webkit-details-marker {
        display: none;
    }
    .sidebar-mobile-menu summary span {
        width: 22px;
        height: 2px;
        border-radius: 999px;
        background: #b77f86;
    }
    .sidebar-mobile-menu[open] summary {
        background: #fffaf7;
    }
    .sidebar-mobile-menu[open] summary span {
        background: #9c6870;
    }
    .sidebar-mobile-panel {
        position: fixed;
        top: calc(env(safe-area-inset-top, 0px) + 54px);
        right: 18px;
        left: auto;
        bottom: auto;
        width: min(320px, calc(100vw - 36px));
        z-index: 2147482999;
        max-height: min(72vh, 560px);
        overflow-y: auto;
        padding: 12px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: rgba(255, 250, 247, 0.98);
        box-shadow: 0 18px 48px rgba(34, 28, 20, 0.18);
    }
    .sidebar-mobile-section {
        display: grid;
        gap: 4px;
        padding: 8px 0;
        border-bottom: 1px solid var(--line);
    }
    .sidebar-mobile-section:last-child {
        border-bottom: 0;
    }
    .sidebar-mobile-section > span {
        padding: 4px 8px 6px;
        color: var(--muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.1em;
        text-transform: uppercase;
    }
    .sidebar-mobile-section a {
        min-height: 42px;
        display: flex;
        align-items: center;
        padding: 0 10px;
        border-radius: 6px;
        color: var(--ink);
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
    }
    .sidebar-mobile-section a.active {
        background: rgba(183, 127, 134, 0.14);
        color: #9c6870;
    }
    .sidebar-mobile-section.sidebar-studios-mobile {
        border-top: 3px double var(--line);
    }
    .sidebar-mobile-section.sidebar-publishing-mobile {
        border-top: 3px double var(--line);
        background: #E6F2E7;
    }
    .sidebar-library-divider {
        align-self: stretch;
        width: 0;
        min-height: 32px;
        margin: 0 0 0 14px;
        border-left: 3px double var(--line);
    }
    .sidebar-studios {
        flex: 0 0 auto;
        display: flex;
        align-items: stretch;
        padding-left: 16px;
        border-left: 3px double var(--line);
    }
    .sidebar-submenu {
        display: flex;
        align-items: stretch;
    }

    .sidebar-submenu-panel {
        display: none;
        position: fixed;
        z-index: 2147482500;
        min-width: 168px;
        padding: 8px;
        border: 1px solid var(--line);
        border-radius: 6px;
        background: rgba(255, 250, 247, 0.98);
        box-shadow: 0 18px 42px rgba(34, 28, 20, 0.16);
    }
    .sidebar-submenu-panel.is-open {
        display: grid;
        gap: 4px;
    }
    .sidebar-submenu-panel a {
        min-height: 34px;
        display: flex;
        align-items: center;
        padding: 0 12px;
        color: var(--muted);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-decoration: none;
        white-space: nowrap;
    }
    .sidebar-submenu-panel a.active,
    .sidebar-submenu-panel a:hover {
        color: var(--accent);
        background: rgba(168, 129, 86, 0.08);
    }
    .sidebar-mobile-flow-selector {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 4px;
        padding: 0 8px 4px;
    }
    .sidebar-mobile-flow-selector button {
        min-height: 38px;
        padding: 0 12px;
        border: 1px solid var(--line);
        border-radius: 6px;
        background: var(--surface-soft);
        color: var(--muted);
        font: inherit;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
    }
    .sidebar-mobile-flow-selector button.is-active {
        border-color: var(--accent);
        background: rgba(183, 127, 134, 0.14);
        color: var(--accent);
    }
    .sidebar-mode-switch-wrap {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 16px;
        border-left: 1px solid var(--line);
        border-right: 1px solid var(--line);
    }
    .sidebar-mode-switch {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 4px 7px 4px 10px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: var(--surface);
        color: var(--muted);
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(20, 20, 18, 0.04);
    }
    .sidebar-mode-switch input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .sidebar-mode-switch-track {
        position: relative;
        width: 46px;
        height: 24px;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: var(--surface-soft);
        transition: background 0.2s, border-color 0.2s;
    }
    .sidebar-mode-switch-track::after {
        content: "";
        position: absolute;
        top: 3px;
        left: 3px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        background: var(--surface);
        box-shadow: 0 2px 6px rgba(20, 20, 18, 0.18);
        transition: transform 0.2s;
    }
    .sidebar-mode-switch input:checked + .sidebar-mode-switch-track {
        background: var(--accent);
        border-color: var(--accent);
    }
    .sidebar-mode-switch input:checked + .sidebar-mode-switch-track::after {
        transform: translateX(22px);
    }
    body[data-sidebar-flow-mode="normal"] .admin-flow-only {
        display: none !important;
    }
    body[data-sidebar-flow-mode="admin"] .normal-flow-only {
        display: none !important;
    }
    @media (min-width: 981px) {
        .app-shell {
            display: grid !important;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
        }
        .sidebar {
            display: flex !important;
            visibility: visible !important;
            min-height: 58px;
            height: auto;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .sidebar-head,
        .sidebar-tabs,
        .sidebar-tab-group,
        .sidebar-tab-row,
        .sidebar-mode-switch-wrap,
        .sidebar-context,
        .sidebar-studios,
        .sidebar-account {
            display: flex !important;
            visibility: visible !important;
        }
        .sidebar-head .brand-title {
            display: flex !important;
            font-size: 21px;
            letter-spacing: 0.18em;
            transform: scaleY(1.18);
        }
        .sidebar-head .brand-mark {
            display: inline-block !important;
        }
        .sidebar-mobile-menu {
            display: none !important;
        }
        .sidebar-studios {
            padding-right: 84px;
        }
    }
    @media (max-width: 980px) {
        .sidebar-head {
            min-height: 56px;
            padding: 8px 76px 8px 18px;
            justify-content: flex-start;
            position: relative;
        }
        .sidebar-head .brand {
            width: auto;
            align-items: center;
            text-align: left;
        }
        .sidebar-head .brand-title {
            display: flex;
            font-size: 21px;
            letter-spacing: 0.07em;
            gap: 8px;
            transform: none;
        }
        .sidebar-head .brand-mark {
            display: inline-block;
            width: 13px;
            height: 13px;
            border: 2px solid #b77f86;
            transform: none;
        }
        .sidebar > .sidebar-mobile-menu:not(.sidebar-mobile-menu-head) {
            display: none !important;
        }
        .sidebar-mode-switch-wrap {
            display: flex !important;
            align-self: stretch;
            border-left: none;
            border-right: none;
            padding: 10px 12px 6px;
        }
        .sidebar-mobile-menu-head {
            display: block !important;
            position: absolute !important;
            top: 50% !important;
            right: 12px !important;
            transform: translateY(-50%) !important;
            width: 46px !important;
            height: 38px !important;
            z-index: 50 !important;
        }
    }
    @media (max-width: 760px) {
        .sidebar-studios {
            display: none !important;
        }
    }
</style>
<aside class="sidebar">
    <div class="sidebar-head">
        <a class="brand" href="create_scenes.php" data-admin-href="create_scenes.php" data-normal-href="create_scenes.php">
            <span class="brand-kicker">ArtworkMockups.com</span>
            <span class="brand-title">ARTWORK MOCKUPS <span class="brand-mark"></span></span>
        </a>
        <details class="sidebar-mobile-menu sidebar-mobile-menu-head">
            <summary aria-label="Open menu"><span></span><span></span><span></span></summary>
            <div class="sidebar-mobile-panel">
                <?php if ($sidebarIsAdmin): ?>
                    <div class="sidebar-mobile-section">
                        <span>View mode</span>
                        <div class="sidebar-mobile-flow-selector" role="group" aria-label="Cambiar entre vista normal y admin">
                            <button type="button" data-sidebar-flow-mode-option="normal">Normal</button>
                            <button type="button" data-sidebar-flow-mode-option="admin">Admin</button>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="sidebar-mobile-section">
                    <span>Create</span>
                    <a class="normal-flow-only <?= $createScenesActive ? 'active' : '' ?>" href="create_scenes.php">Create Art</a>
                    <?php if ($sidebarIsAdmin): ?>
                        <a class="admin-flow-only <?= $step1Active ? 'active' : '' ?>" href="create_scenes.php">Create Art</a>
                    <?php endif; ?>
                    <a class="<?= $generatedResultsActive ? 'active' : '' ?>" href="<?= htmlspecialchars($generatedResultsUrl, ENT_QUOTES, 'UTF-8') ?>">Art Mockups</a>
                    <a class="<?= $variationLabActive ? 'active' : '' ?>" href="<?= htmlspecialchars($variationLabUrl, ENT_QUOTES, 'UTF-8') ?>">Mockup Lab</a>
                </div>
                <div class="sidebar-mobile-section">
                    <span>Library</span>
                    <a class="<?= $seriesActive ? 'active' : '' ?>" href="series.php">Series</a>
                    <a class="<?= $rootAlbumActive ? 'active' : '' ?>" href="<?= htmlspecialchars($rootAlbumUrl, ENT_QUOTES, 'UTF-8') ?>">ArtWorks</a>
                    <a class="<?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">Mockup Album</a>
                    <?php if ($sidebarCanUseVideo): ?>
                        <a class="<?= $videosActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideosUrl, ENT_QUOTES, 'UTF-8') ?>">Videos</a>
                    <?php endif; ?>
                </div>
                <div class="sidebar-mobile-section sidebar-publishing-mobile">
                    <span>Publish</span>
                    <?php if ($sidebarCanUseWebsite): ?>
                        <a class="<?= $websiteActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarWebsiteUrl, ENT_QUOTES, 'UTF-8') ?>">Website Catalog Sync</a>
                    <?php endif; ?>
                    <?php if ($sidebarCanUseSocial): ?>
                        <a class="<?= $socialMediaCatalogActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarSocialUrl, ENT_QUOTES, 'UTF-8') ?>">Social Media Board</a>
                    <?php endif; ?>
                    <a class="<?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">Artist Profile</a>
                </div>
                <?php if ($sidebarIsAdmin || $sidebarCanUseVideo): ?>
                    <div class="sidebar-mobile-section sidebar-studios-mobile">
                        <span>Studios</span>
                        <?php if ($sidebarIsAdmin): ?>
                            <a class="<?= $worldMotherActive ? 'active' : '' ?>" href="world_mother_studio.php">Scene Estudio</a>
                            <a class="<?= $cameraStudioActive ? 'active' : '' ?>" href="camera_studio.php">Camera Boards</a>
                        <?php endif; ?>
                        <a class="<?= $videoStudioActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideoStudioUrl, ENT_QUOTES, 'UTF-8') ?>">Video Lab</a>
                    </div>
                <?php endif; ?>
                <?php if ($sidebarIsAdmin): ?>
                    <div class="sidebar-mobile-section">
                        <span>Admin</span>
                        <a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a>
                        <a class="<?= $usersActive ? 'active' : '' ?>" href="admin_users.php">Users & Credits</a>
                        <a class="<?= $promptsActive ? 'active' : '' ?>" href="admin_prompts.php">Prompts</a>
                        <a class="<?= $apiActive ? 'active' : '' ?>" href="admin_api_keys.php">API Settings</a>
                        <?php if ($studioReferencesLabEnabled): ?>
                            <a class="<?= $studioReferencesLabActive ? 'active' : '' ?>" href="studio_references_lab.php">Visual DNA</a>
                        <?php endif; ?>
                        <?php if ($sidebarCanUseSocial): ?>
                            <a class="<?= $connectionsActive ? 'active' : '' ?>" href="integrations/pinterest/">Pinterest & Meta Connections</a>
                        <?php endif; ?>
                        <a href="logout.php">Logout</a>
                    </div>
                <?php else: ?>
                    <div class="sidebar-mobile-section">
                        <span>Admin</span>
                        <a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a>
                        <?php if ($sidebarCanUseSocial): ?>
                            <a class="<?= $connectionsActive ? 'active' : '' ?>" href="integrations/pinterest/">Pinterest & Meta Connections</a>
                        <?php endif; ?>
                        <a href="logout.php">Logout</a>
                    </div>
                <?php endif; ?>
            </div>
        </details>
    </div>

    <nav class="sidebar-tabs" aria-label="Primary navigation">
        <section class="sidebar-tab-group">
            <div class="sidebar-tab-row">
                <a class="sidebar-tab normal-flow-only <?= $createScenesActive ? 'active' : '' ?>" href="create_scenes.php">
                    <svg class="sidebar-tab-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7h3l1.4-2h7.2L17 7h3v12H4V7Z"/>
                        <circle cx="12" cy="13" r="4" stroke-width="1.5"/>
                    </svg>
                    <span>Create Art</span>
                </a>
                <?php if ($sidebarIsAdmin): ?>
                    <a class="sidebar-tab mobile-hide-upload admin-flow-only <?= $step1Active ? 'active' : '' ?>" href="create_scenes.php">Create Art</a>
                <?php endif; ?>
                <?php if ($sidebarIsAdmin && (!$step5Disabled || $step5Active)): ?>
                    <a class="sidebar-tab admin-flow-only <?= $step5Active && !$variationLabActive && !$generatedResultsActive ? 'active' : '' ?>" href="<?= htmlspecialchars($step5Url, ENT_QUOTES, 'UTF-8') ?>">Scenes</a>
                <?php endif; ?>
                <a class="sidebar-tab <?= $generatedResultsActive ? 'active' : '' ?>" href="<?= htmlspecialchars($generatedResultsUrl, ENT_QUOTES, 'UTF-8') ?>">Art Mockups</a>
                <a class="sidebar-tab <?= $variationLabActive ? 'active' : '' ?>" href="<?= htmlspecialchars($variationLabUrl, ENT_QUOTES, 'UTF-8') ?>">Mockup Lab</a>
            </div>
        </section>

        <?php if ($sidebarUsesCompactBasicNavigation): ?>
            <section class="sidebar-tab-group sidebar-basic-library" aria-label="Library">
                <div class="sidebar-tab-row">
                    <a class="sidebar-tab <?= $seriesActive ? 'active' : '' ?>" href="series.php">Series</a>
                    <a class="sidebar-tab <?= $rootAlbumActive ? 'active' : '' ?>" href="<?= htmlspecialchars($rootAlbumUrl, ENT_QUOTES, 'UTF-8') ?>">ArtWorks</a>
                    <a class="sidebar-tab <?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">Mockup Album</a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($sidebarIsAdmin): ?>
            <section class="sidebar-mode-switch-wrap" aria-label="Flow view selector">
                <label class="sidebar-mode-switch" for="sidebar-flow-mode-toggle">
                    <span id="sidebar-flow-mode-label">Admin</span>
                    <input type="checkbox" id="sidebar-flow-mode-toggle" aria-label="Cambiar entre flujo usuario normal y admin">
                    <span class="sidebar-mode-switch-track" aria-hidden="true"></span>
                </label>
            </section>
        <?php endif; ?>

        <?php if (!$sidebarUsesCompactBasicNavigation): ?>
            <section class="sidebar-context">
                <div class="sidebar-tab-row">
                    <a class="sidebar-tab <?= $seriesActive ? 'active' : '' ?>" href="series.php">Series</a>
                    <a class="sidebar-tab <?= $rootAlbumActive ? 'active' : '' ?>" href="<?= htmlspecialchars($rootAlbumUrl, ENT_QUOTES, 'UTF-8') ?>">ArtWorks</a>
                    <a class="sidebar-tab <?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">Mockup Album</a>
                    <?php if ($sidebarCanUseVideo): ?>
                        <a class="sidebar-tab <?= $videosActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideosUrl, ENT_QUOTES, 'UTF-8') ?>">Videos</a>
                    <?php endif; ?>
                    <span class="sidebar-library-divider" aria-hidden="true"></span>
                    <div class="sidebar-publishing-tabs">
                        <?php if ($sidebarCanUseWebsite): ?>
                            <a class="sidebar-tab <?= $websiteActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarWebsiteUrl, ENT_QUOTES, 'UTF-8') ?>">Website Catalog Sync</a>
                        <?php endif; ?>
                        <?php if ($sidebarCanUseSocial): ?>
                            <a class="sidebar-tab <?= $socialMediaCatalogActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarSocialUrl, ENT_QUOTES, 'UTF-8') ?>">Social Media Board</a>
                        <?php endif; ?>
                        <a class="sidebar-tab <?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">Artist Profile</a>
                    </div>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($sidebarIsAdmin || $sidebarCanUseVideo): ?>
            <section class="sidebar-studios" aria-label="Studios">
                <div class="sidebar-tab-row">
                    <?php if ($sidebarIsAdmin): ?>
                        <a class="sidebar-tab <?= $worldMotherActive ? 'active' : '' ?>" href="world_mother_studio.php">Scene Estudio</a>
                        <a class="sidebar-tab <?= $cameraStudioActive ? 'active' : '' ?>" href="camera_studio.php">Camera Boards</a>
                    <?php endif; ?>
                    <a class="sidebar-tab <?= $videoStudioActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideoStudioUrl, ENT_QUOTES, 'UTF-8') ?>">Video Lab</a>
                </div>
            </section>
        <?php endif; ?>

    </nav>

    <?php if ($sidebarUsesCompactBasicNavigation): ?>
        <section class="sidebar-account sidebar-basic-profile" aria-label="Artist account">
            <div class="sidebar-tab-row">
                <a class="sidebar-tab <?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">Artist Profile</a>
            </div>
        </section>
    <?php endif; ?>

    <details class="sidebar-more">
        <summary>Admin</summary>
        <ul class="nav">
            <li><a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a></li>
            <?php if ($sidebarIsAdmin): ?>
                <li><a class="<?= $usersActive ? 'active' : '' ?>" href="admin_users.php">Users & Credits</a></li>
                <li><a class="<?= $promptsActive ? 'active' : '' ?>" href="admin_prompts.php">Prompts</a></li>
                <li><a class="<?= $apiActive ? 'active' : '' ?>" href="admin_api_keys.php">API Settings</a></li>
                <?php if ($studioReferencesLabEnabled): ?>
                    <li><a class="<?= $studioReferencesLabActive ? 'active' : '' ?>" href="studio_references_lab.php">Visual DNA</a></li>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($sidebarCanUseSocial): ?>
                <li><a class="<?= $connectionsActive ? 'active' : '' ?>" href="integrations/pinterest/">Pinterest & Meta Connections</a></li>
            <?php endif; ?>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </details>

    <details class="sidebar-mobile-menu">
        <summary aria-label="Open menu"><span></span><span></span><span></span></summary>
        <div class="sidebar-mobile-panel">
            <div class="sidebar-mobile-section">
                <span>Library</span>
                <a class="<?= $seriesActive ? 'active' : '' ?>" href="series.php">Series</a>
                <a class="<?= $rootAlbumActive ? 'active' : '' ?>" href="<?= htmlspecialchars($rootAlbumUrl, ENT_QUOTES, 'UTF-8') ?>">ArtWorks</a>
                <a class="<?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">Mockup Album</a>
                <?php if ($sidebarCanUseVideo): ?>
                    <a class="<?= $videosActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideosUrl, ENT_QUOTES, 'UTF-8') ?>">Videos</a>
                <?php endif; ?>
            </div>
            <div class="sidebar-mobile-section sidebar-publishing-mobile">
                <span>Publish</span>
                <?php if ($sidebarCanUseWebsite): ?>
                    <a class="<?= $websiteActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarWebsiteUrl, ENT_QUOTES, 'UTF-8') ?>">Website Catalog Sync</a>
                <?php endif; ?>
                <?php if ($sidebarCanUseSocial): ?>
                    <a class="<?= $socialMediaCatalogActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarSocialUrl, ENT_QUOTES, 'UTF-8') ?>">Social Media Board</a>
                <?php endif; ?>
                <a class="<?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">Artist Profile</a>
            </div>
            <?php if ($sidebarIsAdmin || $sidebarCanUseVideo): ?>
                <div class="sidebar-mobile-section sidebar-studios-mobile">
                    <span>Studios</span>
                    <?php if ($sidebarIsAdmin): ?>
                        <a class="<?= $worldMotherActive ? 'active' : '' ?>" href="world_mother_studio.php">Scene Estudio</a>
                        <a class="<?= $cameraStudioActive ? 'active' : '' ?>" href="camera_studio.php">Camera Boards</a>
                    <?php endif; ?>
                    <a class="<?= $videoStudioActive ? 'active' : '' ?>" href="<?= htmlspecialchars($sidebarVideoStudioUrl, ENT_QUOTES, 'UTF-8') ?>">Video Lab</a>
                </div>
            <?php endif; ?>
            <?php if ($sidebarIsAdmin): ?>
                <div class="sidebar-mobile-section">
                    <span>Admin</span>
                    <a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a>
                    <a class="<?= $usersActive ? 'active' : '' ?>" href="admin_users.php">Users & Credits</a>
                    <a class="<?= $promptsActive ? 'active' : '' ?>" href="admin_prompts.php">Prompts</a>
                    <a class="<?= $apiActive ? 'active' : '' ?>" href="admin_api_keys.php">API Settings</a>
                    <?php if ($studioReferencesLabEnabled): ?>
                        <a class="<?= $studioReferencesLabActive ? 'active' : '' ?>" href="studio_references_lab.php">Visual DNA</a>
                    <?php endif; ?>
                    <?php if ($sidebarCanUseSocial): ?>
                        <a class="<?= $connectionsActive ? 'active' : '' ?>" href="integrations/pinterest/">Pinterest & Meta Connections</a>
                    <?php endif; ?>
                    <a href="logout.php">Logout</a>
                </div>
            <?php else: ?>
                <div class="sidebar-mobile-section">
                    <span>Admin</span>
                    <a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a>
                    <?php if ($sidebarCanUseSocial): ?>
                        <a class="<?= $connectionsActive ? 'active' : '' ?>" href="integrations/pinterest/">Pinterest & Meta Connections</a>
                    <?php endif; ?>
                    <a href="logout.php">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </details>
    <?php if ($sidebarIsLocalEnvironment): ?>
        <div class="app-environment-badge" role="status" aria-label="Entorno local">
            LOCAL · <?= htmlspecialchars($sidebarEnvironmentDatabase, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</aside>
<?php if ($sidebarUser): ?>
<style>
    .global-generation-activity,
    .global-generation-ready {
        position: fixed;
        right: 88px;
        bottom: 24px;
        z-index: 1450;
        border: 1px solid #c8d7c3;
        border-radius: 8px;
        background: #e4eee1;
        color: #3f593c;
        box-shadow: 0 14px 38px rgba(38, 45, 35, .16);
        font-family: var(--font-sans, Arial, sans-serif);
    }
    .global-generation-activity[hidden],
    .global-generation-ready[hidden] { display: none !important; }
    .global-generation-activity {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 48px;
        padding: 10px 15px;
        font-size: 12px;
    }
    .global-generation-sound {
        display: grid;
        place-items: center;
        width: 28px;
        height: 28px;
        flex: 0 0 auto;
        padding: 0;
        border: 1px solid rgba(111, 139, 104, .3);
        border-radius: 50%;
        background: rgba(255, 255, 255, .36);
        color: #657061;
        cursor: pointer;
    }
    .global-generation-sound:hover { background: rgba(255, 255, 255, .68); }
    .global-generation-sound svg {
        width: 15px;
        height: 15px;
        fill: none;
        stroke: currentColor;
        stroke-linecap: round;
        stroke-linejoin: round;
        stroke-width: 1.8;
    }
    .global-generation-sound [data-sound-slash] { display: none; }
    .global-generation-sound[aria-pressed="true"] [data-sound-wave] { display: none; }
    .global-generation-sound[aria-pressed="true"] [data-sound-slash] { display: block; }
    .global-generation-spinner {
        width: 18px;
        height: 18px;
        flex: 0 0 auto;
        border: 2px solid rgba(63, 89, 60, .2);
        border-top-color: #6f8b68;
        border-radius: 50%;
        animation: global-generation-spin .9s linear infinite;
    }
    @keyframes global-generation-spin { to { transform: rotate(360deg); } }
    .global-generation-ready {
        display: grid;
        grid-template-columns: auto 1fr auto auto auto;
        align-items: center;
        gap: 12px;
        width: min(470px, calc(100vw - 32px));
        padding: 14px 15px;
        background: #f1f6ef;
    }
    .global-generation-ready-mark {
        display: grid;
        place-items: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #d5e5d0;
        font-size: 17px;
    }
    .global-generation-ready strong { display: block; font-size: 13px; }
    .global-generation-ready span { display: block; margin-top: 2px; color: #657061; font-size: 11px; }
    .global-generation-ready a {
        min-height: 38px;
        padding: 11px 15px;
        border: 1px solid #a9bfa3;
        border-radius: 5px;
        background: #dcead8;
        color: #3f593c;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .06em;
        text-decoration: none;
        text-transform: uppercase;
        white-space: nowrap;
    }
    .global-generation-ready button {
        width: 30px;
        height: 30px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #657061;
        font-size: 20px;
        cursor: pointer;
    }
    .global-generation-ready.is-error { border-color: #dfbbb5; background: #f8e9e6; color: #8e4b43; }
    @media (max-width: 760px) {
        .global-generation-activity { right: 16px; bottom: 76px; }
        .global-generation-ready {
            right: 16px;
            bottom: 76px;
            grid-template-columns: auto 1fr auto auto;
        }
        .global-generation-ready a { grid-column: 1 / -1; text-align: center; }
    }
</style>
<div class="global-generation-activity" data-global-generation-activity role="status" aria-live="polite" hidden>
    <span class="global-generation-spinner" aria-hidden="true"></span>
    <span data-global-generation-active-text>Creating mockups in the background…</span>
    <button class="global-generation-sound" type="button" data-global-generation-sound aria-pressed="false" aria-label="Mute completion sounds" title="Mute completion sounds">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 10v4h3l4 3V7l-4 3H4Z"></path>
            <path data-sound-wave d="M15 9.5c1.3 1.3 1.3 3.7 0 5"></path>
            <path data-sound-wave d="M18 7c2.8 2.8 2.8 7.2 0 10"></path>
            <path data-sound-slash d="M5 5l14 14"></path>
        </svg>
    </button>
</div>
<div class="global-generation-ready" data-global-generation-ready role="status" aria-live="polite" hidden>
    <span class="global-generation-ready-mark" aria-hidden="true">✓</span>
    <div>
        <strong data-global-generation-ready-title>Mockups ready</strong>
        <span data-global-generation-ready-text>Your results are available.</span>
    </div>
    <a href="mockups.php" data-global-generation-ready-link>View results</a>
    <button class="global-generation-sound" type="button" data-global-generation-sound aria-pressed="false" aria-label="Mute completion sounds" title="Mute completion sounds">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M4 10v4h3l4 3V7l-4 3H4Z"></path>
            <path data-sound-wave d="M15 9.5c1.3 1.3 1.3 3.7 0 5"></path>
            <path data-sound-wave d="M18 7c2.8 2.8 2.8 7.2 0 10"></path>
            <path data-sound-slash d="M5 5l14 14"></path>
        </svg>
    </button>
    <button type="button" data-global-generation-dismiss aria-label="Dismiss">×</button>
</div>
<script>
(function () {
    const userKey = <?= json_encode((string)$sidebarUser['id']) ?>;
    const trackedKey = 'artworkMockupsGenerationTracked:' + userKey;
    const pendingKey = 'artworkMockupsGenerationNotices:' + userKey;
    const soundPlayedKey = 'artworkMockupsGenerationSoundsPlayed:' + userKey;
    const soundMutedKey = 'artworkMockupsGenerationSoundMuted:' + userKey;
    const activity = document.querySelector('[data-global-generation-activity]');
    const activeText = document.querySelector('[data-global-generation-active-text]');
    const ready = document.querySelector('[data-global-generation-ready]');
    const readyTitle = document.querySelector('[data-global-generation-ready-title]');
    const readyText = document.querySelector('[data-global-generation-ready-text]');
    const readyLink = document.querySelector('[data-global-generation-ready-link]');
    const dismiss = document.querySelector('[data-global-generation-dismiss]');
    const soundToggles = Array.from(document.querySelectorAll('[data-global-generation-sound]'));
    const brand = document.querySelector('.brand');
    const endpoint = new URL('mockup_generation_activity.php', brand ? brand.href : window.location.href).href;
    let pollTimer = 0;
    let completionAudioContext = null;
    let soundMuted = false;

    try {
        soundMuted = localStorage.getItem(soundMutedKey) === '1';
    } catch (error) {}

    function syncSoundToggles() {
        soundToggles.forEach(button => {
            button.setAttribute('aria-pressed', soundMuted ? 'true' : 'false');
            button.setAttribute('aria-label', soundMuted ? 'Turn on completion sounds' : 'Mute completion sounds');
            button.title = soundMuted ? 'Turn on completion sounds' : 'Mute completion sounds';
        });
    }

    function audioContext() {
        if (completionAudioContext) return completionAudioContext;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return null;
        try {
            completionAudioContext = new AudioContextClass();
        } catch (error) {
            completionAudioContext = null;
        }
        return completionAudioContext;
    }

    function armCompletionSound() {
        if (soundMuted) return;
        const context = audioContext();
        if (!context || context.state === 'running') return;
        context.resume().catch(() => {});
    }

    function scheduleCompletionTone(context, frequency, start, duration, type, volume) {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = type;
        oscillator.frequency.setValueAtTime(frequency, start);
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(volume, start + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start(start);
        oscillator.stop(start + duration + 0.02);
    }

    function playCompletionSound(kind) {
        if (soundMuted) return;
        const context = audioContext();
        if (!context) return;
        const play = () => {
            if (context.state !== 'running') return;
            const now = context.currentTime + 0.03;
            if (kind === 'error') {
                scheduleCompletionTone(context, 330, now, 0.24, 'triangle', 0.035);
                scheduleCompletionTone(context, 247, now + 0.18, 0.3, 'triangle', 0.03);
                return;
            }
            scheduleCompletionTone(context, 659, now, 0.22, 'sine', 0.035);
            scheduleCompletionTone(context, 880, now + 0.16, 0.34, 'sine', 0.04);
        };
        if (context.state === 'running') {
            play();
            return;
        }
        context.resume().then(play).catch(() => {});
    }

    function ids(key) {
        try {
            const parsed = JSON.parse(localStorage.getItem(key) || '[]');
            return Array.isArray(parsed) ? parsed.map(Number).filter(Number.isFinite) : [];
        } catch (error) { return []; }
    }
    function save(key, values) {
        localStorage.setItem(key, JSON.stringify(Array.from(new Set(values.map(Number).filter(Number.isFinite)))));
    }
    function playNewNoticeSound(notices, kind) {
        const played = ids(soundPlayedKey);
        const newIds = (notices || [])
            .map(item => Number(item.id))
            .filter(id => Number.isFinite(id) && !played.includes(id));
        if (!newIds.length) return;
        save(soundPlayedKey, played.concat(newIds).slice(-120));
        playCompletionSound(kind);
    }
    function trackJobs(values) {
        save(trackedKey, ids(trackedKey).concat((values || []).map(Number)));
        refresh(150);
    }
    function clearNotices() {
        save(pendingKey, []);
        if (ready) ready.hidden = true;
    }
    function dismissJobs(values) {
        const dismissed = new Set((values || []).map(Number).filter(Number.isFinite));
        if (!dismissed.size) return;
        save(pendingKey, ids(pendingKey).filter(id => !dismissed.has(id)));
        if (ready && ids(pendingKey).length === 0) ready.hidden = true;
    }
    function render(data) {
        const active = Array.isArray(data.active) ? data.active : [];
        if (activity && activeText) {
            activity.hidden = active.length === 0;
            activeText.textContent = active.length === 1
                ? '1 mockup is being created in the background'
                : active.length + ' mockups are being created in the background';
        }

        const activeIds = active.map(item => Number(item.id));
        if (activeIds.length) save(trackedKey, ids(trackedKey).concat(activeIds));
        const tracked = ids(trackedKey);
        const allItems = Array.isArray(data.items) ? data.items : [];
        const completed = allItems.filter(item => tracked.includes(Number(item.id)) && !item.active);
        if (completed.length) {
            save(trackedKey, tracked.filter(id => !completed.some(item => Number(item.id) === id)));
            save(pendingKey, ids(pendingKey).concat(completed.map(item => Number(item.id))));
            completed.forEach(item => {
                window.dispatchEvent(new CustomEvent('artworkmockups:generation-completed', { detail: item }));
            });
        }

        const remainingTracked = ids(trackedKey);
        const trackedActive = active.filter(item => remainingTracked.includes(Number(item.id)));
        const pendingIds = ids(pendingKey);
        const notices = allItems.filter(item => pendingIds.includes(Number(item.id)));
        if (!ready || !notices.length || trackedActive.length > 0) {
            if (ready) ready.hidden = true;
            return;
        }
        const successful = notices.filter(item => item.status === 'done');
        const failed = notices.filter(item => item.status === 'error' || item.status === 'failed_enqueue');
        playNewNoticeSound(notices, failed.length > 0 ? 'error' : 'success');
        successful.forEach(item => {
            window.dispatchEvent(new CustomEvent('artworkmockups:generation-ready', { detail: item }));
        });
        const pendingAfterEvents = ids(pendingKey);
        const visibleSuccessful = successful.filter(item => pendingAfterEvents.includes(Number(item.id)));
        const visibleFailed = failed.filter(item => pendingAfterEvents.includes(Number(item.id)));
        if (!visibleSuccessful.length && !visibleFailed.length) {
            ready.hidden = true;
            return;
        }
        ready.hidden = false;
        ready.classList.toggle('is-error', visibleSuccessful.length === 0 && visibleFailed.length > 0);
        if (visibleSuccessful.length) {
            const regenerations = visibleSuccessful.filter(item => item.kind === 'regeneration').length;
            const newScenes = visibleSuccessful.filter(item => item.kind === 'generation').length;
            const oneGenerationRun = new Set(visibleSuccessful.map(item => String(item.generation_run_id || '')).filter(Boolean)).size === 1;
            const sceneCategory = String(visibleSuccessful[0]?.scene_category || '').trim();
            if (newScenes === visibleSuccessful.length && oneGenerationRun) {
                readyTitle.textContent = newScenes === 1 ? 'New scene ready' : newScenes + ' new scenes ready';
                readyText.textContent = sceneCategory !== ''
                    ? sceneCategory + ' is ready to review.'
                    : 'Your new scenes are ready to review.';
                readyLink.textContent = 'View new scenes';
            } else {
                readyTitle.textContent = visibleSuccessful.length === 1
                    ? (regenerations ? 'Regeneration ready' : 'Mockup ready')
                    : visibleSuccessful.length + ' mockups ready';
                readyText.textContent = 'The task finished without interrupting your work.';
                readyLink.textContent = 'View results';
            }
            readyLink.hidden = false;
            readyLink.href = visibleSuccessful[0].results_url || 'mockups.php';
        } else {
            readyTitle.textContent = visibleFailed.length === 1 ? 'Generation could not finish' : 'Some generations could not finish';
            readyText.textContent = visibleFailed[0]?.error || 'The credit was returned and the task can be retried.';
            readyLink.hidden = true;
        }
    }
    async function poll() {
        try {
            const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const data = await response.json();
            if (data.ok) render(data);
            window.clearTimeout(pollTimer);
            pollTimer = window.setTimeout(poll, data.active_count > 0 ? 3000 : 10000);
        } catch (error) {
            window.clearTimeout(pollTimer);
            pollTimer = window.setTimeout(poll, 12000);
        }
    }
    function refresh(delay) {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, typeof delay === 'number' ? delay : 0);
    }

    dismiss?.addEventListener('click', clearNotices);
    readyLink?.addEventListener('click', clearNotices);
    soundToggles.forEach(button => {
        button.addEventListener('click', () => {
            soundMuted = !soundMuted;
            try {
                localStorage.setItem(soundMutedKey, soundMuted ? '1' : '0');
            } catch (error) {}
            syncSoundToggles();
            if (!soundMuted) playCompletionSound('success');
        });
    });
    document.addEventListener('pointerdown', armCompletionSound, { capture: true });
    document.addEventListener('keydown', armCompletionSound, { capture: true });
    window.addEventListener('storage', event => {
        if (event.key !== soundMutedKey) return;
        soundMuted = event.newValue === '1';
        syncSoundToggles();
    });
    syncSoundToggles();
    window.artworkGenerationTracker = { trackJobs: trackJobs, refresh: refresh, dismissJobs: dismissJobs };
    refresh(300);
})();
</script>
<?php endif; ?>
<script>
(function () {
    const menu = document.querySelector('[data-sidebar-website-menu]');
    const toggle = document.querySelector('[data-sidebar-website-toggle]');
    const panel = menu ? menu.querySelector('.sidebar-submenu-panel') : null;
    if (!menu || !toggle || !panel) return;

    document.body.appendChild(panel);

    function cumulativeZoom(el) {
        let zoom = 1;
        let node = el;
        while (node) {
            const z = parseFloat(window.getComputedStyle(node).zoom);
            if (!isNaN(z) && z > 0) zoom *= z;
            node = node.parentElement;
        }
        return zoom;
    }

    function positionPanel() {
        const rect = toggle.getBoundingClientRect();
        // The panel lives under <body>/<html>, which can carry a different
        // cumulative CSS `zoom` than the toggle (see style.css "Laptop Scaling
        // Tweak"). getBoundingClientRect() already returns true viewport
        // pixels, but assigning that value straight to style.left/top gets
        // re-scaled by the panel's own zoom context, so we pre-divide by it.
        const zoom = cumulativeZoom(panel.parentElement);
        const computedLeft = Math.round(rect.left / zoom);
        const computedTop = Math.round((rect.bottom + 1) / zoom);
        panel.style.left = computedLeft + 'px';
        panel.style.top = computedTop + 'px';
    }

    function closePanel() {
        panel.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (event) {
        event.preventDefault();
        const willOpen = !panel.classList.contains('is-open');
        if (!willOpen) {
            closePanel();
            return;
        }
        positionPanel();
        panel.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
    });
    document.addEventListener('click', function (event) {
        if (!toggle.contains(event.target) && !panel.contains(event.target)) {
            closePanel();
        }
    });
    window.addEventListener('resize', function () {
        if (panel.classList.contains('is-open')) positionPanel();
    });
    document.querySelector('.sidebar-tabs')?.addEventListener('scroll', function () {
        if (panel.classList.contains('is-open')) positionPanel();
    });
})();
</script>
<script>
(function () {
    const navigation = document.querySelector('.sidebar-tabs');
    const activeTab = navigation ? navigation.querySelector('.sidebar-tab.active') : null;
    if (!navigation || !activeTab) return;

    function keepActiveTabVisible() {
        if (window.innerWidth <= 980) return;
        const navigationRect = navigation.getBoundingClientRect();
        const activeRect = activeTab.getBoundingClientRect();
        if (activeRect.left < navigationRect.left || activeRect.right > navigationRect.right) {
            activeTab.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'nearest' });
        }
    }

    requestAnimationFrame(function () {
        requestAnimationFrame(keepActiveTabVisible);
    });
    window.addEventListener('resize', keepActiveTabVisible);
    new MutationObserver(function () {
        requestAnimationFrame(keepActiveTabVisible);
    }).observe(document.body, { attributes: true, attributeFilter: ['data-sidebar-flow-mode'] });
})();
</script>
<?php if ($sidebarIsAdmin): ?>
<script>
(function () {
    const storageKey = 'sidebarFlowMode';
    const toggle = document.getElementById('sidebar-flow-mode-toggle');
    const label = document.getElementById('sidebar-flow-mode-label');
    const brand = document.querySelector('.brand[data-admin-href][data-normal-href]');
    const modeButtons = document.querySelectorAll('[data-sidebar-flow-mode-option]');

    function setFlowMode(mode) {
        const normalized = mode === 'normal' ? 'normal' : 'admin';
        const cookieMatch = document.cookie.match(/(?:^|; )sidebar_flow_mode=([^;]*)/);
        const cookieMode = cookieMatch ? decodeURIComponent(cookieMatch[1]) : '';
        document.cookie = 'sidebar_flow_mode=' + encodeURIComponent(normalized) + '; path=/; max-age=31536000; samesite=lax';
        if (cookieMode !== normalized && /\/mockup_variation_lab\.php$/.test(window.location.pathname)) {
            window.location.reload();
            return;
        }
        document.body.dataset.sidebarFlowMode = normalized;
        if (toggle) {
            toggle.checked = normalized === 'admin';
        }
        if (label) {
            label.textContent = normalized === 'admin' ? 'Admin' : 'Usuario';
        }
        if (brand) {
            brand.href = normalized === 'admin' ? brand.dataset.adminHref : brand.dataset.normalHref;
        }
        modeButtons.forEach(function (button) {
            const isActive = button.dataset.sidebarFlowModeOption === normalized;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    setFlowMode(localStorage.getItem(storageKey) || 'admin');

    if (toggle) {
        toggle.addEventListener('change', function () {
            const mode = toggle.checked ? 'admin' : 'normal';
            localStorage.setItem(storageKey, mode);
            setFlowMode(mode);
        });
    }

    modeButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const mode = button.dataset.sidebarFlowModeOption === 'normal' ? 'normal' : 'admin';
            localStorage.setItem(storageKey, mode);
            setFlowMode(mode);
        });
    });
})();
</script>
<?php endif; ?>
<?php
if ($sidebarUser) {
    AssistantView::render($sidebarUser, [
        'current_route' => $currentPage,
        'artwork_id' => $sidebarContextArtworkId ?: $currentArtworkIdParam,
        'mockup_id' => $currentMockupIdParam,
        'series_id' => $currentPage === 'series.php' ? (int)($_GET['id'] ?? 0) : 0,
        'generation_id' => (int)($_GET['generation_id'] ?? $_GET['job_id'] ?? 0),
        'publication_id' => (int)($_GET['publication_id'] ?? 0),
    ]);
}
?>
