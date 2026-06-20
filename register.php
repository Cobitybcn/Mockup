<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: dashboard.php');
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

        header('Location: dashboard.php');
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
    <title>Register - The Artwork Curator</title>
    <link rel="stylesheet" href="style.css?v=auth-gallery-5">
</head>
<body class="auth-page">
<main class="auth-layout">
    <section class="auth-panel">
        <a class="brand" href="login.php">The Artwork Curator <span class="brand-mark"></span></a>

        <h1 style="margin-top:56px;">Register</h1>
        <p class="page-kicker">Create your private workspace to generate root images and build curatorial mockups.</p>

        <?php if ($error): ?>
            <p class="notice error"><?= h($error) ?></p>
        <?php endif; ?>

        <form class="auth-card" method="post">
            <label>Name</label>
            <input type="text" name="name" autocomplete="name">

            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">

            <label>Password</label>
            <input type="password" name="password" required autocomplete="new-password">

            <button type="submit">Register</button>
        </form>

        <p class="auth-links">
            Already have an account? <a href="login.php">Login</a>
        </p>
    </section>

    <section class="auth-visual" aria-label="Artwork mockup preview">
        <figure class="auth-gallery auth-gallery-main" style="--auth-gallery-opacity: <?= h($authOpacities['main']) ?>">
            <img src="<?= h($authImages[0]) ?>" alt="">
        </figure>
        <figure class="auth-gallery auth-gallery-secondary" style="--auth-gallery-opacity: <?= h($authOpacities['secondary']) ?>">
            <img src="<?= h($authImages[1]) ?>" alt="">
        </figure>
        <figure class="auth-gallery auth-gallery-tertiary" style="--auth-gallery-opacity: <?= h($authOpacities['tertiary']) ?>">
            <img src="<?= h($authImages[2]) ?>" alt="">
        </figure>
        <div class="auth-visual-caption">
            Private archive for artworks, curated presentations, and collector-ready mockups.
        </div>
    </section>
</main>
</body>
</html>
