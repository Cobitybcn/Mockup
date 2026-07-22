<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$publicRegistrationEnabled = strtolower(app_env('APP_ENV', '')) !== 'production'
    || strtolower(app_env('PUBLIC_REGISTRATION_ENABLED', 'false')) === 'true';

$authenticatedUser = Auth::user();
if ($authenticatedUser) {
    header('Location: create_scenes.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!Auth::validateCsrf((string)($_POST['csrf'] ?? ''), 'login')) {
        $error = 'Your form session expired. Reload the page and try again.';
    } elseif (Auth::login($email, $password)) {
        header('Location: create_scenes.php');
        exit;
    } else {
        $error = 'Incorrect email or password.';
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$authOpacities = [
    'main' => 0.65,
    'secondary' => 0.48,
    'tertiary' => 0.44,
];
$showcaseBackground = PublicArtistShowcase::background(Database::connection(), 'assets/showcase/latest_mockup_1.jpg');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Artwork Mockups</title>
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

        <h1>Login</h1>
        <p class="page-kicker-v2">Access your private archive of artworks, root images, and curatorial mockups.</p>

        <div class="auth-divider">
            <span class="divider-dot">✦</span>
        </div>

        <?php if ($error): ?>
            <p class="notice error" style="margin-bottom: 20px;"><?= h($error) ?></p>
        <?php endif; ?>

        <form method="post" novalidate>
            <input type="hidden" name="csrf" value="<?= h(Auth::csrfToken('login')) ?>">
            <div class="form-group-v2">
                <label for="email">Email</label>
                <div class="input-wrapper-v2">
                    <span class="field-icon-left">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </span>
                    <input type="email" id="email" name="email" required autocomplete="email" placeholder="Your email address">
                </div>
            </div>

            <div class="form-group-v2">
                <label for="password">Password</label>
                <div class="input-wrapper-v2">
                    <span class="field-icon-left">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </span>
                    <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="Enter your password">
                    <button type="button" class="toggle-password-btn" aria-label="Toggle password visibility" onclick="togglePasswordVisibility()">
                        <!-- Eye-off icon by default -->
                        <svg id="password-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit-v2">Login</button>
        </form>

        <div class="auth-divider">
            <span class="divider-dot">✦</span>
        </div>

        <p class="auth-links-v2">
            <?php if ($publicRegistrationEnabled): ?>
                Don't have an account? <a href="register.php">Register</a>
                <br>
            <?php endif; ?>
            <a href="forgot_password.php">Forgot password?</a>
        </p>

        <div class="auth-footer-v2">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
            <span>Faithful artwork presentation. Professional mockups.</span>
        </div>
    </div>
</main>

<script>
function togglePasswordVisibility() {
    const passwordInput = document.getElementById('password');
    const toggleIcon = document.getElementById('password-toggle-icon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        // Set to visible eye icon
        toggleIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    } else {
        passwordInput.type = 'password';
        // Set back to eye-off icon
        toggleIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    }
}
</script>

</body>
</html>
