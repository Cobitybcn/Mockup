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
$stripeStatus = ProviderSettings::stripeConnectStatus();
$stripeRedirectUri = ProviderSettings::stripeConnectRedirectUri();
$stripePublicUrl = trim(app_env('APP_PUBLIC_URL', '')) ?: 'https://artworkmockups.com';
$stripeWebhookUrl = rtrim($stripePublicUrl, '/') . '/integrations/stripe/webhook/';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function key_status(string $value): string
{
    return trim($value) === '' ? 'Not Configured' : 'Configured';
}

$geminiImagePlans = [
    'gemini-3.1-flash-image' => 'Default / fast and economical - gemini-3.1-flash-image',
    'gemini-3-pro-image' => 'Premium / maximum quality - gemini-3-pro-image',
    'gemini-2.5-flash-image' => 'Experimental / more economical - gemini-2.5-flash-image',
];

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>API Settings - Artwork Mockups</title>
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

        .stripe-platform-panel {
            margin-top: 18px;
            padding: clamp(22px, 3vw, 34px);
            border: 1px solid var(--line);
            background: var(--surface);
        }

        .stripe-platform-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 24px;
            margin-bottom: 22px;
        }

        .stripe-platform-heading h2 { margin-bottom: 5px; }
        .stripe-platform-heading p { max-width: 760px; margin: 0; color: var(--muted); }
        .stripe-platform-status {
            flex: 0 0 auto;
            padding: 7px 10px;
            border: 1px solid var(--line-dark);
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }
        .stripe-platform-status.is-ready { border-color: #9caf94; background: #e7efe4; color: #40543c; }
        .stripe-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 20px; }
        .stripe-field-note { margin: -7px 0 12px; color: var(--muted); font-size: 12px; }
        .stripe-field-wide { grid-column: 1 / -1; }
        .stripe-webhook-guide { margin-top: 20px; padding-top: 18px; border-top: 1px solid var(--line); }
        .stripe-webhook-guide summary { cursor: pointer; font-weight: 650; }
        .stripe-webhook-guide code { overflow-wrap: anywhere; }
        .stripe-webhook-guide ul { columns: 2; color: var(--muted); font-size: 13px; }

        .notice.error { border-color: #d8aaa5; background: #f6e8e6; color: #7d342e; }

        @media (max-width: 980px) {
            .settings-grid {
                grid-template-columns: 1fr;
            }
            .stripe-fields { grid-template-columns: 1fr; }
            .stripe-field-wide { grid-column: auto; }
            .stripe-platform-heading { flex-direction: column; }
            .stripe-webhook-guide ul { columns: 1; }
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
            Private configuration of AI providers and platform integrations.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>API Settings</h1>
                    <p>Manage keys and providers without modifying the source code.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="admin_prompts.php">System Prompts</a>
                    <a class="button-link secondary" href="root_album.php">ArtWorks</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Configuration saved successfully.</div>
            <?php endif; ?>
            <?php if ($saveError !== ''): ?>
                <div class="notice error" role="alert"><?= h($saveError) ?></div>
            <?php endif; ?>

            <form method="post" class="form" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <div class="settings-grid">
                    <section class="settings-card">
                        <h2>API Mode</h2>
                        <p>Control if the app uses mock responses or real API calls.</p>

                        <label for="app_mode">Application Mode</label>
                        <select id="app_mode" name="app_mode">
                            <option value="mock" <?= ($settings['app_mode'] ?? '') === 'mock' ? 'selected' : '' ?>>Mock Mode (simulated, no API)</option>
                            <option value="gemini" <?= ($settings['app_mode'] ?? '') === 'gemini' ? 'selected' : '' ?>>Gemini Mode (real Gemini/Vertex AI)</option>
                            <option value="openai" <?= in_array($settings['app_mode'] ?? '', ['openai'], true) ? 'selected' : '' ?>>OpenAI Mode (real OpenAI)</option>
                        </select>

                        <label for="image_provider">Image Provider</label>
                        <select id="image_provider" name="image_provider">
                            <option value="gemini" <?= ($settings['image_provider'] ?? '') === 'gemini' ? 'selected' : '' ?>>Gemini</option>
                            <option value="openai" <?= ($settings['image_provider'] ?? '') === 'openai' ? 'selected' : '' ?>>OpenAI</option>
                        </select>

                        <label class="checkbox-line">
                            <input type="checkbox" name="allow_real_api" value="1" <?= !empty($settings['allow_real_api']) ? 'checked' : '' ?>>
                            Allow real API calls
                        </label>
                    </section>

                    <section class="settings-card">
                        <h2>OpenAI</h2>
                        <span class="key-state"><?= h(key_status($openAIKey)) ?></span>

                        <label for="openai_api_key">API Key</label>
                        <input id="openai_api_key" name="openai_api_key" type="password" value="" placeholder="Paste new key to replace">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_openai_api_key" value="1">
                            Clear saved API key
                        </label>

                        <label for="openai_image_model">Image Model</label>
                        <input id="openai_image_model" name="openai_image_model" type="text" value="<?= h($settings['openai_image_model'] ?? 'gpt-image-1') ?>">

                        <label for="openai_analysis_model">Analysis Model</label>
                        <input id="openai_analysis_model" name="openai_analysis_model" type="text" value="<?= h($settings['openai_analysis_model'] ?? 'gpt-4.1-mini') ?>">

                        <label for="openai_image_quality">Image Quality</label>
                        <input id="openai_image_quality" name="openai_image_quality" type="text" value="<?= h($settings['openai_image_quality'] ?? 'low') ?>">

                        <label for="openai_image_size">Image Size</label>
                        <input id="openai_image_size" name="openai_image_size" type="text" value="<?= h($settings['openai_image_size'] ?? '1024x1024') ?>">
                    </section>

                    <section class="settings-card">
                        <h2>Gemini</h2>
                        <span class="key-state"><?= h(key_status($geminiKey)) ?></span>

                        <label for="gemini_api_key">API Key</label>
                        <input id="gemini_api_key" name="gemini_api_key" type="password" value="" placeholder="Paste new key to replace">
                        <label class="checkbox-line">
                            <input type="checkbox" name="clear_gemini_api_key" value="1">
                            Clear saved API key
                        </label>

                        <label for="gemini_image_model">Gemini Image Plan</label>
                        <select id="gemini_image_model" name="gemini_image_model">
                            <?php foreach ($geminiImagePlans as $model => $label): ?>
                                <option value="<?= h($model) ?>" <?= ($settings['gemini_image_model'] ?? 'gemini-3.1-flash-image') === $model ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="checkbox-line" style="margin-top: 14px;">
                            Default uses Gemini 3.1 Flash Image. Premium uses Gemini 3 Pro Image. Experimental uses Gemini 2.5 Flash Image if available in Vertex.
                        </p>

                    </section>

                    <section class="settings-card">
                        <h2>Batch Performance</h2>
                        <p>Control how many automatic mockups are generated at the same time.</p>

                        <label for="mockup_worker_count">Parallel Mockup Workers</label>
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
                            Recommended testing path: 4, then 6 or 8 if Vertex remains stable. Higher concurrency tends to trigger Vertex quota errors.
                        </p>
                    </section>
                </div>

                <section class="stripe-platform-panel" id="stripe-connect">
                    <div class="stripe-platform-heading">
                        <div>
                            <h2>Stripe Connect · Platform</h2>
                            <p>Configure this once for Artwork Mockups. Every artist then authorizes a separate Stripe Standard account from their own Store Admin; artists never enter platform API keys.</p>
                        </div>
                        <span class="stripe-platform-status <?= $stripeStatus['ready'] ? 'is-ready' : '' ?>">
                            <?= $stripeStatus['ready'] ? h(ucfirst($stripeStatus['mode']) . ' mode ready') : 'Setup incomplete' ?>
                        </span>
                    </div>

                    <div class="stripe-fields">
                        <div>
                            <label for="stripe_connect_secret_key">Platform secret key</label>
                            <input id="stripe_connect_secret_key" name="stripe_connect_secret_key" type="password" value="" placeholder="<?= $stripeStatus['secret_key'] ? 'Configured — paste to replace' : 'sk_test_… or sk_live_…' ?>" autocomplete="new-password">
                            <p class="stripe-field-note">Used server-side to create Checkout sessions on connected artist accounts.</p>
                            <label class="checkbox-line"><input type="checkbox" name="clear_stripe_connect_secret_key" value="1">Clear saved secret key</label>
                        </div>
                        <div>
                            <label for="stripe_connect_client_id">Connect client ID</label>
                            <input id="stripe_connect_client_id" name="stripe_connect_client_id" type="password" value="" placeholder="<?= $stripeStatus['client_id'] ? 'Configured — paste to replace' : 'ca_…' ?>" autocomplete="new-password">
                            <p class="stripe-field-note">Identifies the platform during each artist authorization.</p>
                            <label class="checkbox-line"><input type="checkbox" name="clear_stripe_connect_client_id" value="1">Clear saved client ID</label>
                        </div>
                        <div>
                            <label for="stripe_connect_webhook_secret">Connected accounts webhook secret</label>
                            <input id="stripe_connect_webhook_secret" name="stripe_connect_webhook_secret" type="password" value="" placeholder="<?= $stripeStatus['webhook_secret'] ? 'Configured — paste to replace' : 'whsec_…' ?>" autocomplete="new-password">
                            <p class="stripe-field-note">Verifies payment and account-status events received from Stripe.</p>
                            <label class="checkbox-line"><input type="checkbox" name="clear_stripe_connect_webhook_secret" value="1">Clear saved webhook secret</label>
                        </div>
                        <div>
                            <label for="stripe_connect_redirect_uri">OAuth redirect URI</label>
                            <input id="stripe_connect_redirect_uri" name="stripe_connect_redirect_uri" type="url" value="<?= h($stripeRedirectUri) ?>" placeholder="https://artworkmockups.com/site-admin/stripe-connect-callback.php">
                            <p class="stripe-field-note">This URL must exactly match a redirect URI allowed in Stripe Connect.</p>
                        </div>
                    </div>

                    <details class="stripe-webhook-guide">
                        <summary>Webhook endpoint and required events</summary>
                        <p>In Stripe, create a webhook for <strong>Connected accounts</strong> pointing to <code><?= h($stripeWebhookUrl) ?></code>.</p>
                        <ul>
                            <li><code>checkout.session.completed</code></li>
                            <li><code>checkout.session.async_payment_succeeded</code></li>
                            <li><code>checkout.session.expired</code></li>
                            <li><code>checkout.session.async_payment_failed</code></li>
                            <li><code>account.updated</code></li>
                            <li><code>account.application.deauthorized</code></li>
                        </ul>
                    </details>
                </section>

                <section class="form-section">
                    <h2>Media Processing</h2>
                    <label for="ffmpeg_binary_path">FFmpeg binary path</label>
                    <input id="ffmpeg_binary_path" name="ffmpeg_binary_path" type="text" value="<?= h($settings['ffmpeg_binary_path'] ?? '') ?>" placeholder="Leave empty to use ffmpeg from PATH">
                </section>

                <button type="submit">Save API Settings</button>
            </form>

            <!-- Punto #5: sección de mantenimiento -->
            <section class="panel" style="margin-top: 28px;">
                <h2>Maintenance</h2>
                <p style="color: var(--muted); margin-bottom: 16px;">
                    Automatic cleanup of completed job directories older than 30 days.
                    Active jobs (queued or processing) are never deleted.
                </p>
                <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                    <button id="cleanup-btn" type="button" class="button-link secondary">Clean Old Jobs (&gt;30 days)</button>
                    <span id="cleanup-result" style="font-size: 13px; color: var(--muted);"></span>
                </div>
                <script>
                    document.getElementById('cleanup-btn').addEventListener('click', function() {
                        const btn = this;
                        const result = document.getElementById('cleanup-result');
                        btn.disabled = true;
                        btn.textContent = 'Cleaning...';
                        result.textContent = '';

                        fetch('cleanup_jobs.php', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'ajax=1&max_age_days=30'
                        })
                        .then(r => r.json())
                        .then(data => {
                            result.textContent = data.summary || (data.error || 'Done.');
                            btn.disabled = false;
                            btn.textContent = 'Clean Old Jobs (>30 days)';
                        })
                        .catch(() => {
                            result.textContent = 'Error during cleanup.';
                            btn.disabled = false;
                            btn.textContent = 'Clean Old Jobs (>30 days)';
                        });
                    });
                </script>
            </section>
        </div>
    </main>
</div>
</body>
</html>
