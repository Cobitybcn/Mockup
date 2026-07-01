<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$user = Auth::requireUser();
$pdo = Database::connection();

$id = max(0, (int)($_GET['id'] ?? 0));
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

function world_mother_favorites_path(int $userId): string
{
    return __DIR__ . '/storage/world_mother_favorites/user_' . $userId . '.json';
}

function world_mother_favorites(int $userId): array
{
    $path = world_mother_favorites_path($userId);
    if (!is_file($path)) {
        return [];
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter(array_map('strval', $decoded), static fn (string $slug): bool => $slug !== ''));
}

function world_mother_search_aliases(string $slug): string
{
    $aliases = [];
    if (str_contains($slug, 'sunlit') || str_contains($slug, 'sun')) {
        $aliases[] = 'sunlight sunny sun morning light daylight';
    }
    if (str_contains($slug, 'blue_hour')) {
        $aliases[] = 'blue hour twilight dusk evening cobalt light';
    }
    if (str_contains($slug, 'low_light') || str_contains($slug, 'dark') || str_contains($slug, 'night')) {
        $aliases[] = 'low light moody evening night shadow';
    }
    if (str_contains($slug, 'atelier') || str_contains($slug, 'studio') || str_contains($slug, 'workspace')) {
        $aliases[] = 'atelier studio workspace artist workroom';
    }
    if (str_contains($slug, 'concrete') || str_contains($slug, 'brutalist')) {
        $aliases[] = 'concrete brutalist raw architecture mineral';
    }

    return implode(' ', $aliases);
}

$selectedSlots = [];
foreach (($_GET['slot'] ?? []) as $index => $slotId) {
    $selectedSlots[(int)$index] = trim((string)$slotId);
}
$selectedWorldMotherCategory = WorldMotherGenerator::safeSlug((string)($_GET['world_mother_category'] ?? ''));

$engine = new MockupCombinationEngine();
$review = $engine->buildForArtwork($id, $selectedSlots, [
    'selected_world_mother_category' => $selectedWorldMotherCategory,
]);
$combinations = $review['combinations'] ?? [];
$cameraSlots = $review['available_camera_slots'] ?? [];
$suggestedWorldMotherCategories = (array)($review['suggested_world_mother_categories'] ?? []);
$selectedWorldMotherCategory = (string)($review['selected_world_mother_category'] ?? $selectedWorldMotherCategory);
$favoriteWorldMotherCategories = world_mother_favorites((int)$user['id']);
$favoriteWorldMotherLookup = array_fill_keys($favoriteWorldMotherCategories, true);

