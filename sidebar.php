<?php
declare(strict_types=1);

// Resolve current user and admin role
$sidebarUser = isset($user) ? $user : (isset($currentUser) ? $currentUser : Auth::user());
$sidebarIsAdmin = $sidebarUser ? Auth::isAdmin($sidebarUser) : false;

$currentPage = basename($_SERVER['PHP_SELF']);
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$currentImageParam = basename((string)($_GET['image'] ?? $_POST['image'] ?? ''));
$currentArtworkIdParam = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$sidebarContextArtworkId = 0;
$sidebarContextRootFile = '';
$sidebarArtistPhoto = '';

if ($sidebarUser) {
    try {
        $db = Database::connection();
        $profileStmt = $db->prepare('SELECT photo_file FROM artist_profiles WHERE user_id = :user_id LIMIT 1');
        $profileStmt->execute(['user_id' => (int)$sidebarUser['id']]);
        $sidebarArtistPhoto = basename((string)($profileStmt->fetchColumn() ?: ''));

        if ($currentImageParam !== '') {
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
        } elseif ($currentArtworkIdParam > 0) {
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
        } else {
            $contextArtwork = null;
        }

        if ($contextArtwork) {
            $sidebarContextArtworkId = (int)($contextArtwork['id'] ?? 0);
            $sidebarContextRootFile = basename((string)($contextArtwork['root_file'] ?? ''));
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step active states
$step1Active = ($currentPage === 'artwork_new.php' || $currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step2Active = ($currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step3Active = ($currentPage === 'core_review.php');
$step4Active = ($currentPage === 'artwork.php' || $currentPage === 'publish.php' || $currentPage === 'artwork_details.php');
$step5Active = ($currentPage === 'report.php' || $currentPage === 'curated_mockups.php' || $currentPage === 'mockup_batch_wait.php' || $currentPage === 'mockup_branches_review.php' || $currentPage === 'mockup_prompt_drafts_review.php' || $currentPage === 'mockup_combinations_review.php' || $currentPage === 'mockup_combination_results.php' || $currentPage === 'mockup_variation_lab.php' || $currentPage === 'generate_mockup_combination.php' || $currentPage === 'save_mockup_combination_evaluation.php' || $currentPage === 'prompt_inspector.php' || $currentPage === 'approve_mockup_prompt_draft.php' || $currentPage === 'generate_mockup_from_approved_prompt.php');

// Menu active states
$dashboardActive = ($currentPage === 'dashboard.php');
$mockupsActive = ($currentPage === 'mockups.php' || $currentPage === 'viewer.php');
$worldMotherActive = ($currentPage === 'world_mother_studio.php');
$cameraStudioActive = ($currentPage === 'camera_studio.php');
$variationLabActive = ($currentPage === 'mockup_variation_lab.php');
$generatedResultsActive = ($currentPage === 'mockup_combination_results.php');
$profileActive = ($currentPage === 'artist_profile.php');
$usersActive = ($currentPage === 'admin_users.php');
$accountActive = ($currentPage === 'account.php');

// Admin active states
$promptsActive = ($currentPage === 'admin_prompts.php');
$apiActive = ($currentPage === 'admin_api_keys.php');

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

$generatedResultsUrl = $sidebarContextArtworkId > 0
    ? 'mockup_combination_results.php?id=' . urlencode((string)$sidebarContextArtworkId)
    : 'mockups.php';

?>
<aside class="sidebar">
    <div class="sidebar-head">
        <a class="brand" href="artwork_new.php">
            <span class="brand-kicker">BETA WORKFLOW</span>
            <span class="brand-title">MOCKUP LAB <span class="brand-mark"></span></span>
        </a>
    </div>

    <nav class="sidebar-tabs" aria-label="Primary navigation">
        <section class="sidebar-tab-group">
            <div class="sidebar-section-title">Create</div>
            <div class="sidebar-tab-row">
                <a class="sidebar-tab <?= $step1Active ? 'active' : '' ?>" href="artwork_new.php">Upload Artwork</a>
                <?php if (!$step5Disabled || $step5Active): ?>
                    <a class="sidebar-tab <?= $step5Active && !$variationLabActive && !$generatedResultsActive ? 'active' : '' ?>" href="<?= htmlspecialchars($step5Url, ENT_QUOTES, 'UTF-8') ?>">Scenes</a>
                <?php endif; ?>
                <a class="sidebar-tab <?= $generatedResultsActive ? 'active' : '' ?>" href="<?= htmlspecialchars($generatedResultsUrl, ENT_QUOTES, 'UTF-8') ?>">Generated Results</a>
                <a class="sidebar-tab <?= $variationLabActive ? 'active' : '' ?>" href="mockup_variation_lab.php">Variation Lab</a>
            </div>
        </section>

        <section class="sidebar-context">
            <div class="sidebar-tab-row">
                <?php if (!$step4Disabled): ?>
                    <a class="sidebar-tab compact <?= $step4Active ? 'active' : '' ?>" href="<?= htmlspecialchars($step4Url, ENT_QUOTES, 'UTF-8') ?>">Artwork Details</a>
                <?php endif; ?>
                <a class="sidebar-tab <?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">Mockup Album</a>
                <a class="sidebar-tab <?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">Artist Profile</a>
            </div>
        </section>

        <section class="sidebar-tab-group sidebar-account">
            <div class="sidebar-tab-row">
                <?php if ($sidebarArtistPhoto !== ''): ?>
                    <a class="sidebar-artist-photo" href="artist_profile.php" title="Artist Profile" aria-label="Artist Profile">
                        <img src="uploads/artist_profiles/<?= rawurlencode($sidebarArtistPhoto) ?>" alt="">
                    </a>
                <?php endif; ?>
                <a class="sidebar-tab compact <?= $dashboardActive ? 'active' : '' ?>" href="dashboard.php">Dashboard</a>
                <a class="sidebar-tab compact <?= $accountActive ? 'active' : '' ?>" href="account.php">Account</a>
                <a class="sidebar-tab compact" href="logout.php">Logout</a>
            </div>
        </section>
    </nav>

    <?php if ($sidebarIsAdmin): ?>
        <details class="sidebar-more">
            <summary>Admin</summary>
            <ul class="nav">
                <li><a class="<?= $usersActive ? 'active' : '' ?>" href="admin_users.php">Users & Credits</a></li>
                <li><a class="<?= $worldMotherActive ? 'active' : '' ?>" href="world_mother_studio.php">Scenes Studio</a></li>
                <li><a class="<?= $cameraStudioActive ? 'active' : '' ?>" href="camera_studio.php">Camera Studio</a></li>
                <li><a class="<?= $promptsActive ? 'active' : '' ?>" href="admin_prompts.php">System Prompts</a></li>
                <li><a class="<?= $apiActive ? 'active' : '' ?>" href="admin_api_keys.php">API Settings</a></li>
            </ul>
        </details>
    <?php endif; ?>
</aside>
