<?php
declare(strict_types=1);

// Resolve current user and admin role
$sidebarUser = isset($user) ? $user : (isset($currentUser) ? $currentUser : Auth::user());
$sidebarIsAdmin = $sidebarUser ? Auth::isAdmin($sidebarUser) : false;

$currentPage = basename($_SERVER['PHP_SELF']);
$queryString = $_SERVER['QUERY_STRING'] ?? '';

// Step active states
$step1Active = ($currentPage === 'artwork_new.php');
$step2Active = ($currentPage === 'root_select.php' || $currentPage === 'waiting.php');
$step3Active = ($currentPage === 'report.php');
$step4Active = ($currentPage === 'artwork.php' || $currentPage === 'publish.php');

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

// Step 3 link determination (Create Mockups)
$step3Url = '#';
$step3Disabled = true;

if ($currentPage === 'report.php') {
    $imageParam = $_GET['image'] ?? $_POST['image'] ?? '';
    if ($imageParam !== '') {
        $step3Url = 'report.php?image=' . urlencode(basename($imageParam));
        $step3Disabled = false;
    }
}

if ($step3Disabled && $sidebarUser) {
    try {
        $db = Database::connection();
        $stmt = $db->prepare("SELECT root_file FROM artworks WHERE user_id = :user_id AND status = 'done' AND root_file IS NOT NULL AND root_file != '' ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['user_id' => $sidebarUser['id']]);
        $latestArtwork = $stmt->fetch();
        if ($latestArtwork && !empty($latestArtwork['root_file'])) {
            $step3Url = 'report.php?image=' . urlencode(basename($latestArtwork['root_file']));
            $step3Disabled = false;
        }
    } catch (Throwable $e) {
        // Fallback silently if DB is not ready
    }
}

// Step 4 link determination (Publish = Artwork details)
$step4Url = '#';
$step4Disabled = true;

if ($currentPage === 'artwork.php') {
    $artworkId = (int)($_GET['id'] ?? 0);
    if ($artworkId > 0) {
        $step4Url = 'artwork.php?id=' . urlencode((string)$artworkId);
        $step4Disabled = false;
    }
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
            $step4Url = 'artwork.php?id=' . urlencode((string)$latestArtworkId);
            $step4Disabled = false;
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
                <span class="step-subtitle">Subir la obra original.</span>
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
                    <span class="step-subtitle">Elegir obra raíz generada.</span>
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
                    <span class="step-subtitle">Elegir obra raíz generada.</span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Step 3: Create Mockups -->
        <?php if ($step3Disabled && !$step3Active): ?>
            <div class="step-item disabled" title="No root artwork selected yet. Please upload and select one first.">
                <div class="step-num-container">
                    <span class="step-number">3</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Create Mockups</span>
                    <span class="step-subtitle">Crear múltiples mockups.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step3Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step3Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">3</span>
                    <?php if ($step3Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Create Mockups</span>
                    <span class="step-subtitle">Crear múltiples mockups.</span>
                </div>
            </a>
        <?php endif; ?>

        <!-- Step 4: Publish -->
        <?php if ($step4Disabled && !$step4Active): ?>
            <div class="step-item disabled" title="No artwork ready to publish yet. Create/select a root artwork first.">
                <div class="step-num-container">
                    <span class="step-number">4</span>
                </div>
                <div class="step-details">
                    <span class="step-label">Publish</span>
                    <span class="step-subtitle">Ficha artwork.</span>
                </div>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($step4Url, ENT_QUOTES, 'UTF-8') ?>" class="step-item <?= $step4Active ? 'active' : '' ?>">
                <div class="step-num-container">
                    <span class="step-number">4</span>
                    <?php if ($step4Active): ?><span class="step-indicator"></span><?php endif; ?>
                </div>
                <div class="step-details">
                    <span class="step-label">Publish</span>
                    <span class="step-subtitle">Ficha artwork.</span>
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