$rootUrl = '';
$rootPath = (string)($review['root_artwork_path'] ?? '');
if ($rootPath !== '') {
    $rootUrl = 'media.php?file=' . rawurlencode(basename($rootPath));
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Mockup Combinations Review - The Artwork Curator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        .review-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
            margin-bottom: 30px;
        }
        .combination-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .combination-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px dashed var(--line);
            padding-bottom: 12px;
        }
        .combination-head h3 {
            margin: 0;
            font-family: var(--font-serif);
            font-size: 22px;
            line-height: 1.2;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            height: 24px;
            padding: 0 8px;
            border-radius: 3px;
            border: 1px solid rgba(154, 123, 86, 0.25);
            background: var(--accent-light);
            color: var(--accent);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .badge.ready {
            background: #e6fffa;
            border-color: rgba(35, 78, 82, .25);
            color: #234e52;
        }
        .badge.warn {
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .25);
            color: #8c6d1f;
        }
        .thumb-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .thumb-box {
            background: var(--surface-soft);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            overflow: hidden;
            min-height: 180px;
        }
        .thumb-box img {
            display: block;
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: var(--surface-soft);
        }
        .thumb-label {
            display: block;
            padding: 8px 10px;
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .05em;
            color: var(--muted);
            border-top: 1px solid var(--line);
            word-break: break-word;
        }
        .meta-list {
            display: grid;
            gap: 9px;
            font-size: 13px;
            line-height: 1.45;
        }
        .meta-list strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .camera-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: end;
        }
        .camera-form select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 12px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
        }
        .prompt-preview {
            width: 100%;
            min-height: 190px;
            resize: vertical;
            font-family: monospace;
            font-size: 11px;
            line-height: 1.55;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
            color: var(--ink);
        }
        .beta-hidden-stage {
            display: none !important;
        }
        .camera-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            background: var(--surface-soft);
        }
        .camera-title-row strong {
            font-size: 14px;
        }
        .camera-title-row code {
            font-size: 10px;
            color: var(--muted);
        }
        .notes {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }
        .auto-world-panel {
            border: 1px solid rgba(35, 78, 82, .22);
            background: #f0fdfa;
            color: #234e52;
            border-radius: var(--radius);
            padding: 11px 12px;
            font-size: 12px;
            line-height: 1.45;
            word-break: break-word;
        }
        .auto-world-panel strong {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .05em;
            margin-bottom: 4px;
        }
        .auto-world-panel a {
            color: #234e52;
            font-weight: 700;
        }
        .prepare-result {
            font-size: 12px;
            min-height: 18px;
            color: var(--muted);
        }
        .scene-choice-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-top: 12px;
            max-height: 420px;
            overflow: auto;
            padding-right: 4px;
        }
        .scene-choice {
            display: grid;
            gap: 6px;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px;
            background: var(--surface-soft);
            color: var(--ink);
            text-decoration: none;
            min-height: 92px;
        }
        .scene-choice.hidden { display: none; }
        .scene-choice.active {
            border-color: rgba(154, 123, 86, .55);
            background: var(--accent-light);
        }
        .scene-choice-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 8px;
            align-items: start;
        }
        .favorite-scene-btn {
            width: 28px;
            height: 28px;
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            font-size: 17px;
            line-height: 1;
        }
        .favorite-scene-btn.active {
            color: #8c6d1f;
            background: #fffdf5;
            border-color: rgba(140, 109, 31, .35);
        }
        .scene-choice strong {
            display: block;
            font-size: 12px;
            word-break: break-word;
        }
        .scene-choice span {
            display: block;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }
        .scene-browser-controls {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 10px;
            align-items: center;
            margin-top: 12px;
        }
        .scene-browser-controls input {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 12px;
            background: var(--surface-soft);
            color: var(--ink);
            font-size: 13px;
        }
        .scene-filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            justify-content: flex-end;
        }
        .scene-filter-tabs button {
            border: 1px solid var(--line);
            border-radius: 3px;
            background: var(--surface-soft);
            color: var(--muted);
            padding: 8px 10px;
            font-size: 11px;
            cursor: pointer;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .04em;
        }
        .scene-filter-tabs button.active {
            background: var(--accent-light);
            color: var(--accent);
            border-color: rgba(154, 123, 86, .35);
        }
        .scene-browser-count {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }
        @media (max-width: 980px) {
            .review-grid,
            .thumb-row,
            .scene-choice-grid {
                grid-template-columns: 1fr;
            }
            .scene-browser-controls {
                grid-template-columns: 1fr;
            }
            .scene-filter-tabs {
                justify-content: flex-start;
            }
            .camera-form {
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
            Mockup Combinations Review: choose a real world mother reference, camera slot, then generate and evaluate the result.
        </div>

        <div class="workspace">
            <div class="workspace-header">
                <div>
                    <h1>Mockup Combinations Review</h1>
                    <p>Choose one scene mother, then generate every enabled camera-slot view from that same reference.</p>
                </div>
                <div class="topbar-actions">
                    <button class="button-link" type="button" id="generate-all-btn" onclick="generateAllCombinations(this)">Generate All Combinations</button>
                    <a class="button-link secondary" href="mockup_combination_results.php?id=<?= (int)$id ?>">Generated Results</a>
                    <a class="button-link secondary" href="artwork_details.php?id=<?= (int)$id ?>">Artwork Details</a>
                </div>
            </div>

            <?php if (!empty($review['validation_notes'])): ?>
                <div class="notice warning">
                    <strong>Review notes:</strong>
                    <ul style="margin: 6px 0 0 18px;">
                        <?php foreach ((array)$review['validation_notes'] as $note): ?>
                            <li><?= h($note) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="specs-panel" style="background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; margin-bottom: 24px;">
                <div class="specs-grid" style="display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px;">
                    <div><strong style="font-size:10px; text-transform:uppercase; color:var(--muted);">Artwork ID</strong><br><code><?= (int)$id ?></code></div>
                    <div><strong style="font-size:10px; text-transform:uppercase; color:var(--muted);">Camera Views</strong><br><code><?= count($combinations) ?></code></div>
                    <div><strong style="font-size:10px; text-transform:uppercase; color:var(--muted);">World Categories Available</strong><br><code><?= (int)($review['world_mother_categories_available'] ?? $review['world_mother_categories_with_images'] ?? 0) ?></code></div>
                    <div><strong style="font-size:10px; text-transform:uppercase; color:var(--muted);">Mode</strong><br><code><?= h($review['generation_mode'] ?? '') ?></code></div>
                </div>
            </div>

            <div class="specs-panel" style="background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; margin-bottom: 24px;">
                <strong style="font-size:10px; text-transform:uppercase; color:var(--muted);">Ranked Scene Mothers</strong>
                <p style="margin:6px 0 0; color:var(--muted); font-size:13px;">Pick one category, search the full library, or save favorites for repeated use. Empty folders are visible but generation waits until an image exists.</p>
                <div class="scene-browser-controls">
                    <input type="search" id="scene-search" placeholder="Search scene mothers: sunlight, blue hour, atelier, concrete..." autocomplete="off">
                    <div class="scene-filter-tabs" role="group" aria-label="Scene mother filters">
                        <button type="button" class="active" data-scene-filter="all">All</button>
                        <button type="button" data-scene-filter="with-images">With images</button>
                        <button type="button" data-scene-filter="favorites">Favorites</button>
                    </div>
                </div>
                <div class="scene-browser-count" id="scene-browser-count"></div>
                <div class="scene-choice-grid">
                    <?php foreach ($suggestedWorldMotherCategories as $scene): ?>
                        <?php
                        $slug = (string)($scene['category_slug'] ?? '');
                        $url = 'mockup_combinations_review.php?id=' . (int)$id . '&world_mother_category=' . rawurlencode($slug);
                        $matchedTerms = implode(' ', array_map('strval', (array)($scene['matched_terms'] ?? [])));
                        $searchText = strtolower(trim($slug . ' ' . str_replace('_', ' ', $slug) . ' ' . (string)($scene['category_name'] ?? '') . ' ' . $matchedTerms . ' ' . world_mother_search_aliases($slug)));
                        $isFavorite = isset($favoriteWorldMotherLookup[$slug]);
                        $imageCount = (int)($scene['image_count'] ?? 0);
                        ?>
                        <a
                            class="scene-choice <?= $slug === $selectedWorldMotherCategory ? 'active' : '' ?>"
                            href="<?= h($url) ?>"
                            data-scene-choice
                            data-slug="<?= h($slug) ?>"
                            data-name="<?= h($scene['category_name'] ?? $slug) ?>"
                            data-image-count="<?= $imageCount ?>"
                            data-favorite="<?= $isFavorite ? '1' : '0' ?>"
                            data-search="<?= h($searchText) ?>"
                        >
                            <div class="scene-choice-top">
                                <strong><?= h($scene['category_name'] ?? $slug) ?></strong>
                                <button
                                    class="favorite-scene-btn <?= $isFavorite ? 'active' : '' ?>"
                                    type="button"
                                    title="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                                    aria-label="<?= $isFavorite ? 'Remove favorite' : 'Add favorite' ?>"
                                    data-favorite-scene="<?= h($slug) ?>"
                                >★</button>
                            </div>
                            <span><code><?= h($slug) ?></code></span>
                            <span><?= $imageCount ?> image(s)</span>
                            <span>Score <?= (int)($scene['score'] ?? 0) ?></span>
                            <?php if ($matchedTerms !== ''): ?>
                                <span><?= h(implode(', ', array_slice((array)$scene['matched_terms'], 0, 3))) ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="review-grid">
                <?php foreach ($combinations as $combo): ?>
                    <?php
                    $idx = (int)$combo['combination_index'];
                    $worldImage = (string)$combo['world_mother_image_path'];
                    $worldImageUrl = world_mother_image_url($worldImage);
                    $generatedWorldMother = (array)($combo['world_mother_selection']['generated_world_mother'] ?? []);
                    $missingWorldMother = (array)($combo['world_mother_selection']['missing_world_mother'] ?? []);
                    $isGeneratedWorldMother = !empty($generatedWorldMother);
                    $isMissingWorldMother = !empty($missingWorldMother);
                    ?>
                    <section class="combination-card">
                        <div class="combination-head">
                            <div>
                                <span class="badge">Combination <?= $idx ?></span>
                                <h3><?= h($combo['camera_slot_name'] ?? $combo['selected_camera_slot_id'] ?? 'Camera') ?></h3>
                            </div>
                            <span class="badge <?= !empty($combo['generation_ready']) ? 'ready' : 'warn' ?>">
                                <?= !empty($combo['generation_ready']) ? 'Ready' : 'Needs Data' ?>
                            </span>
                            <?php if ($isGeneratedWorldMother): ?>
                                <span class="badge ready">Auto Scene</span>
                            <?php endif; ?>
                            <?php if ($isMissingWorldMother): ?>
                                <span class="badge warn">Scene Pending</span>
                            <?php endif; ?>
                        </div>

                        <div class="thumb-row">
                            <div class="thumb-box">
                                <?php if ($rootUrl !== ''): ?>
                                    <img src="<?= h($rootUrl) ?>" alt="">
                                <?php endif; ?>
                                <span class="thumb-label">Root artwork<br><?= h(basename((string)$combo['root_artwork_path'])) ?></span>
                            </div>
                            <div class="thumb-box">
                                <?php if ($worldImageUrl !== ''): ?>
                                    <img src="<?= h($worldImageUrl) ?>" alt="">
                                <?php endif; ?>
                                <span class="thumb-label">
                                    World mother<br><?= h($worldImage) ?><br>
                                    Variant <?= h((string)($combo['world_mother_variant_index'] ?? 1)) ?>:
                                    <?= h((string)($combo['world_mother_variant_role'] ?? 'primary')) ?><br>
                                    <?php if (!empty($combo['world_mother_selection_strategy'])): ?>
                                        Selection: <?= h((string)$combo['world_mother_selection_strategy']) ?><br>
                                    <?php endif; ?>
                                    Reference mode: <?= h((string)($combo['world_mother_reference_mode'] ?? 'literal_scene_view')) ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($isGeneratedWorldMother): ?>
                            <div class="auto-world-panel">
                                <strong>Beta auto-generated scene mother</strong>
                                This scene mother was created earlier. For this beta flow, prefer replacing it with a curated manual image if quality is not enough.
                                <?php if ($worldImageUrl !== ''): ?>
                                    <br><a href="<?= h($worldImageUrl) ?>" target="_blank" rel="noopener">Open generated image</a>
                                <?php endif; ?>
                                <?php if (!empty($generatedWorldMother['audit_file'])): ?>
                                    <br>Audit: <code><?= h($generatedWorldMother['audit_file']) ?></code>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($isMissingWorldMother): ?>
                            <div class="auto-world-panel">
                                <strong>Beta scene mother pending</strong>
                                Add one image manually to <code><?= h($missingWorldMother['folder'] ?? ('storage/world_mothers/' . $combo['world_mother_category'])) ?></code>, then refresh. The system will not generate this scene mother automatically.
                            </div>
                        <?php endif; ?>

                        <div class="meta-list">
                            <div><strong>World Mother</strong><?= h($combo['world_mother_category'] ?? '') ?></div>
                            <div><strong>Reference Mode</strong><?= h($combo['world_mother_reference_mode'] ?? 'literal_scene_view') ?></div>
                            <?php if (!empty($combo['selected_world_id'])): ?>
                                <div><strong>Source Context World</strong><?= h($combo['selected_world_id']) ?></div>
                            <?php endif; ?>
                            <div class="camera-title-row">
                                <strong><?= h($combo['camera_slot_name'] ?? '') ?></strong>
                                <code><?= h($combo['selected_camera_slot_id']) ?></code>
                            </div>
                        </div>

                        <form class="camera-form beta-hidden-stage" method="get" action="mockup_combinations_review.php">
                            <input type="hidden" name="id" value="<?= (int)$id ?>">
                            <input type="hidden" name="world_mother_category" value="<?= h($selectedWorldMotherCategory) ?>">
                            <?php foreach ($combinations as $other): ?>
                                <?php if ((int)$other['combination_index'] !== $idx): ?>
                                    <input type="hidden" name="slot[<?= (int)$other['combination_index'] ?>]" value="<?= h($other['selected_camera_slot_id']) ?>">
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <label>
                                <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:4px;">Selected Camera Slot</strong>
                                <select name="slot[<?= $idx ?>]" onchange="this.form.submit()">
                                    <?php foreach ($cameraSlots as $slot): ?>
                                        <?php $slotId = (string)($slot['slot_id'] ?? ''); ?>
                                        <option value="<?= h($slotId) ?>" <?= $slotId === (string)$combo['selected_camera_slot_id'] ? 'selected' : '' ?>>
                                            <?= h(($slot['slot_name'] ?? $slotId) . ' - ' . $slotId) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button class="button-link secondary" type="submit">Refresh Preview</button>
                        </form>

                        <div class="beta-hidden-stage">
                            <strong style="display:block; font-size:10px; text-transform:uppercase; color:var(--muted); margin-bottom:6px;">Final Prompt Preview</strong>
                            <textarea class="prompt-preview" readonly><?= h($combo['final_prompt_preview']) ?></textarea>
                        </div>

                        <?php if (!empty($combo['validation_notes'])): ?>
                            <ul class="notes">
                                <?php foreach ((array)$combo['validation_notes'] as $note): ?>
                                    <li><?= h($note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <div>
                            <div id="prepare-result-<?= $idx ?>" class="prepare-result"></div>
                            <button
                                class="button-link"
                                type="button"
                                data-index="<?= $idx ?>"
                                data-artwork-id="<?= (int)$id ?>"
                                data-camera-slot="<?= h($combo['selected_camera_slot_id']) ?>"
                                data-world-mother-category="<?= h($selectedWorldMotherCategory) ?>"
                                onclick="prepareCombination(this)"
                                <?= empty($combo['generation_ready']) ? 'disabled' : '' ?>
                            >Generate This Combination</button>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
</div>

<script>
function prepareCombination(btn, skipConfirm = false) {
    if (!skipConfirm && !confirm('Generate an image for this combination now? This may consume a real API credit when real API mode is enabled.')) {
        return;
    }
    return runCombinationGeneration(btn);
}

function runCombinationGeneration(btn) {
    const index = btn.getAttribute('data-index');
    const status = document.getElementById('prepare-result-' + index);
    const formData = new FormData();
    formData.append('artwork_id', btn.getAttribute('data-artwork-id'));
    formData.append('combination_index', index);
    formData.append('camera_slot_id', btn.getAttribute('data-camera-slot'));
    formData.append('world_mother_category', btn.getAttribute('data-world-mother-category'));

    btn.disabled = true;
    const originalText = btn.textContent;
    btn.textContent = 'Generating...';
    status.textContent = 'Generating image from root artwork, world mother reference, selected camera, and ADMIN prompt.';

    return fetch('generate_mockup_combination.php', { method: 'POST', body: formData })
        .then(response => response.text().then(text => {
            let parsed;
            try { parsed = JSON.parse(text); } catch (err) { throw new Error(text.substring(0, 220)); }
            return { status: response.status, body: parsed };
        }))
        .then(result => {
            if (result.status === 200 && result.body.ok) {
                status.innerHTML = (result.body.message || 'Image generated.') + ' <a href="' + result.body.results_url + '">Evaluate results</a>';
                btn.textContent = 'Generated';
                return result.body;
            } else {
                status.textContent = (result.body && result.body.error) ? result.body.error : 'Preparation failed.';
                btn.disabled = false;
                btn.textContent = originalText;
                throw new Error(status.textContent);
            }
        })
        .catch(err => {
            status.textContent = 'Preparation failed: ' + err.message;
            btn.disabled = false;
            btn.textContent = originalText;
            throw err;
        });
}

async function generateAllCombinations(btn) {
    const buttons = Array.from(document.querySelectorAll('button[data-index][data-artwork-id][data-camera-slot]:not([disabled])'));
    if (buttons.length === 0) {
        alert('No combinations are available to generate.');
        return;
    }
    if (!confirm('Generate all ' + buttons.length + ' combinations now? This may consume one real API credit per combination when real API mode is enabled.')) {
        return;
    }

    btn.disabled = true;
    const originalText = btn.textContent;
    let successCount = 0;
    let failCount = 0;

    for (let i = 0; i < buttons.length; i++) {
        const comboBtn = buttons[i];
        btn.textContent = 'Generating ' + (i + 1) + ' / ' + buttons.length + '...';
        try {
            await prepareCombination(comboBtn, true);
            successCount++;
        } catch (err) {
            failCount++;
        }
    }

    btn.disabled = false;
    btn.textContent = originalText;

    if (successCount > 0) {
        const go = confirm('Generation complete. Success: ' + successCount + ', failed: ' + failCount + '. Open results now?');
        if (go) {
            window.location.href = 'mockup_combination_results.php?id=<?= (int)$id ?>';
        }
    } else {
        alert('No combinations were generated. Check the messages on each card.');
    }
}

const sceneSearchInput = document.getElementById('scene-search');
const sceneCount = document.getElementById('scene-browser-count');
let activeSceneFilter = 'all';

function updateSceneBrowser() {
    const query = (sceneSearchInput ? sceneSearchInput.value : '').trim().toLowerCase();
    const cards = Array.from(document.querySelectorAll('[data-scene-choice]'));
    let visible = 0;
    for (const card of cards) {
        const hasImages = parseInt(card.getAttribute('data-image-count') || '0', 10) > 0;
        const isFavorite = card.getAttribute('data-favorite') === '1';
        const haystack = card.getAttribute('data-search') || '';
        const matchesQuery = query === '' || haystack.includes(query);
        const matchesFilter =
            activeSceneFilter === 'all'
            || (activeSceneFilter === 'with-images' && hasImages)
            || (activeSceneFilter === 'favorites' && isFavorite);
        const show = matchesQuery && matchesFilter;
        card.classList.toggle('hidden', !show);
        if (show) visible++;
    }
    if (sceneCount) {
        sceneCount.textContent = visible + ' of ' + cards.length + ' scene mothers shown';
    }
}

if (sceneSearchInput) {
    sceneSearchInput.addEventListener('input', updateSceneBrowser);
}
document.querySelectorAll('[data-scene-filter]').forEach(button => {
    button.addEventListener('click', () => {
        activeSceneFilter = button.getAttribute('data-scene-filter') || 'all';
        document.querySelectorAll('[data-scene-filter]').forEach(btn => btn.classList.toggle('active', btn === button));
        updateSceneBrowser();
    });
});
document.querySelectorAll('[data-favorite-scene]').forEach(button => {
    button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        const slug = button.getAttribute('data-favorite-scene') || '';
        if (slug === '') return;
        const formData = new FormData();
        formData.append('category', slug);
        fetch('toggle_world_mother_favorite.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (!result.ok) {
                    throw new Error(result.error || 'Favorite update failed.');
                }
                const favorite = result.favorite ? '1' : '0';
                button.classList.toggle('active', result.favorite);
                button.title = result.favorite ? 'Remove favorite' : 'Add favorite';
                button.setAttribute('aria-label', button.title);
                const card = button.closest('[data-scene-choice]');
                if (card) {
                    card.setAttribute('data-favorite', favorite);
                }
                updateSceneBrowser();
            })
            .catch(err => {
                alert(err.message);
            });
    });
});
updateSceneBrowser();
</script>
</body>
</html>
