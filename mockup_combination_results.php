<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? 0));
$selectedWorldMotherCategory = WorldMotherGenerator::safeSlug((string)($_GET['world_mother_category'] ?? ''));
if ($id <= 0) {
    http_response_code(404);
    die('Artwork ID is missing.');
}

$stmt = $pdo->prepare('SELECT * FROM artworks WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$artwork = $stmt->fetch();
if (!$artwork) {
    http_response_code(404);
    die('Artwork not found.');
}
if ((int)$artwork['user_id'] !== (int)$user['id'] && !Auth::isAdmin($user)) {
    http_response_code(403);
    die('Access denied.');
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function world_mother_image_url(string $file): string
{
    $file = str_replace('\\', '/', trim($file));
    if ($file === '' || !str_starts_with($file, 'storage/world_mothers/')) {
        return '';
    }

    return 'world_mother_media.php?file=' . rawurlencode($file);
}

$stmt = $pdo->prepare('SELECT * FROM mockups WHERE user_id = :user_id AND artwork_file = :artwork_file ORDER BY id DESC');
$stmt->execute([
    'user_id' => (int)$artwork['user_id'],
    'artwork_file' => basename((string)$artwork['root_file']),
]);
$rows = [];
foreach ($stmt->fetchAll() ?: [] as $row) {
    $state = json_decode((string)($row['selector_state_json'] ?? ''), true);
    if (!is_array($state) || ($state['generation_source'] ?? '') !== 'mockup_combination_review') {
        continue;
    }
    $row['selector_state'] = $state;
    $rows[] = $row;
}

$evalPath = __DIR__ . '/analysis/mockup-combination-evaluations/' . $id . '.evaluations.json';
$evaluations = [];
if (is_file($evalPath)) {
    $decoded = json_decode((string)file_get_contents($evalPath), true);
    if (is_array($decoded['evaluations'] ?? null)) {
        $evaluations = $decoded['evaluations'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Combination Results - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .results-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px; }
        .result-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); box-shadow: var(--shadow); padding: 18px; }
        .result-card img { width: 100%; max-height: 520px; object-fit: contain; background: var(--surface-soft); border: 1px solid var(--line); }
        .result-card h3 { margin: 12px 0 6px; font-family: var(--font-serif); font-size: 18px; }
        .meta { color: var(--muted); font-size: 12px; line-height: 1.5; word-break: break-word; }
        .result-mini-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .result-mini-meta span {
            border: 1px solid var(--line);
            background: var(--surface-soft);
            border-radius: 3px;
            padding: 4px 7px;
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .world-ref {
            margin-top: 12px;
            display: grid;
            grid-template-columns: 140px minmax(0, 1fr);
            gap: 12px;
            align-items: start;
            border: 1px solid var(--line);
            background: var(--surface-soft);
            padding: 10px;
            border-radius: var(--radius);
        }
        .world-ref img {
            width: 140px;
            height: 95px;
            object-fit: cover;
            border: 1px solid var(--line);
            background: var(--surface);
        }
        .auto-world-note {
            color: #234e52;
            font-weight: 700;
        }
        .eval-form { display: grid; gap: 10px; margin-top: 14px; }
        .eval-form textarea,
        .eval-form label:nth-of-type(2) {
            display: none;
        }
        .eval-form select, .eval-form textarea { width: 100%; border: 1px solid var(--line); border-radius: var(--radius); padding: 10px; background: var(--surface-soft); color: var(--ink); }
        .eval-status { min-height: 18px; color: var(--muted); font-size: 12px; }
        @media (max-width: 980px) { .results-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header">
            <a class="user-chip" href="account.php"><?= h($user['email']) ?></a>
        </header>
        <div class="alert-strip">Evaluate generated mockup combinations and keep the best candidates.</div>
        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Mockup Combination Results</h1>
                    <p>Generated images from the six-combination review flow.</p>
                </div>
                <div class="topbar-actions">
                    <a class="button-link" href="mockup_combinations_review.php?id=<?= (int)$id ?><?= $selectedWorldMotherCategory !== '' ? '&world_mother_category=' . rawurlencode($selectedWorldMotherCategory) : '' ?>">Back to Combinations</a>
                    <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="notice">No generated combination images yet. Generate one from the combinations review screen.</div>
            <?php endif; ?>

            <div class="results-grid">
                <?php foreach ($rows as $row): ?>
                    <?php
                    $state = $row['selector_state'];
                    $combo = (array)($state['combination'] ?? []);
                    $mockupId = (int)$row['id'];
                    $existing = (array)($evaluations[(string)$mockupId] ?? []);
                    $worldImage = (string)($combo['world_mother_image_path'] ?? '');
                    $worldImageUrl = world_mother_image_url($worldImage);
                    $generatedWorldMother = (array)($combo['world_mother_selection']['generated_world_mother'] ?? []);
                    $cameraTitle = (string)($combo['camera_slot_name'] ?? $combo['selected_camera_slot_id'] ?? 'Camera');
                    $sceneTitle = (string)($combo['world_mother_category'] ?? 'Scene');
                    ?>
                    <section class="result-card">
                        <img src="media.php?file=<?= rawurlencode(basename((string)$row['mockup_file'])) ?>" alt="">
                        <h3><?= h($cameraTitle) ?></h3>
                        <div class="result-mini-meta">
                            <span>Scene: <?= h($sceneTitle) ?></span>
                            <span>Camera <?= (int)($combo['combination_index'] ?? 0) ?></span>
                        </div>
                        <?php if ($worldImageUrl !== ''): ?>
                            <div class="world-ref" style="margin-top:10px;">
                                <a href="<?= h($worldImageUrl) ?>" target="_blank" rel="noopener">
                                    <img src="<?= h($worldImageUrl) ?>" alt="">
                                </a>
                                <div class="meta">
                                    <div><strong><?= h($sceneTitle) ?></strong></div>
                                    <div>Variant <?= h((string)($combo['world_mother_variant_index'] ?? 1)) ?>: <?= h((string)($combo['world_mother_variant_role'] ?? 'primary')) ?></div>
                                    <?php if (!empty($combo['world_mother_selection_strategy'])): ?>
                                        <div>Selection: <?= h((string)$combo['world_mother_selection_strategy']) ?></div>
                                    <?php endif; ?>
                                    <div>Reference mode: <?= h((string)($combo['world_mother_reference_mode'] ?? 'literal_scene_view')) ?></div>
                                    <?php if ($generatedWorldMother): ?>
                                        <div class="auto-world-note">Auto-generated for this category during beta review.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <form class="eval-form" onsubmit="saveEvaluation(event, this)">
                            <input type="hidden" name="artwork_id" value="<?= (int)$id ?>">
                            <input type="hidden" name="mockup_id" value="<?= $mockupId ?>">
                            <label>
                                Score
                                <select name="score">
                                    <?php for ($score = 5; $score >= 1; $score--): ?>
                                        <option value="<?= $score ?>" <?= (int)($existing['score'] ?? 0) === $score ? 'selected' : '' ?>><?= $score ?></option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                            <label>
                                Evaluation notes
                                <textarea name="notes" rows="4" placeholder="Fidelity, scale, camera, world fit, commercial usefulness..."><?= h($existing['notes'] ?? '') ?></textarea>
                            </label>
                            <label style="display:inline-flex; gap:8px; align-items:center;">
                                <input type="checkbox" name="keeper" value="1" <?= !empty($existing['keeper']) ? 'checked' : '' ?>>
                                Keep as candidate
                            </label>
                            <div class="eval-status"></div>
                            <button class="button-link" type="submit">Save Evaluation</button>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>
<script>
function saveEvaluation(event, form) {
    event.preventDefault();
    const status = form.querySelector('.eval-status');
    const button = form.querySelector('button');
    button.disabled = true;
    status.textContent = 'Saving...';
    fetch('save_mockup_combination_evaluation.php', { method: 'POST', body: new FormData(form) })
        .then(response => response.text().then(text => {
            let parsed;
            try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
            return { status: response.status, body: parsed };
        }))
        .then(result => {
            status.textContent = result.body.ok ? 'Saved.' : (result.body.error || 'Save failed.');
            button.disabled = false;
        })
        .catch(err => {
            status.textContent = 'Save failed: ' + err.message;
            button.disabled = false;
        });
}
</script>
</body>
</html>
