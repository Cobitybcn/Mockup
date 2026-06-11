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
    ProviderSettings::save($_POST);
    $saved = true;
}

$settings = ProviderSettings::all();
$openAIKey = ProviderSettings::openAIAPIKey();
$geminiKey = ProviderSettings::geminiAPIKey();

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function key_status(string $value): string
{
    return trim($value) === '' ? 'Sin configurar' : 'Configurada';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Admin API keys</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .settings-card {
            border: 1px solid var(--line);
            background: #fff;
            padding: 22px;
        }

        .settings-card h2 {
            margin-bottom: 6px;
        }

        .key-state {
            display: inline-block;
            margin-bottom: 10px;
            padding: 4px 8px;
            border: 1px solid var(--line-dark);
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .checkbox-line {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .checkbox-line input {
            width: auto;
        }

        @media (max-width: 980px) {
            .settings-grid {
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
            <li><a href="admin_prompts.php">Admin prompts</a></li>
            <li><a class="active" href="admin_api_keys.php">API keys</a></li>
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
            Configuracion privada de proveedores de IA y credenciales.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>API keys</h1>
                    <p>Administra claves y proveedor sin modificar codigo.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="admin_prompts.php">Admin prompts</a>
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Configuracion guardada.</div>
            <?php endif; ?>

            <form method="post" class="form" autocomplete="off">
                <div class="settings-grid">
                    <section class="settings-card">
                        <h2>Modo de API</h2>
                        <p>Controla si la app usa respuestas mock o llamadas reales.</p>

                        <label for="app_mode">Modo de aplicacion</label>
                        <select id="app_mode" name="app_mode">
                            <option value="mock" <?= ($settings['app_mode'] ?? '') === 'mock' ? 'selected' : '' ?>>Mock</option>
                            <option value="openai" <?= ($settings['app_mode'] ?? '') === 'openai' ? 'selected' : '' ?>>API real</option>
                        </select>

                        <label for="image_provider">Proveedor de imagenes</label>
                        <select id="image_provider" name="image_provider">
                            <option value="gemini" <?= ($settings['image_provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                            <option value="openai" <?= ($settings['image_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                        </select>

                        <label class="checkbox-line">
                            <input type="checkbox" name="allow_real_api" value="1" <?= !empty($settings['allow_real_api']) ? 'checked' : '' ?>>
                            Permitir consumo real de API
                        </label>
                    </section>

                    <section class="settings-card">
                        <h2>OpenAI</h2>
                        <span class="key-state"><?= h(key_status($openAIKey)) ?></span>

                        <label for="openai_api_key">API key</label>
                        <input id="openai_api_key" name="openai_api_key" type="password" value="" placeholder="Pegar nueva clave para reemplazar">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_openai_api_key" value="1">
                            Borrar clave guardada
                        </label>

                        <label for="openai_image_model">Modelo de imagen</label>
                        <input id="openai_image_model" name="openai_image_model" type="text" value="<?= h($settings['openai_image_model'] ?? 'gpt-image-1') ?>">

                        <label for="openai_analysis_model">Modelo de analisis</label>
                        <input id="openai_analysis_model" name="openai_analysis_model" type="text" value="<?= h($settings['openai_analysis_model'] ?? 'gpt-4.1-mini') ?>">

                        <label for="openai_image_quality">Calidad de imagen</label>
                        <input id="openai_image_quality" name="openai_image_quality" type="text" value="<?= h($settings['openai_image_quality'] ?? 'low') ?>">

                        <label for="openai_image_size">Tamano de imagen</label>
                        <input id="openai_image_size" name="openai_image_size" type="text" value="<?= h($settings['openai_image_size'] ?? '1024x1024') ?>">
                    </section>

                    <section class="settings-card">
                        <h2>Gemini</h2>
                        <span class="key-state"><?= h(key_status($geminiKey)) ?></span>

                        <label for="gemini_api_key">API key</label>
                        <input id="gemini_api_key" name="gemini_api_key" type="password" value="" placeholder="Pegar nueva clave para reemplazar">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_gemini_api_key" value="1">
                            Borrar clave guardada
                        </label>

                        <label for="gemini_image_model">Modelo de imagen</label>
                        <input id="gemini_image_model" name="gemini_image_model" type="text" value="<?= h($settings['gemini_image_model'] ?? 'gemini-2.5-flash-image') ?>">
                    </section>
                </div>

                <button type="submit">Guardar configuracion</button>
            </form>
        </div>
    </main>
</div>
</body>
</html>
