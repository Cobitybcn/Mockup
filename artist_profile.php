<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        ArtistProfile::saveForUser((int)$user['id'], $_POST);
        $saved = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$profile = ArtistProfile::findForUser((int)$user['id']);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function field_value(array $profile, string $field): string
{
    return h($profile[$field] ?? '');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Perfil de artista</title>
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
            <li><a class="active" href="artist_profile.php">Perfil de artista</a></li>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>

        <div class="nav-section">Archivo</div>
        <ul class="nav">
            <li><a href="dashboard.php#obras">Obras raiz</a></li>
            <li><a href="mockups.php">Mockups</a></li>
            <li><a href="logout.php">Salir</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            El perfil guia contexto, publico y atmosfera. Nunca modifica la obra raiz.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Perfil de artista</h1>
                    <p>Define el universo visual que la IA debe respetar al proponer mockups.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <p class="notice">Perfil guardado correctamente.</p>
            <?php endif; ?>

            <?php if ($error): ?>
                <p class="notice error"><?= h($error) ?></p>
            <?php endif; ?>

            <form class="form profile-form" method="post">
                <label>Nombre artistico</label>
                <input type="text" name="artist_name" value="<?= field_value($profile, 'artist_name') ?>">

                <label>Bio corta</label>
                <textarea name="short_bio" rows="3"><?= field_value($profile, 'short_bio') ?></textarea>

                <label>Statement</label>
                <textarea name="statement" rows="5" placeholder="Que busca, investiga o tensiona tu obra."><?= field_value($profile, 'statement') ?></textarea>

                <label>Lenguaje visual</label>
                <textarea name="visual_language" rows="4" placeholder="Abstracto, arquitectonico, gestual, material, surreal, color field, etc."><?= field_value($profile, 'visual_language') ?></textarea>

                <label>Materiales y proceso</label>
                <textarea name="materials" rows="4" placeholder="Oleo, acrilico, espatula, incisiones, capas, veladuras, tela, madera, etc."><?= field_value($profile, 'materials') ?></textarea>

                <label>Temas recurrentes</label>
                <textarea name="recurring_themes" rows="4" placeholder="Memoria, paisaje mental, arquitectura, cuerpo, silencio, tension, territorio..."><?= field_value($profile, 'recurring_themes') ?></textarea>

                <label>Paleta habitual o temperatura emocional</label>
                <textarea name="palette_notes" rows="3" placeholder="Rojos oscuros, azules frios, tierras, alta saturacion, paletas nocturnas..."><?= field_value($profile, 'palette_notes') ?></textarea>

                <label>Publico objetivo</label>
                <textarea name="target_audience" rows="3" placeholder="Coleccionistas, galerias, interioristas, arquitectos, hoteles boutique..."><?= field_value($profile, 'target_audience') ?></textarea>

                <label>Regiones preferidas</label>
                <textarea name="preferred_regions" rows="3" placeholder="Europa, Estados Unidos, Paris, Londres, New York, Miami, temporada especifica..."><?= field_value($profile, 'preferred_regions') ?></textarea>

                <label>Contextos deseados</label>
                <textarea name="preferred_contexts" rows="4" placeholder="Galerias, museo, townhouse, coleccion privada, art fair, estudio, arquitectura historica..."><?= field_value($profile, 'preferred_contexts') ?></textarea>

                <label>Contextos prohibidos o no deseados</label>
                <textarea name="forbidden_contexts" rows="4" placeholder="Cocinas, dormitorios comunes, minimalismo frio, brutalismo, lujo ostentoso, decoracion generica..."><?= field_value($profile, 'forbidden_contexts') ?></textarea>

                <label>Posicionamiento comercial</label>
                <textarea name="commercial_positioning" rows="4" placeholder="Premium, galerias, coleccionismo joven, obra institucional, interiorismo sofisticado..."><?= field_value($profile, 'commercial_positioning') ?></textarea>

                <button type="submit">Guardar perfil</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
