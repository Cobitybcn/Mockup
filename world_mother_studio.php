<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function wms_upload_file(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('No se pudo subir la imagen de referencia.');
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Formato no permitido. Usa JPG, PNG o WEBP.');
    }
    $dir = __DIR__ . '/storage/world_mother_uploads';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de uploads.');
    }
    $name = 'world_mother_ref_' . date('Ymd_His') . '_' . random_int(1000, 9999) . '.' . $ext;
    $path = $dir . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $path)) {
        throw new RuntimeException('No se pudo guardar la imagen subida.');
    }
    return $path;
}

$library = new WorldMotherLibrary();
$generator = new WorldMotherGenerator($library);
$error = '';
$notice = '';
$analysis = null;
$jobId = trim((string)($_POST['job_id'] ?? $_GET['job_id'] ?? ''));
$referencePath = '';
$generated = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'analyze') {
        $referencePath = wms_upload_file((array)($_FILES['reference_image'] ?? []));
        $analysis = $generator->analyzeReference($referencePath, ['notes' => trim((string)($_POST['notes'] ?? ''))]);
        $jobId = date('Ymd_His') . '_' . random_int(1000, 9999);
        $analysis['reference_path'] = $referencePath;
        $analysis['created_by_user_id'] = (int)$user['id'];
        $dir = __DIR__ . '/analysis/world-mother-studio';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $jobId . '.analysis.json', json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $notice = 'Referencia analizada. Confirma la categoria antes de generar.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
        $jobId = trim((string)($_POST['job_id'] ?? ''));
        $analysisPath = __DIR__ . '/analysis/world-mother-studio/' . basename($jobId) . '.analysis.json';
        if (!is_file($analysisPath)) {
            throw new RuntimeException('No se encontro el analisis previo.');
        }
        $analysis = json_decode((string)file_get_contents($analysisPath), true);
        if (!is_array($analysis)) {
            throw new RuntimeException('El analisis previo no es valido.');
        }
        $referencePath = (string)($analysis['reference_path'] ?? '');
        $choice = trim((string)($_POST['category_choice'] ?? ''));
        $newCategory = trim((string)($_POST['new_category'] ?? ''));
        $category = $choice === '__new__' ? $newCategory : $choice;
        $category = WorldMotherGenerator::safeSlug($category);
        if ($category === '') {
            throw new RuntimeException('Selecciona o crea una categoria.');
        }
        $generated = $generator->generateOriginalWorldMother($referencePath, $category, $analysis, [
            'notes' => trim((string)($_POST['generation_notes'] ?? '')),
        ]);
        $notice = 'Mundo Madre generado y guardado.';
    } elseif ($jobId !== '') {
        $analysisPath = __DIR__ . '/analysis/world-mother-studio/' . basename($jobId) . '.analysis.json';
        if (is_file($analysisPath)) {
            $analysis = json_decode((string)file_get_contents($analysisPath), true);
            $referencePath = is_array($analysis) ? (string)($analysis['reference_path'] ?? '') : '';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$categories = $library->categories();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>World Mother Studio - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .studio-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(360px, .8fr); gap: 24px; align-items: start; }
        .panel-box { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 22px; }
        .field { display: grid; gap: 7px; margin-bottom: 16px; }
        .field label, .small-label { font-size: 10px; text-transform: uppercase; color: var(--muted); font-weight: 700; letter-spacing: .05em; }
        textarea, input[type="text"], input[type="file"] { width: 100%; border: 1px solid var(--line); border-radius: var(--radius); background: var(--surface-soft); color: var(--ink); padding: 10px; }
        .ref-img { width: 100%; max-height: 380px; object-fit: contain; border: 1px solid var(--line); background: var(--surface-soft); }
        .candidate { border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; margin: 8px 0; background: var(--surface-soft); }
        .candidate code { font-size: 12px; }
        .analysis-list { font-size: 13px; line-height: 1.55; color: var(--ink); }
        .analysis-list strong { color: var(--muted); font-size: 10px; text-transform: uppercase; display: block; margin-top: 10px; }
        @media (max-width: 980px) { .studio-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">World Mother Studio: upload a reference, analyze it, confirm category, generate an original environment reference.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>World Mother Studio</h1>
                    <p>Create clean environment references for future mockup combinations.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link secondary" href="dashboard.php">Dashboard</a>
                </div>
            </div>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <div class="studio-grid">
                <section class="panel-box">
                    <h2>1. Upload Reference</h2>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="analyze">
                        <div class="field">
                            <label>Reference Image</label>
                            <input type="file" name="reference_image" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                        <div class="field">
                            <label>Notes</label>
                            <textarea name="notes" rows="4" placeholder="Example: concrete gallery, low light, strong wall planes, no furniture dominance..."></textarea>
                        </div>
                        <button class="button-link" type="submit">Analyze Reference</button>
                    </form>

                    <?php if (is_array($analysis)): ?>
                        <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                        <h2>2. Confirm Category</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="generate">
                            <input type="hidden" name="job_id" value="<?= h($jobId) ?>">
                            <?php foreach ((array)($analysis['category_candidates'] ?? []) as $idx => $candidate): ?>
                                <label class="candidate">
                                    <input type="radio" name="category_choice" value="<?= h($candidate['category_slug'] ?? '') ?>" <?= $idx === 0 ? 'checked' : '' ?>>
                                    <code><?= h($candidate['category_slug'] ?? '') ?></code>
                                    <span style="color:var(--muted);">score <?= (int)($candidate['score'] ?? 0) ?> · images <?= (int)($candidate['image_count'] ?? 0) ?></span>
                                </label>
                            <?php endforeach; ?>
                            <label class="candidate">
                                <input type="radio" name="category_choice" value="__new__">
                                Create new category
                                <input type="text" name="new_category" value="<?= h($analysis['new_category_suggestion'] ?? '') ?>" style="margin-top:8px;">
                            </label>
                            <div class="field">
                                <label>Generation Notes</label>
                                <textarea name="generation_notes" rows="3" placeholder="Optional refinements before generation"></textarea>
                            </div>
                            <button class="button-link" type="submit">Generate World Mother</button>
                        </form>
                    <?php endif; ?>
                </section>

                <aside class="panel-box">
                    <h2>Analysis</h2>
                    <?php if ($referencePath !== '' && is_file($referencePath)): ?>
                        <img class="ref-img" src="<?= h(str_replace('\\', '/', str_replace(__DIR__ . DIRECTORY_SEPARATOR, '', $referencePath))) ?>" alt="">
                    <?php endif; ?>
                    <?php if (is_array($analysis)): ?>
                        <div class="analysis-list">
                            <strong>Scene Type</strong><?= h($analysis['scene_type'] ?? '') ?>
                            <strong>Architecture</strong><?= h($analysis['architecture_language'] ?? '') ?>
                            <strong>Walls</strong><?= h($analysis['wall_language'] ?? '') ?>
                            <strong>Lighting</strong><?= h($analysis['lighting'] ?? '') ?>
                            <strong>Materials</strong><?= h(implode(', ', (array)($analysis['materials'] ?? []))) ?>
                            <strong>Palette</strong><?= h(implode(', ', (array)($analysis['palette'] ?? []))) ?>
                            <strong>Camera Potential</strong><?= h(implode(', ', (array)($analysis['camera_potential'] ?? []))) ?>
                            <strong>Risks To Remove</strong><?= h(implode(', ', (array)($analysis['negative_risks'] ?? []))) ?>
                        </div>
                    <?php else: ?>
                        <p style="color:var(--muted);">Upload a reference to see its World Mother core analysis.</p>
                    <?php endif; ?>

                    <?php if (is_array($generated)): ?>
                        <hr style="border:0; border-top:1px dashed var(--line); margin:24px 0;">
                        <h2>Generated</h2>
                        <img class="ref-img" src="<?= h($generated['relative_path'] ?? '') ?>" alt="">
                        <p><code><?= h($generated['relative_path'] ?? '') ?></code></p>
                        <p><code><?= h($generated['audit_file'] ?? '') ?></code></p>
                    <?php endif; ?>
                </aside>
            </div>
        </div>
    </main>
</div>
</body>
</html>
