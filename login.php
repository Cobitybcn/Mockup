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

    $error = 'Email o contrasena incorrectos.';
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ingresar</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="auth-layout">
    <section class="auth-panel">
        <a class="brand" href="login.php">ARTMOCK <span class="brand-mark"></span></a>

        <h1 style="margin-top:56px;">Ingresar</h1>
        <p class="page-kicker">Accede a tu archivo privado de obras, imagenes raiz y mockups curatoriales.</p>

        <?php if ($error): ?>
            <p class="notice error"><?= h($error) ?></p>
        <?php endif; ?>

        <form class="auth-card" method="post">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">

            <label>Contrasena</label>
            <input type="password" name="password" required autocomplete="current-password">

            <button type="submit">Ingresar</button>
        </form>

        <p class="auth-links">
            No tienes cuenta? <a href="register.php">Crear cuenta</a>
        </p>
    </section>

    <section class="auth-visual" aria-hidden="true">
        <div class="auth-art-card"></div>
    </section>
</main>
</body>
</html>
