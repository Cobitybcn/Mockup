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

if ($sidebarUser) {
    try {
        $db = Database::connection();
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
$step1Active = ($currentPage === 'artwork_new.php');
$step2Active = ($currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step3Active = ($currentPage === 'core_review.php');
$step4Active = ($currentPage === 'report.php');
$step5Active = ($currentPage === 'artwork.php' || $currentPage === 'publish.php');

// Menu active states
$dashboardActive = ($currentPage === 'dashboard.php');
$mockupsActive = ($currentPage === 'mockups.php' || $currentPage === 'viewer.php');
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

// Step 3 link determination (Review Artwork Core)
$step3Url = '#';
$step3Disabled = true;

if ($sidebarContextArtworkId > 0) {
    $step3Url = 'core_review.php?id=' . urlencode((string)$sidebarContextArtworkId);
    $step3Disabled = false;
} elseif ($currentPage === 'core_review.php' && $currentArtworkIdParam > 0) {
    $step3Url = 'core_review.php?id=' . urlencode((string)$currentArtworkIdParam);
    $step3Disabled = false;
}

if ($step3Disabled && $sidebarUser) {
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
            $step3Url = 'core_review.php?id=' . urlencode((string)$latestArtworkId);
            $step3Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step 4 link determination (Curated Mockups)
$step4Url = '#';
$step4Disabled = true;

if ($sidebarContextRootFile !== '') {
    $step4Url = 'report.php?image=' . urlencode($sidebarContextRootFile);
    $step4Disabled = false;
} elseif ($currentPage === 'report.php' && $currentImageParam !== '') {
    $step4Url = 'report.php?image=' . urlencode($currentImageParam);
    $step4Disabled = false;
}

if ($step4Disabled && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT root_file FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['user_id' => $sidebarUser['id']]);
        $latestArtwork = $stmt->fetch();
        if ($latestArtwork && !empty($latestArtwork['root_file'])) {
            $step4Url = 'report.php?image=' . urlencode(basename($latestArtwork['root_file']));
            $step4Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step 5 link determination (Publish = Artwork details)
$step5Url = '#';
$step5Disabled = true;

if ($sidebarContextArtworkId > 0) {
    $step5Url = 'artwork.php?id=' . urlencode((string)$sidebarContextArtworkId);
    $step5Disabled = false;
} elseif (($currentPage === 'artwork.php' || $currentPage === 'publish.php') && $currentArtworkIdParam > 0) {
    $step5Url = 'artwork.php?id=' . urlencode((string)$currentArtworkIdParam);
    $step5Disabled = false;
}

if ($step5Disabled && $sidebarUser) {
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
            $step5Url = 'artwork.php?id=' . urlencode((string)$latestArtworkId);
            $step5Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

?>
<aside class="sidebar">
    <div class="sidebar-head">
        <a class="brand" href="dashboard.php">
            <span class="brand-kicker">THE ARTWORK</span>
            <span class="brand-title">CURATOR <span class="brand-mark"></span></span>
        </a>
    </div>

    <!-- SECTION 1: STEPS -->
    <div class="sidebar-section-title">STEPS</div>
    <div class="sidebar-steps">
        <!-- Step 1: Upload Artwork -->
        <a href="artwork_new.php" class="step-item <?= $step1Active ? 'active' : '' ?>">
            <div class="step-num-container">
                <span class="step-number">1</span>
                <?php if ($step1Active): ?><span class="step-indicator"></span><?php endif; ?>
            </div>
            <div class="step-details">
                <span class="step-label">Upload Artwork</span>
                <span class="step-subtitle">Upload the original artwork.</span>
            </div>
        </a>

        <!-- Step 2: Select Root Artwork -->
        <?php if ($step2Disabled && !$step2Active): ?>
            <div class="step-item disabled" title="No artwork awaiting selection. Start by uploading an artwork.">
                <div class="step-num-container">
                    <span class="step-number">2</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Select Root Artwork</span>
                    <span class="step-subtitle">Choose the generated root artwork.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step2Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step2Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">2</span>
                    <?php if ($step2Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Select Root Artwork</span>
                    <span class="step-subtitle">Choose the generated root artwork.</span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Step 3: Review Artwork Core -->
        <?php if ($step3Disabled && !$step3Active): ?>
            <div class="step-item disabled" title="No root artwork selected yet. Please upload and select one first.">
                <div class="step-num-container">
                    <span class="step-number">3</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Review Artwork Core</span>
                    <span class="step-subtitle">Inspect visual & physical core data.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step3Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step3Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">3</span>
                    <?php if ($step3Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Review Artwork Core</span>
                    <span class="step-subtitle">Inspect visual & physical core data.</span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Step 4: Curated Mockups -->
        <?php if ($step4Disabled && !$step4Active): ?>
            <div class="step-item disabled" title="No root artwork selected yet. Please upload and select one first.">
                <div class="step-num-container">
                    <span class="step-number">4</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Curated Mockups</span>
                    <span class="step-subtitle">Create multiple mockups.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step4Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step4Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">4</span>
                    <?php if ($step4Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Curated Mockups</span>
                    <span class="step-subtitle">Create multiple mockups.</span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Step 5: Publish -->
        <?php if ($step5Disabled && !$step5Active): ?>
            <div class="step-item disabled" title="No artwork ready to publish yet. Create/select a root artwork first.">
                <div class="step-num-container">
                    <span class="step-number">5</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Artwork Details</span>
                    <span class="step-subtitle">Artwork metadata and publishing assets.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step5Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step5Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">5</span>
                    <?php if ($step5Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Artwork Details</span>
                    <span class="step-subtitle">Artwork metadata and publishing assets.</span>
                </div>
            </a>
        <?php endif; ?>

    </div>

    <!-- SECTION 2: MENU -->
    <div class="sidebar-section-title">MENU</div>
    <ul class="nav">
        <li>
            <a class="<?= $dashboardActive ? 'active' : '' ?>" href="dashboard.php">
                Dashboard
            </a>
        </li>
        <li>
            <a class="<?= $mockupsActive ? 'active' : '' ?>" href="mockups.php">
                Generated Mockups
            </a>
        </li>
        <li>
            <a class="<?= $profileActive ? 'active' : '' ?>" href="artist_profile.php">
                Artist Profile
            </a>
        </li>
        <?php if ($sidebarIsAdmin): ?>
            <li>
                <a class="<?= $usersActive ? 'active' : '' ?>" href="admin_users.php">
                    Users & Credits
                </a>
            </li>
        <?php endif; ?>
        <li>
            <a class="<?= $accountActive ? 'active' : '' ?>" href="account.php">
                Account
            </a>
        </li>
        <li>
            <a href="logout.php">
                Logout
            </a>
        </li>
    </ul>

    <!-- SECTION 3: ADMIN -->
    <?php if ($sidebarIsAdmin): ?>
        <div class="sidebar-section-title">ADMIN</div>
        <ul class="nav">
            <li>
                <a class="<?= $promptsActive ? 'active' : '' ?>" href="admin_prompts.php">
                    System Prompts
                </a>
            </li>
            <li>
                <a class="<?= $apiActive ? 'active' : '' ?>" href="admin_api_keys.php">
                    API Settings
                </a>
            </li>
        </ul>
    <?php endif; ?>
</aside>
