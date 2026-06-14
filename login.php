<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (Auth::login($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Incorrect email or password.';
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
    <title>Login - The Artwork Curator</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="auth-layout">
    <section class="auth-panel">
        <a class="brand" href="login.php">The Artwork Curator <span class="brand-mark"></span></a>

        <h1 style="margin-top:56px;">Login</h1>
        <p class="page-kicker">Access your private archive of artworks, root images, and curatorial mockups.</p>

        <?php if ($error): ?>
            <p class="notice error"><?= h($error) ?></p>
        <?php endif; ?>

        <form class="auth-card" method="post">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">

            <label>Password</label>
            <input type="password" name="password" required autocomplete="current-password">

            <button type="submit">Login</button>
        </form>

        <p class="auth-links">
            Don't have an account? <a href="register.php">Register</a>
        </p>
    </section>

    <section class="auth-visual" aria-hidden="true">
        <div class="auth-art-card"></div>
    </section>
</main>
</body>
</html>
