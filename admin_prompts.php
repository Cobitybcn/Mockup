<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('No tienes acceso a esta seccion.');
}

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    PromptSettings::save($_POST);
    $saved = true;
}

$settings = PromptSettings::all();
$labels = PromptSettings::labels();
$defaultDirectives = PromptSettings::defaultDirectives();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Admin prompts</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .prompt-admin-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .prompt-admin-grid textarea {
            min-height: 260px;
            font-family: Consolas, monospace;
            font-size: 13px;
            line-height: 1.45;
        }

        .admin-note {
            border-left: 4px solid var(--accent);
            background: var(--surface-soft);
            padding: 14px;
            margin-bottom: 22px;
            color: var(--ink);
        }

        details.directive-reference {
            margin-top: 12px;
            border: 1px solid var(--line);
            background: #fff;
            padding: 12px;
        }

        details.directive-reference summary {
            cursor: pointer;
            font-weight: 700;
        }

        .directive-reference textarea {
            min-height: 220px;
            margin-top: 10px;
            background: #f8f8f6;
            color: #333;
        }

        @media (max-width: 980px) {
            .prompt-admin-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
            <li><a class="active" href="admin_prompts.php">Admin prompts</a></li>
            <li><a href="admin_api_keys.php">API keys</a></li>
            <li><a href="account.php">Cuenta y pagos</a></li>
        </ul>

        <div class="nav-section">Archivo</div>
        <ul class="nav">
            <li><a href="mockups.php">Mockups</a></li>
            <li><a href="logout.php">Salir</a></li>
        </ul>
    </aside>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Parametros administrativos de prompts. Estos textos se inyectan en futuras generaciones.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Admin prompts</h1>
                    <p>Ajusta reglas manuales sin modificar codigo.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Parametros guardados.</div>
            <?php endif; ?>

            <div class="admin-note">
                Los cambios afectan prompts nuevos. Estos campos son las directivas activas del sistema. Para propuestas ya analizadas, usa <strong>Actualizar analisis</strong> en la ficha de obra antes de generar nuevos mockups.
            </div>

            <form method="post" class="form">
                <div class="prompt-admin-grid">
                    <?php foreach ($labels as $key => $info): ?>
                        <section>
                            <label for="<?= h($key) ?>"><?= h($info['title']) ?></label>
                            <small><?= h($info['help']) ?></small>
                            <?php if (($info['type'] ?? '') === 'number'): ?>
                                <input id="<?= h($key) ?>" name="<?= h($key) ?>" type="number" min="1" max="10" step="1" value="<?= h($settings[$key] ?? '10') ?>">
                            <?php else: ?>
                                <textarea id="<?= h($key) ?>" name="<?= h($key) ?>"><?= h($settings[$key] ?? '') ?></textarea>
                                <details class="directive-reference">
                                    <summary>Ver valor por defecto para restaurar</summary>
                                    <textarea readonly><?= h($defaultDirectives[$key] ?? '') ?></textarea>
                                </details>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <button type="submit">Guardar parametros</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
