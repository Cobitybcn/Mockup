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

$settings = PromptSettings::all();
$adminPromptKeys = [
    'root_artwork_rules',
    'artwork_analysis_prompt',
    'root_artwork_count',
    'mockup_context_count',
];
$labels = array_intersect_key(PromptSettings::labels(), array_flip($adminPromptKeys));
$defaultDirectives = PromptSettings::defaultDirectives();
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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>System Prompts - The Artwork Curator</title>
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
            border-left: 3px solid var(--accent);
            background: var(--surface-soft);
            padding: 14px 18px;
            margin-bottom: 22px;
            color: var(--ink);
            border-radius: 0 var(--radius) var(--radius) 0;
            font-size: 13px;
        }

        .placeholder-reference {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: -8px 0 22px;
        }

        .placeholder-reference code {
            border: 1px solid var(--line);
            background: var(--surface);
            border-radius: 4px;
            padding: 5px 7px;
            font-size: 12px;
        }

        .default-directive-editor {
            margin-top: 12px;
            border: 1px solid var(--line);
            background: var(--surface);
            padding: 12px;
            border-radius: var(--radius);
        }

        .default-directive-editor strong {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .default-directive-editor textarea {
            min-height: 220px;
            margin-top: 10px;
            background: var(--surface-soft);
        }

        .directive-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 10px;
        }

        .directive-actions button {
            width: auto;
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
    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>

        <div class="alert-strip">
            Administrative prompt parameters. These directives are injected into future generation processes.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>System Prompts</h1>
                    <p>Adjust prompt rulesets without modifying the source code.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($saved): ?>
                <div class="notice">Parameters saved successfully.</div>
            <?php endif; ?>

            <div class="admin-note">
                Changes apply to new prompts. These fields serve as the active system directives. For already analyzed proposals, click <strong>Recalculate Analysis</strong> on the artwork page before generating new mockups.
            </div>

            <div class="placeholder-reference" aria-label="Available prompt placeholders">
                <?php foreach (['{artist_profile_prompt}', '{artist_statement}', '{visual_language}', '{recurring_symbols}', '{preferred_atmospheres}', '{title}', '{width_cm}', '{height_cm}', '{depth_cm}', '{notes}', '{preferred_style}', '{target_market}', '{orientation}', '{region}', '{scale_text}', '{context_count}'] as $placeholder): ?>
                    <code><?= h($placeholder) ?></code>
                <?php endforeach; ?>
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
                                <?php if (trim((string)($defaultDirectives[$key] ?? '')) !== ''): ?>
                                    <div class="directive-actions">
                                        <button type="button" class="secondary use-default-directive" data-target="<?= h($key) ?>" data-source="<?= h($key) ?>_default">Restaurar desde el valor por defecto</button>
                                    </div>
                                <?php endif; ?>
                                <div class="default-directive-editor">
                                    <strong>Valor por defecto editable</strong>
                                    <small>Este texto se guarda como referencia de restauracion para este bloque.</small>
                                    <textarea id="<?= h($key) ?>_default" name="default_directives[<?= h($key) ?>]"><?= h($defaultDirectives[$key] ?? '') ?></textarea>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>

                <button type="submit">Save Prompt Settings</button>
            </form>
        </div>
    </main>
</div>
<script>
document.querySelectorAll('.use-default-directive').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.getElementById(button.dataset.target || '');
        const source = document.getElementById(button.dataset.source || '');

        if (target && source) {
            target.value = source.value;
            target.focus();
        }
    });
});
</script>
</body>
</html>
