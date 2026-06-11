<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Crear cuenta</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="auth-layout">
    <section class="auth-panel">
        <a class="brand" href="login.php">ARTMOCK <span class="brand-mark"></span></a>

        <h1 style="margin-top:56px;">Crear cuenta</h1>
        <p class="page-kicker">Crea tu area privada para generar obras raiz y construir mockups curatoriales.</p>

        <?php if ($error): ?>
            <p class="notice error"><?= h($error) ?></p>
        <?php endif; ?>

        <form class="auth-card" method="post">
            <label>Nombre</label>
            <input type="text" name="name" autocomplete="name">

            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">

            <label>Contrasena</label>
            <input type="password" name="password" required autocomplete="new-password">

            <button type="submit">Crear cuenta</button>
        </form>

        <p class="auth-links">
            Ya tienes cuenta? <a href="login.php">Ingresar</a>
        </p>
    </section>

    <section class="auth-visual" aria-hidden="true">
        <div class="auth-art-card"></div>
    </section>
</main>
</body>
</html>
