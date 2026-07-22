<?php
if (!defined('CAMERA_STUDIO_VIEW')) {
    http_response_code(404);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Camera Boards - Artwork Mockups</title>
    <link rel="stylesheet" href="style.css">
<link rel="stylesheet" href="camera_studio.css?v=3">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="main-area">
        <header class="app-header"><a class="user-chip" href="account.php"><?= h($user['email']) ?></a></header>
        <div class="cmb-page" data-camera-page>
            <header class="cmb-page-head">
                <div>
                    <span class="cmb-kicker">Camera Studio</span>
                    <h1>Camera Boards</h1>
                    <p>Organize, activate, and edit cameras with the same visual workflow as the Social Media Board.</p>
                </div>
                <div class="cmb-page-actions">
                    <button type="button" class="cmb-button cmb-button--primary" data-new-camera>New camera</button>
                    <form method="post">
                        <input type="hidden" name="action" value="purge_inactive_slots">
                        <button type="submit" class="cmb-button" onclick="return confirm('Delete or hide all inactive cameras?');">Remove inactive</button>
                    </form>
                    <a class="cmb-button" href="world_mother_studio.php">Scene Studio</a>
                </div>
            </header>

            <?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
            <?php if ($notice !== ''): ?><div class="notice success"><?= h($notice) ?></div><?php endif; ?>

            <section class="cmb-library" aria-labelledby="cmb-library-title">
                <div class="cmb-library-head">
                    <div>
                        <h2 id="cmb-library-title">Camera library</h2>
                        <p>Drag a camera to any board. Drag it back here to keep it available.</p>
                    </div>
                    <div class="cmb-library-meta">
                        <span class="cmb-count" data-library-count><?= $availablePoolCount ?> en espera</span>
                        <button class="cmb-view-exit" type="button" data-exit-board-focus>Vista<br>general</button>
                    </div>
                </div>
                <div class="cmb-library-rail-wrap">
                    <button class="cmb-rail-arrow cmb-rail-arrow--left" type="button" data-scroll-library="-1" aria-label="View previous cameras">‹</button>
                    <div class="cmb-library-rail cmb-sortable-zone" data-camera-library>
                        <?php foreach ($studioSlots as $slotId => $listedSlot): ?>
                            <?php if (isset($assignedBoardLookup[(string)$slotId])) { continue; } ?>
                            <?php render_camera_board_card((string)$slotId, $listedSlot, (string)$slotId === $selectedSlotId); ?>
                        <?php endforeach; ?>
                        <?php if ($availablePoolCount === 0): ?>
                            <div class="cmb-empty cmb-empty--library" data-empty-state>All cameras are assigned. Drag one back from a board to keep it available.</div>
                        <?php endif; ?>
                    </div>
                    <button class="cmb-rail-arrow cmb-rail-arrow--right" type="button" data-scroll-library="1" aria-label="View more cameras">›</button>
                </div>
            </section>

            <section class="cmb-boards" data-camera-boards aria-label="Camera boards">
                <?php for ($boardNumber = 1; $boardNumber <= 3; $boardNumber++): ?>
                    <article class="cmb-board cmb-board--<?= $boardNumber ?>" data-camera-board data-board-number="<?= $boardNumber ?>">
                        <header class="cmb-board-head">
                            <button class="cmb-board-title" type="button" data-focus-board="<?= $boardNumber ?>" aria-label="Open Board <?= $boardNumber ?> in focus mode">
                                <span class="cmb-board-icon" aria-hidden="true"><?= $boardNumber ?></span>
                                <span><strong>Board <?= $boardNumber ?></strong><small>Maximum 12 cameras</small></span>
                            </button>
                            <span class="cmb-board-count" data-board-count><?= count($boardSlotsByBoard[$boardNumber] ?? []) ?>/12</span>
                        </header>
                        <div class="cmb-board-grid cmb-sortable-zone" data-board-list data-board-number="<?= $boardNumber ?>">
                            <?php foreach (($boardSlotsByBoard[$boardNumber] ?? []) as $assignedSlotId): ?>
                                <?php if (isset($studioSlots[$assignedSlotId])): ?>
                                    <?php render_camera_board_card($assignedSlotId, $studioSlots[$assignedSlotId], $assignedSlotId === $selectedSlotId); ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($boardSlotsByBoard[$boardNumber] ?? []) === 0): ?>
                                <div class="cmb-empty" data-empty-state>Drag cameras here to activate this board.</div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endfor; ?>
            </section>

            <form method="post" id="camera-board-form" class="cmb-save-bar" data-board-form>
                <input type="hidden" name="action" value="save_scene_board">
                <input type="hidden" name="selected_slot_id" value="<?= h($selectedSlotId) ?>">
                <div data-board-inputs></div>
                <div class="cmb-save-copy">
                    <span class="cmb-save-icon" aria-hidden="true">↕</span>
                    <div><strong data-save-title>Current organization</strong><span data-save-summary><?= $boardSlotCount ?> active cameras · <?= $availablePoolCount ?> available</span></div>
                </div>
                <button class="cmb-save-button" type="submit">Save boards</button>
            </form>

            <div class="cmb-toast" data-camera-toast role="status" aria-live="polite"></div>

            <div class="cmb-inspector-backdrop" data-camera-inspector-backdrop hidden>
                <aside class="cmb-inspector" role="dialog" aria-modal="true" aria-labelledby="cmb-inspector-title">
                    <header class="cmb-inspector-head">
                        <div><span data-editor-kicker>Camera editor</span><h2 id="cmb-inspector-title" data-editor-title>Camera</h2></div>
                        <button type="button" data-close-camera-inspector aria-label="Close">×</button>
                    </header>
                    <form method="post" class="cmb-editor-form" data-camera-editor-form>
                        <input type="hidden" name="action" value="save_scene_quick" data-editor-action>
                        <input type="hidden" name="enabled" value="1">
                        <input type="hidden" name="fidelity_mode" value="adaptacion_camara_world_mother">
                        <input type="hidden" name="size_classes_supported" value="<?= h(csv_value($emptySlot['size_classes_supported'])) ?>">
                        <input type="hidden" name="orientation_supported" value="<?= h(csv_value($emptySlot['orientation_supported'])) ?>">
                        <div data-editor-board-inputs></div>

                        <div class="cmb-editor-identity">
                            <label>
                                <span>Technical ID</span>
                                <input type="text" name="slot_id" data-editor-id autocomplete="off" required>
                            </label>
                            <div><span>Origen</span><strong data-editor-origin>Nueva</strong></div>
                        </div>

                        <label class="cmb-editor-field">
                            <span>Nombre visible</span>
                            <input type="text" name="slot_name" data-editor-name autocomplete="off" required>
                            <small>This name appears on cards and during camera selection.</small>
                        </label>

                        <label class="cmb-editor-field cmb-editor-field--prompt">
                            <span>Prompt completo</span>
                            <textarea name="full_prompt_template" rows="28" data-editor-prompt spellcheck="false" placeholder="Define this camera’s height, lens, tilt, composition, and restrictions."></textarea>
                        </label>

                        <footer class="cmb-editor-actions">
                            <button class="cmb-editor-delete" type="submit" name="action" value="delete_slot" data-delete-camera onclick="return confirm('Delete or deactivate this camera? Base cameras are deactivated through an override.');">Delete</button>
                            <button class="cmb-editor-save" type="submit">Save camera</button>
                        </footer>
                    </form>
                </aside>
            </div>
        </div>
    </main>
</div>
<script type="application/json" id="camera-board-cameras"><?= json_encode($cameraEditorPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script type="application/json" id="camera-board-config"><?= json_encode($cameraBoardClientConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<script src="assets/vendor/sortablejs/Sortable.min.js?v=1.15.7"></script>
<script src="camera_studio.js?v=2"></script>
</body>
</html>
