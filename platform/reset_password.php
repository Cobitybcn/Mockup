<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: artwork_new.php');
    exit;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            Auth::resetPassword($token, $password);
            $success = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose New Password - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css?v=auth-gallery-6">
</head>
<body class="auth-page">
<div class="auth-bg-full">
    <img src="assets/showcase/latest_mockup_1.jpg" alt="Background" class="auth-bg-image-full">
    <div class="auth-bg-overlay-full"></div>
</div>

<main class="auth-layout-v2">
    <div class="auth-card-floating">
        <a class="brand-v2" href="login.php">
            <span class="star-mark">✦</span> Artwork Mockups
        </a>

        <h1>New password</h1>
        <p class="page-kicker-v2">Choose a new password for your account.</p>

        <div class="auth-divider"><span class="divider-dot">✦</span></div>

        <?php if ($success): ?>
            <p class="notice" style="margin-bottom: 20px;">Your password has been updated.</p>
            <p class="auth-links-v2"><a href="login.php">Login with your new password</a></p>
        <?php else: ?>
            <?php if ($error): ?>
                <p class="notice error" style="margin-bottom: 20px;"><?= h($error) ?></p>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= h($token) ?>">

                <div class="form-group-v2">
                    <label for="password">Password</label>
                    <div class="input-wrapper-v2">
                        <span class="field-icon-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        </span>
                        <input type="password" id="password" name="password" required autocomplete="new-password" placeholder="Choose a password">
                    </div>
                </div>

                <div class="form-group-v2">
                    <label for="confirm_password">Confirm password</label>
                    <div class="input-wrapper-v2">
                        <span class="field-icon-left">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
                        </span>
                        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="Repeat password">
                    </div>
                </div>

                <button type="submit" class="btn-submit-v2">Update password</button>
            </form>

            <div class="auth-divider"><span class="divider-dot">✦</span></div>
            <p class="auth-links-v2"><a href="forgot_password.php">Request a new link</a></p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
