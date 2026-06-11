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
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Generar obra raiz</title>
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
      <li><a class="active" href="artwork_new.php">Crear obra raiz</a></li>
      <li><a href="artist_profile.php">Perfil de artista</a></li>
      <?php if ($isAdmin): ?>
        <li><a href="admin_prompts.php">Admin prompts</a></li>
        <li><a href="admin_api_keys.php">API keys</a></li>
      <?php endif; ?>
      <li><a href="account.php">Cuenta y pagos</a></li>
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
      Formulario 1: crea una imagen raiz fiel. Los mockups se generan despues.
    </div>

    <div class="workspace">
      <div class="workspace-header">
        <div>
          <h1>Upload artwork</h1>
          <p>Sube una fotografia de la obra y define sus medidas reales.</p>
        </div>
        <div class="topbar-actions">
          <a class="button-link secondary" href="dashboard.php">Dashboard</a>
        </div>
      </div>

      <p class="page-kicker">
        El sistema preparara una imagen raiz limpia, frontal y fiel para construir los mockups posteriores.
        La obra debe conservar su identidad visual, textura, trazo y materialidad.
      </p>

      <form action="start_generate.php" method="post" enctype="multipart/form-data" class="form">

        <label>Imagen principal de la obra completa</label>
        <input type="file" name="main_artwork" accept="image/*" required>
        <small>
          Esta imagen manda sobre composicion, proporcion, encuadre completo e identidad general.
        </small>

        <label>Medidas reales de la obra de arte</label>
        <small>
          No incluyas fondo, mesa, pared, margen de la foto, soporte externo ni elementos que no formen parte de la obra.
        </small>
        <div class="row">
          <input type="number" name="width" step="0.1" placeholder="Ancho real de la obra">
          <input type="number" name="height" step="0.1" placeholder="Alto real de la obra">
          <input type="number" name="depth" step="0.1" placeholder="Profundidad del bastidor">
          <select name="unit">
            <option value="cm" selected>cm</option>
            <option value="in">in</option>
          </select>
        </div>

        <small>
          Beta: la imagen raiz se genera automaticamente con la configuracion estable del sistema.
        </small>

        <button type="submit">Generar obra raiz mejorada</button>

      </form>
    </div>
  </main>
</div>

</body>
</html>
