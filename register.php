<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: create_scenes.php');
    exit;
}

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        Auth::register(
            (string)($_POST['email'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['name'] ?? '')
        );

        header('Location: create_scenes.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function auth_mockup_images(): array
{
    $files = glob(RESULTS_DIR . DIRECTORY_SEPARATOR . '*mockup*.{jpg,jpeg,png}', GLOB_BRACE) ?: [];
    $files = array_filter($files, static function (string $path): bool {
        $name = strtolower(basename($path));
        return !str_contains($name, '.original.')
            && !str_contains($name, 'prompt')
            && is_file($path);
    });

    $groups = [];
    foreach ($files as $path) {
        $name = basename($path);
        if (preg_match('/rootartjob\d+v\d+/i', $name, $matches)) {
            $key = strtolower($matches[0]);
        } else {
            $key = preg_replace('/-(?:[^-]+-)?(?:frontal|3-4-left|3-4-right|mockup).*$/i', '', pathinfo($name, PATHINFO_FILENAME));
        }
        $key = $key ?: pathinfo($name, PATHINFO_FILENAME);
        $groups[$key][] = $path;
    }

    $selected = [];
    foreach ($groups as $groupFiles) {
        $selected[] = $groupFiles[array_rand($groupFiles)];
    }

    shuffle($selected);

    $fallback = [
        'assets/auth/gallery-main.jpg',
        'assets/auth/gallery-side.jpg',
        'assets/auth/gallery-detail.jpg',
    ];

    return array_map(
        static fn(string $path): string => 'auth_mockup_image.php?file=' . rawurlencode(basename($path)) . '&v=' . filemtime($path),
        array_slice($selected, 0, 3)
    ) + $fallback;
}

$authImages = auth_mockup_images();
$authOpacities = [
    'main' => random_int(52, 72) / 100,
    'secondary' => random_int(38, 58) / 100,
    'tertiary' => random_int(34, 54) / 100,
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register - Artwork Mockups</title>
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

        <h1>Register</h1>
        <p class="page-kicker-v2">Create your private workspace and begin building high-end artwork mockups.</p>

        <div class="auth-divider">
            <span class="divider-dot">✦</span>
        </div>

        <?php if ($error): ?>
            <p class="notice error" style="margin-bottom: 20px;"><?= h($error) ?></p>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="form-group-v2">
                <label for="name">Name</label>
                <div class="input-wrapper-v2">
                    <span class="field-icon-left">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </span>
                    <input type="text" id="name" name="name" autocomplete="name" placeholder="Your name">
                </div>
            </div>

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
                    <input type="password" id="password" name="password" required autocomplete="new-password" placeholder="Choose a password">
                    <button type="button" class="toggle-password-btn" aria-label="Toggle password visibility" onclick="togglePasswordVisibility()">
                        <!-- Eye-off icon by default -->
                        <svg id="password-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit-v2">Register</button>
        </form>

        <div class="auth-divider">
            <span class="divider-dot">✦</span>
        </div>

        <p class="auth-links-v2">
            Already have an account? <a href="login.php">Log in</a>
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
