<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: create_scenes.php');
    exit;
}

$message = '';
$debugLink = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Auth::validateCsrf((string)($_POST['csrf'] ?? ''), 'forgot_password')) {
        $message = 'Your form session expired. Reload the page and try again.';
    } else {
        $result = Auth::requestPasswordReset((string)($_POST['email'] ?? ''));
        $message = 'If an account exists for that email, we sent a password reset link.';
        $debugLink = (string)($result['debug_link'] ?? '');
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
$showcaseBackground = PublicArtistShowcase::background(Database::connection(), 'assets/showcase/latest_mockup_1.jpg');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - Artwork Mockups</title>
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=1">
    <link rel="stylesheet" href="style.css?v=auth-gallery-6">
</head>
<body class="auth-page">
<div class="auth-bg-full">
    <img src="<?= h($showcaseBackground['url']) ?>" alt="" class="auth-bg-image-full">
    <div class="auth-bg-overlay-full"></div>
</div>

<main class="auth-layout-v2">
    <div class="auth-card-floating">
        <a class="brand-v2" href="login.php">
            <span class="star-mark">✦</span> Artwork Mockups
        </a>

        <h1>Reset password</h1>
        <p class="page-kicker-v2">Enter your account email and we will send a reset link.</p>

        <div class="auth-divider"><span class="divider-dot">✦</span></div>

        <?php if ($message): ?>
            <p class="notice" style="margin-bottom: 20px;"><?= h($message) ?></p>
            <?php if ($debugLink !== ''): ?>
                <p class="notice" style="margin-bottom: 20px;">Debug reset link: <a href="<?= h($debugLink) ?>">open reset link</a></p>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken('forgot_password')) ?>">
            <div class="form-group-v2">
                <label for="email">Email</label>
                <div class="input-wrapper-v2">
                    <span class="field-icon-left">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </span>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="Your email address">
                </div>
            </div>

            <button type="submit" class="btn-submit-v2">Send reset link</button>
        </form>

        <div class="auth-divider"><span class="divider-dot">✦</span></div>

        <p class="auth-links-v2">
            Remember your password? <a href="login.php">Login</a>
        </p>
    </div>
</main>
</body>
</html>
