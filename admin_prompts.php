<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!Auth::isAdmin($user)) {
    http_response_code(403);
    exit('You do not have access to this section.');
}

$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    PromptSettings::save($_POST);
    $saved = true;
}

$settings        = PromptSettings::all();
$defaultDirectives = PromptSettings::defaultDirectives();

$adminPromptKeys = [
    'root_artwork_rules_frontal',
    'root_artwork_rules_left',
    'root_artwork_rules_right',
    'artwork_analysis_prompt',
    'mockup_final_request',
    'root_artwork_count',
    'mockup_context_count',
];

$storedDefaultKeys = [];
try {
    $stmt = Database::connection()->query("SELECT `key` FROM app_settings WHERE `key` LIKE 'prompt_default_%'");
    foreach ($stmt->fetchAll() as $row) {
        $settingKey = (string)($row['key'] ?? '');
        $storedDefaultKeys[substr($settingKey, strlen('prompt_default_'))] = true;
    }
} catch (Throwable $e) {
    $storedDefaultKeys = [];
}

foreach ($adminPromptKeys as $key) {
    $currentValue = trim((string)($settings[$key] ?? ''));
    if (!isset($storedDefaultKeys[$key]) && $currentValue !== '') {
        $defaultDirectives[$key] = $currentValue;
    }
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$rootViews = [
    'root_artwork_rules_frontal' => ['label' => 'Vista Frontal', 'badge' => 'V1', 'color' => '#4a7c59'],
    'root_artwork_rules_left'    => ['label' => '3/4 Izquierda', 'badge' => 'V2', 'color' => '#5c6b8a'],
    'root_artwork_rules_right'   => ['label' => '3/4 Derecha',   'badge' => 'V3', 'color' => '#8a5c5c'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>System Prompts — The Artwork Curator</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ── Page layout ── */
        .prompts-workspace {
            display: flex;
            flex-direction: column;
            gap: 32px;
        }

        /* ── Section card ── */
        .prompt-section {
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--surface);
            overflow: hidden;
        }

        .prompt-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 22px;
            background: var(--surface-soft);
            border-bottom: 1px solid var(--line);
            gap: 12px;
        }

        .prompt-section-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .prompt-section-title {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink);
            margin: 0;
        }

        .section-badge {
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 2px 8px;
            border-radius: 20px;
            background: var(--accent-light);
            color: var(--accent);
            border: 1px solid var(--line);
        }

        .prompt-section-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--muted);
        }

        .prompt-section-meta label {
            font-weight: 500;
            color: var(--ink);
            white-space: nowrap;
            margin: 0;
        }

        .prompt-section-meta input[type="number"] {
            width: 58px;
            padding: 4px 8px;
            font-size: 13px;
            text-align: center;
            margin: 0;
        }

        .prompt-section-body {
            padding: 22px;
        }

        /* ── Root artwork: 3-column grid ── */
        .root-views-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        .view-card {
            display: flex;
            flex-direction: column;
            border: 1px solid var(--line);
            border-radius: 5px;
            overflow: hidden;
        }

        .view-card-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .view-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: #fff;
            flex-shrink: 0;
        }

        .view-card-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--ink);
        }

        .view-card-body {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .view-card-help {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.5;
            margin: 0;
        }

        .view-card textarea {
            min-height: 320px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            resize: vertical;
            margin: 0;
        }

        /* ── Default editor (collapsed) ── */
        .default-editor-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: none;
            padding: 6px 0;
            cursor: pointer;
            font-size: 11px;
            color: var(--muted);
            width: 100%;
            text-align: left;
            border-top: 1px dashed var(--line);
            margin-top: 4px;
        }

        .default-editor-toggle:hover {
            color: var(--accent);
        }

        .default-editor-toggle svg {
            transition: transform 0.2s;
            flex-shrink: 0;
        }

        .default-editor-toggle.open svg {
            transform: rotate(90deg);
        }

        .default-editor-panel {
            display: none;
            flex-direction: column;
            gap: 8px;
            padding-top: 8px;
            border-top: 1px solid var(--line);
            margin-top: 4px;
        }

        .default-editor-panel.open {
            display: flex;
        }

        .default-editor-panel textarea {
            min-height: 200px;
            background: var(--surface-soft);
            font-family: Consolas, 'Courier New', monospace;
            font-size: 11px;
            line-height: 1.45;
            color: var(--muted);
            margin: 0;
        }

        .default-editor-panel .restore-btn {
            align-self: flex-start;
            font-size: 11px;
            padding: 5px 12px;
        }

        .default-editor-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--muted);
            letter-spacing: 0.05em;
        }

        /* ── Analysis section ── */
        .analysis-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .analysis-field label {
            font-size: 13px;
            font-weight: 600;
        }

        .analysis-field > small {
            color: var(--muted);
            font-size: 12px;
            margin-top: -4px;
        }

        .analysis-field textarea {
            min-height: 420px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            resize: vertical;
            margin: 0;
        }

        /* ── Placeholder chips ── */
        .placeholder-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            background: none;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 5px 12px;
            cursor: pointer;
            font-size: 11px;
            color: var(--muted);
            align-self: flex-start;
        }

        .placeholder-toggle:hover { color: var(--accent); border-color: var(--accent); }
        .placeholder-toggle svg { transition: transform 0.2s; }
        .placeholder-toggle.open svg { transform: rotate(90deg); }

        .placeholder-chips {
            display: none;
            flex-wrap: wrap;
            gap: 6px;
            padding: 10px;
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
        }

        .placeholder-chips.open { display: flex; }

        .placeholder-chips code {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: 3px;
            padding: 3px 7px;
            font-size: 11px;
            cursor: pointer;
            transition: border-color 0.15s;
        }

        .placeholder-chips code:hover { border-color: var(--accent); color: var(--accent); }

        /* ── Sticky save bar ── */
        .save-bar {
            position: sticky;
            bottom: 0;
            z-index: 10;
            background: var(--surface);
            border-top: 1px solid var(--line);
            padding: 12px 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 0 -22px -22px;
        }

        .save-bar-hint {
            font-size: 12px;
            color: var(--muted);
        }

        .save-bar button {
            margin: 0;
            min-width: 160px;
        }

        /* ── Responsive ── */
        @media (max-width: 1100px) {
            .root-views-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 720px) {
            .root-views-grid { grid-template-columns: 1fr; }
            .prompt-section-header { flex-wrap: wrap; }
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

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>System Prompts</h1>
                    <p>Prompt directives sent to Vertex AI. Each field is the exact text sent — no mixing, no hidden additions.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Settings saved. Next generation will use these prompts.</div>
            <?php endif; ?>

            <form method="post" class="form">
            <div class="prompts-workspace">

                <!-- ══════════════════════════════════════════
                     SECTION 1 — ROOT ARTWORK
                ══════════════════════════════════════════ -->
                <div class="prompt-section">
                    <div class="prompt-section-header">
                        <div class="prompt-section-header-left">
                            <h2 class="prompt-section-title">Obra Raíz</h2>
                            <span class="section-badge">Formulario 1</span>
                        </div>
                        <div class="prompt-section-meta">
                            <label for="root_artwork_count">Versiones a generar</label>
                            <input
                                type="number"
                                id="root_artwork_count"
                                name="root_artwork_count"
                                min="1" max="10" step="1"
                                value="<?= h($settings['root_artwork_count'] ?? '3') ?>"
                            >
                        </div>
                    </div>

                    <div class="prompt-section-body">
                        <div class="root-views-grid">
                            <?php foreach ($rootViews as $key => $view):
                                $activeValue   = (string)($settings[$key] ?? '');
                                $defaultValue  = (string)($defaultDirectives[$key] ?? '');
                                $hasDefault    = trim($defaultValue) !== '';
                            ?>
                            <div class="view-card">
                                <div class="view-card-header">
                                    <span class="view-badge" style="background:<?= h($view['color']) ?>"><?= h($view['badge']) ?></span>
                                    <span class="view-card-title"><?= h($view['label']) ?></span>
                                </div>
                                <div class="view-card-body">
                                    <p class="view-card-help">Prompt completo y exclusivo para esta vista. Se envía a Vertex AI tal cual.</p>
                                    <textarea
                                        id="<?= h($key) ?>"
                                        name="<?= h($key) ?>"
                                    ><?= h($activeValue) ?></textarea>

                                    <?php if ($hasDefault): ?>
                                    <button
                                        type="button"
                                        class="default-editor-toggle"
                                        data-panel="panel-<?= h($key) ?>"
                                        aria-expanded="false"
                                    >
                                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                            <path d="M3 1.5L7 5L3 8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        Valor por defecto
                                    </button>
                                    <div class="default-editor-panel" id="panel-<?= h($key) ?>">
                                        <span class="default-editor-label">Texto de restauración</span>
                                        <textarea
                                            id="<?= h($key) ?>_default"
                                            name="default_directives[<?= h($key) ?>]"
                                        ><?= h($defaultValue) ?></textarea>
                                        <button
                                            type="button"
                                            class="secondary restore-btn use-default-directive"
                                            data-target="<?= h($key) ?>"
                                            data-source="<?= h($key) ?>_default"
                                        >↩ Restaurar</button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div><!-- /root artwork -->


                <!-- ══════════════════════════════════════════
                     SECTION 2 — ARTWORK ANALYSIS
                ══════════════════════════════════════════ -->
                <?php
                    $analysisKey      = 'artwork_analysis_prompt';
                    $analysisActive   = (string)($settings[$analysisKey] ?? '');
                    $analysisDefault  = (string)($defaultDirectives[$analysisKey] ?? '');
                    $analysisHasDefault = trim($analysisDefault) !== '';
                ?>
                <div class="prompt-section">
                    <div class="prompt-section-header">
                        <div class="prompt-section-header-left">
                            <h2 class="prompt-section-title">Análisis de Obra</h2>
                            <span class="section-badge">Formulario 2</span>
                        </div>
                        <div class="prompt-section-meta">
                            <label for="mockup_context_count">Propuestas de contexto</label>
                            <input
                                type="number"
                                id="mockup_context_count"
                                name="mockup_context_count"
                                min="1" max="10" step="1"
                                value="<?= h($settings['mockup_context_count'] ?? '6') ?>"
                            >
                        </div>
                    </div>

                    <div class="prompt-section-body">
                        <div class="analysis-field">
                            <label for="<?= h($analysisKey) ?>">Prompt de análisis</label>
                            <small>Instrucciones para analizar la obra y proponer contextos. Mantener los marcadores <code>{placeholder}</code> y la estructura JSON intacta.</small>

                            <!-- Placeholder reference (collapsed) -->
                            <button type="button" class="placeholder-toggle" id="ph-toggle" aria-expanded="false">
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                    <path d="M3 1.5L7 5L3 8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Variables disponibles
                            </button>
                            <div class="placeholder-chips" id="ph-chips" role="list">
                                <?php foreach (['{artist_profile_prompt}','{artist_statement}','{visual_language}','{recurring_symbols}','{preferred_atmospheres}','{title}','{width_cm}','{height_cm}','{depth_cm}','{notes}','{preferred_style}','{target_market}','{orientation}','{region}','{scale_text}','{context_count}'] as $ph): ?>
                                    <code data-placeholder="<?= h($ph) ?>" title="Click para copiar"><?= h($ph) ?></code>
                                <?php endforeach; ?>
                            </div>

                            <textarea
                                id="<?= h($analysisKey) ?>"
                                name="<?= h($analysisKey) ?>"
                            ><?= h($analysisActive) ?></textarea>

                            <?php if ($analysisHasDefault): ?>
                            <button
                                type="button"
                                class="default-editor-toggle"
                                data-panel="panel-<?= h($analysisKey) ?>"
                                aria-expanded="false"
                            >
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                    <path d="M3 1.5L7 5L3 8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Valor por defecto
                            </button>
                            <div class="default-editor-panel" id="panel-<?= h($analysisKey) ?>">
                                <span class="default-editor-label">Texto de restauración</span>
                                <textarea
                                    id="<?= h($analysisKey) ?>_default"
                                    name="default_directives[<?= h($analysisKey) ?>]"
                                ><?= h($analysisDefault) ?></textarea>
                                <button
                                    type="button"
                                    class="secondary restore-btn use-default-directive"
                                    data-target="<?= h($analysisKey) ?>"
                                    data-source="<?= h($analysisKey) ?>_default"
                                >↩ Restaurar</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div><!-- /analysis -->

                <!-- ══════════════════════════════════════════
                     SECTION 3 — MOCKUP FINAL REQUEST / ADMIN V7
                ══════════════════════════════════════════ -->
                <?php
                    $finalRequestKey      = 'mockup_final_request';
                    $finalRequestActive   = (string)($settings[$finalRequestKey] ?? '');
                    $finalRequestDefault  = (string)($defaultDirectives[$finalRequestKey] ?? '');
                    $finalRequestHasDefault = trim($finalRequestDefault) !== '';
                    $showPlaceholderWarning = (strpos($finalRequestActive, '{{MOCKUP_CONTEXT_PROPOSAL}}') === false);
                ?>
                <div class="prompt-section">
                    <div class="prompt-section-header">
                        <div class="prompt-section-header-left">
                            <h2 class="prompt-section-title">Petición final de mockups / Admin V7</h2>
                            <span class="section-badge" style="background: #1a73e8; color: #fff; border: none;">Admin V7</span>
                        </div>
                    </div>

                    <div class="prompt-section-body">
                        <div class="analysis-field">
                            <label for="<?= h($finalRequestKey) ?>">Prompt final de generación</label>
                            <small>Este prompt es la autoridad final para generar mockups. Debe contener el marcador <code>{{MOCKUP_CONTEXT_PROPOSAL}}</code>, donde el sistema insertará una propuesta de contexto subordinada. No debe devolver JSON.</small>

                            <?php if ($showPlaceholderWarning): ?>
                                <div class="notice error" style="margin-top: 10px; margin-bottom: 10px; border-left: 4px solid var(--danger); background: #FFF5F5; color: var(--danger); padding: 12px; font-size: 13px; border-radius: var(--radius);">
                                    <strong>¡Atención!</strong> Falta el marcador obligatorio <code>{{MOCKUP_CONTEXT_PROPOSAL}}</code>. El generador Phase 2.3 fallará si este marcador no está presente.
                                </div>
                            <?php endif; ?>

                            <textarea
                                id="<?= h($finalRequestKey) ?>"
                                name="<?= h($finalRequestKey) ?>"
                                style="min-height: 420px;"
                            ><?= h($finalRequestActive) ?></textarea>

                            <?php if ($finalRequestHasDefault): ?>
                            <button
                                type="button"
                                class="default-editor-toggle"
                                data-panel="panel-<?= h($finalRequestKey) ?>"
                                aria-expanded="false"
                            >
                                <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                    <path d="M3 1.5L7 5L3 8.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Valor por defecto
                            </button>
                            <div class="default-editor-panel" id="panel-<?= h($finalRequestKey) ?>">
                                <span class="default-editor-label">Texto de restauración</span>
                                <textarea
                                    id="<?= h($finalRequestKey) ?>_default"
                                    name="default_directives[<?= h($finalRequestKey) ?>]"
                                ><?= h($finalRequestDefault) ?></textarea>
                                <button
                                    type="button"
                                    class="secondary restore-btn use-default-directive"
                                    data-target="<?= h($finalRequestKey) ?>"
                                    data-source="<?= h($finalRequestKey) ?>_default"
                                >↩ Restaurar</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div><!-- /mockup final request -->

            </div><!-- /prompts-workspace -->

                <!-- ── Sticky save bar ── -->
                <div class="save-bar">
                    <span class="save-bar-hint">Los cambios se aplican a partir de la próxima generación.</span>
                    <button type="submit">Guardar cambios</button>
                </div>

            </form>
        </div><!-- /workspace -->
    </main>
</div>

<script>
// Toggle default editor panels
document.querySelectorAll('.default-editor-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
        const panelId = btn.dataset.panel;
        const panel = document.getElementById(panelId);
        if (!panel) return;
        const isOpen = panel.classList.toggle('open');
        btn.classList.toggle('open', isOpen);
        btn.setAttribute('aria-expanded', isOpen);
    });
});

// Toggle placeholder chips panel
const phToggle = document.getElementById('ph-toggle');
const phChips  = document.getElementById('ph-chips');
if (phToggle && phChips) {
    phToggle.addEventListener('click', () => {
        const isOpen = phChips.classList.toggle('open');
        phToggle.classList.toggle('open', isOpen);
        phToggle.setAttribute('aria-expanded', isOpen);
    });
}

// Click placeholder chip → copy to clipboard & flash
document.querySelectorAll('.placeholder-chips code').forEach((chip) => {
    chip.addEventListener('click', () => {
        const text = chip.dataset.placeholder || chip.textContent;
        navigator.clipboard?.writeText(text).then(() => {
            chip.style.borderColor = 'var(--accent)';
            chip.style.color = 'var(--accent)';
            setTimeout(() => { chip.style.borderColor = ''; chip.style.color = ''; }, 800);
        });
    });
});

// Restore from default
document.querySelectorAll('.use-default-directive').forEach((btn) => {
    btn.addEventListener('click', () => {
        const target = document.getElementById(btn.dataset.target || '');
        const source = document.getElementById(btn.dataset.source || '');
        if (target && source) {
            target.value = source.value;
            target.focus();
        }
    });
});
</script>
</body>
</html>
