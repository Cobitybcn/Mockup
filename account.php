<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$isAdmin = Auth::isAdmin($user);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Cuenta</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="dashboard.php">ARTMOCK <span class="brand-mark"></span></a>
        </div>

        <div class="sidebar-action">
            <a class="button-link" href="artwork_new.php">+ Nueva obra</a>
        </div>

        <ul class="nav">
            <li><a href="dashboard.php">Dashboard</a></li>
            <li><a href="artwork_new.php">Crear obra raiz</a></li>
            <li><a href="artist_profile.php">Perfil de artista</a></li>
            <?php if ($isAdmin): ?>
                <li><a href="admin_prompts.php">Admin prompts</a></li>
                <li><a href="admin_api_keys.php">API keys</a></li>
            <?php endif; ?>
            <li><a class="active" href="account.php">Cuenta y pagos</a></li>
        </ul>

        <div class="nav-section">Sesion</div>
        <ul class="nav">
            <li><a href="logout.php">Salir</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Pagos reales pendientes. El beta usa creditos internos para preparar la arquitectura comercial.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Cuenta</h1>
                    <p><?= h($user['email']) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <section class="stats">
                <div class="stat-card">
                    <span>Creditos disponibles</span>
                    <strong><?= h($user['credits']) ?></strong>
                </div>
                <div class="stat-card">
                    <span>Plan</span>
                    <strong>Beta</strong>
                </div>
                <div class="stat-card">
                    <span>Pagos</span>
                    <strong>Off</strong>
                </div>
                <div class="stat-card">
                    <span>Generacion</span>
                    <strong>Activa</strong>
                </div>
            </section>

            <section class="panel">
                <h2>Creditos beta</h2>
                <p>Los creditos internos estan preparados para la fase de planes. Todavia no se descuenta saldo automaticamente.</p>
            </section>

            <section class="panel">
                <h2>Pagos</h2>
                <p>Integracion pendiente. El beta funcionara primero con creditos internos antes de conectar pagos reales.</p>
            </section>
        </div>
    </main>
</div>
</body>
</html>
