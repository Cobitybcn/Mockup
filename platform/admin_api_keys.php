<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('You do not have access to this section.');
}

$saved = false;
$saveError = '';
$csrf = Auth::csrfToken('admin_api_keys');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Auth::requireValidCsrf((string)($_POST['csrf'] ?? ''), 'admin_api_keys');
        ProviderSettings::save($_POST);
        $saved = true;
    } catch (Throwable $error) {
        $saveError = $error->getMessage();
    }
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
    return trim($value) === '' ? t('Not Configured', 'No configurado') : t('Configured', 'Configurado');
}

$geminiImagePlans = [
    'gemini-3.1-flash-image' => t('Default / fast and economical - gemini-3.1-flash-image', 'Predeterminado / rápido y económico - gemini-3.1-flash-image'),
    'gemini-3-pro-image' => t('Premium / maximum quality - gemini-3-pro-image', 'Premium / máxima calidad - gemini-3-pro-image'),
    'gemini-2.5-flash-image' => t('Experimental / more economical - gemini-2.5-flash-image', 'Experimental / más económico - gemini-2.5-flash-image'),
];

?>
<!doctype html>
<html lang="<?= h(Translator::locale($user)) ?>">
<head>
    <meta charset="utf-8">
    <title><?= h(t('API Settings - Artwork Mockups', 'Configuración de API - Artwork Mockups')) ?></title>
    <link rel="stylesheet" href="style.css">
    <style>
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .settings-card {
            border: 1px solid var(--line);
            background: var(--surface);
            padding: 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .settings-card h2 {
            margin-bottom: 6px;
        }

        .key-state {
            display: inline-block;
            margin-bottom: 10px;
            padding: 3px 8px;
            border: 1px solid var(--line-dark);
            color: var(--muted);
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-radius: var(--radius);
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

        .notice.error { border-color: #d8aaa5; background: #f6e8e6; color: #7d342e; }

        @media (max-width: 980px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            <?= h(t('Private configuration of AI providers and credentials.', 'Configuración privada de proveedores de IA y credenciales.')) ?>
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1><?= h(t('API Settings', 'Configuración de API')) ?></h1>
                    <p><?= h(t('Manage keys and providers without modifying the source code.', 'Gestioná claves y proveedores sin modificar el código fuente.')) ?></p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="admin_prompts.php"><?= h(t('System Prompts', 'Prompts del sistema')) ?></a>
                    <a class="button-link secondary" href="root_album.php"><?= h(t('ArtWorks', 'Obras')) ?></a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice"><?= h(t('Configuration saved successfully.', 'Configuración guardada correctamente.')) ?></div>
            <?php endif; ?>
            <?php if ($saveError !== ''): ?>
                <div class="notice error" role="alert"><?= h($saveError) ?></div>
            <?php endif; ?>

            <form method="post" class="form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <div class="settings-grid">
                    <section class="settings-card">
                        <h2><?= h(t('API Mode', 'Modo de API')) ?></h2>
                        <p><?= h(t('Control if the app uses mock responses or real API calls.', 'Controlá si la app usa respuestas simuladas o llamadas reales a la API.')) ?></p>

                        <label for="app_mode"><?= h(t('Application Mode', 'Modo de aplicación')) ?></label>
                        <select id="app_mode" name="app_mode">
                            <option value="mock" <?= ($settings['app_mode'] ?? '') === 'mock' ? 'selected' : '' ?>><?= h(t('Mock Mode (simulated, no API)', 'Modo simulado (sin API)')) ?></option>
                            <option value="gemini" <?= ($settings['app_mode'] ?? '') === 'gemini' ? 'selected' : '' ?>><?= h(t('Gemini Mode (real Gemini/Vertex AI)', 'Modo Gemini (Gemini/Vertex AI real)')) ?></option>
                            <option value="openai" <?= in_array($settings['app_mode'] ?? '', ['openai'], true) ? 'selected' : '' ?>><?= h(t('OpenAI Mode (real OpenAI)', 'Modo OpenAI (OpenAI real)')) ?></option>
                        </select>

                        <label for="image_provider"><?= h(t('Image Provider', 'Proveedor de imágenes')) ?></label>
                        <select id="image_provider" name="image_provider">
                            <option value="gemini" <?= ($settings['image_provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                            <option value="openai" <?= ($settings['image_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                        </select>

                        <label class="checkbox-line">
                            <input type="checkbox" name="allow_real_api" value="1" <?= !empty($settings['allow_real_api']) ? 'checked' : '' ?>>
                            <?= h(t('Allow real API calls', 'Permitir llamadas reales a la API')) ?>
                        </label>
                    </section>

                    <section class="settings-card">
                        <h2>OpenAI</h2>
                        <span class="key-state"><?= h(key_status($openAIKey)) ?></span>

                        <label for="openai_api_key"><?= h(t('API Key', 'Clave de API')) ?></label>
                        <input id="openai_api_key" name="openai_api_key" type="password" value="" placeholder="<?= h(t('Paste new key to replace', 'Pegá una clave nueva para reemplazarla')) ?>">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_openai_api_key" value="1">
                            <?= h(t('Clear saved API key', 'Borrar la clave de API guardada')) ?>
                        </label>

                        <label for="openai_image_model"><?= h(t('Image Model', 'Modelo de imagen')) ?></label>
                        <input id="openai_image_model" name="openai_image_model" type="text" value="<?= h($settings['openai_image_model'] ?? 'gpt-image-1') ?>">

                        <label for="openai_analysis_model"><?= h(t('Analysis Model', 'Modelo de análisis')) ?></label>
                        <input id="openai_analysis_model" name="openai_analysis_model" type="text" value="<?= h($settings['openai_analysis_model'] ?? 'gpt-4.1-mini') ?>">

                        <label for="openai_image_quality"><?= h(t('Image Quality', 'Calidad de imagen')) ?></label>
                        <input id="openai_image_quality" name="openai_image_quality" type="text" value="<?= h($settings['openai_image_quality'] ?? 'low') ?>">

                        <label for="openai_image_size"><?= h(t('Image Size', 'Tamaño de imagen')) ?></label>
                        <input id="openai_image_size" name="openai_image_size" type="text" value="<?= h($settings['openai_image_size'] ?? '1024x1024') ?>">
                    </section>

                    <section class="settings-card">
                        <h2>Gemini</h2>
                        <span class="key-state"><?= h(key_status($geminiKey)) ?></span>

                        <label for="gemini_api_key"><?= h(t('API Key', 'Clave de API')) ?></label>
                        <input id="gemini_api_key" name="gemini_api_key" type="password" value="" placeholder="<?= h(t('Paste new key to replace', 'Pegá una clave nueva para reemplazarla')) ?>">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_gemini_api_key" value="1">
                            <?= h(t('Clear saved API key', 'Borrar la clave de API guardada')) ?>
                        </label>

                        <label for="gemini_image_model"><?= h(t('Gemini Image Plan', 'Plan de imagen de Gemini')) ?></label>
                        <select id="gemini_image_model" name="gemini_image_model">
                            <?php foreach ($geminiImagePlans as $model => $label): ?>
                                <option value="<?= h($model) ?>" <?= ($settings['gemini_image_model'] ?? 'gemini-3.1-flash-image') === $model ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="checkbox-line" style="margin-top: 14px;">
                            <?= h(t('Default uses Gemini 3.1 Flash Image. Premium uses Gemini 3 Pro Image. Experimental uses Gemini 2.5 Flash Image if available in Vertex.', 'Predeterminado usa Gemini 3.1 Flash Image. Premium usa Gemini 3 Pro Image. Experimental usa Gemini 2.5 Flash Image si está disponible en Vertex.')) ?>
                        </p>

                    </section>

                    <section class="settings-card">
                        <h2><?= h(t('Batch Performance', 'Rendimiento por lote')) ?></h2>
                        <p><?= h(t('Control how many automatic mockups are generated at the same time.', 'Controlá cuántos mockups automáticos se generan al mismo tiempo.')) ?></p>

                        <label for="mockup_worker_count"><?= h(t('Parallel Mockup Workers', 'Workers de mockups en paralelo')) ?></label>
                        <input
                            id="mockup_worker_count"
                            name="mockup_worker_count"
                            type="number"
                            min="1"
                            max="8"
                            step="1"
                            value="<?= h($settings['mockup_worker_count'] ?? '4') ?>"
                        >
                        <p class="checkbox-line" style="margin-top: 14px;">
                            <?= h(t('Recommended testing path: 4, then 6 or 8 if Vertex remains stable. Higher concurrency tends to trigger Vertex quota errors.', 'Camino de prueba recomendado: 4, luego 6 u 8 si Vertex se mantiene estable. Mayor concurrencia tiende a disparar errores de cuota de Vertex.')) ?>
                        </p>
                    </section>
                </div>

                <section class="form-section">
                    <h2><?= h(t('Media Processing', 'Procesamiento de medios')) ?></h2>
                    <label for="ffmpeg_binary_path"><?= h(t('FFmpeg binary path', 'Ruta del binario de FFmpeg')) ?></label>
                    <input id="ffmpeg_binary_path" name="ffmpeg_binary_path" type="text" value="<?= h($settings['ffmpeg_binary_path'] ?? '') ?>" placeholder="<?= h(t('Leave empty to use ffmpeg from PATH', 'Dejar vacío para usar ffmpeg del PATH')) ?>">
                </section>

                <button type="submit"><?= h(t('Save API Settings', 'Guardar configuración de API')) ?></button>
            </form>

            <section class="panel" style="margin-top: 28px;">
                <h2><?= h(t('Maintenance', 'Mantenimiento')) ?></h2>
                <p style="color: var(--muted); margin-bottom: 16px;">
                    <?= h(t('Automatic cleanup of completed job directories older than 30 days.', 'Limpieza automática de directorios de tareas completadas con más de 30 días.')) ?>
                    <?= h(t('Active jobs (queued or processing) are never deleted.', 'Las tareas activas (en cola o en proceso) nunca se eliminan.')) ?>
                </p>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button id="cleanup-btn" type="button" class="button-link secondary"><?= t('Clean Old Jobs (&gt;30 days)', 'Limpiar tareas antiguas (&gt;30 días)') ?></button>
                    <span id="cleanup-result" style="font-size: 13px; color: var(--muted);"></span>
                </div>
                <script>
                    const adminApiKeysI18n = {
                        cleaning: <?= json_encode(t('Cleaning...', 'Limpiando...')) ?>,
                        cleanOldJobs: <?= json_encode(t('Clean Old Jobs (>30 days)', 'Limpiar tareas antiguas (>30 días)')) ?>,
                        done: <?= json_encode(t('Done.', 'Listo.')) ?>,
                        errorDuringCleanup: <?= json_encode(t('Error during cleanup.', 'Error durante la limpieza.')) ?>,
                    };
                    document.getElementById('cleanup-btn').addEventListener('click', function() {
                        const btn = this;
                        const result = document.getElementById('cleanup-result');
                        btn.disabled = true;
                        btn.textContent = adminApiKeysI18n.cleaning;
                        result.textContent = '';

                        fetch('cleanup_jobs.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'ajax=1&max_age_days=30'
                        })
                        .then(r => r.json())
                        .then(data => {
                            result.textContent = data.summary || (data.error || adminApiKeysI18n.done);
                            btn.disabled = false;
                            btn.textContent = adminApiKeysI18n.cleanOldJobs;
                        })
                        .catch(() => {
                            result.textContent = adminApiKeysI18n.errorDuringCleanup;
                            btn.disabled = false;
                            btn.textContent = adminApiKeysI18n.cleanOldJobs;
                        });
                    });
                </script>
            </section>
        </div>
    </main>
</div>
</body>
</html>
