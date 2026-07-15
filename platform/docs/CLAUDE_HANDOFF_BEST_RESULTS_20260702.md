# Claude Code handoff - best mockup results snapshot

Date: 2026-07-02
Workspace: `C:\laragon\www\mockups`
Branch observed: `codex/recovery-pre-version-audit-20260701`
Purpose: preserve the current best results and give Claude Code enough context to continue later without re-discovering the whole system.

## Executive summary

This repository is in a valuable dirty working state. Do not reset it, do not clean untracked files, and do not assume old docs are authoritative.

The current best direction is the direct world-mother combination flow:

`uploaded/generated root artwork -> mockup_combinations_review.php -> generate_mockup_combination.php -> AdminPromptComposerPreview -> GeminiMockupGenerator -> GeminiImageClient -> vertex_bridge.py`

The legacy curatorial/context-analysis mockup flow has been intentionally disabled or removed. The system now prioritizes:

- one active mockup generation path;
- direct world mother selection instead of legacy context selection;
- camera slots as the authority for viewpoint and photographic geometry;
- a single physical-integrity policy for artwork scale, aspect ratio, crop, edge behavior, and identity;
- prompt passthrough for composed mockup prompts;
- selective inpainting/precomposition only for explicitly enabled camera strategies; Camera 15 was removed from the active flow on 2026-07-02;
- regression tests protecting camera slots, root artwork prompt contracts, prompt isolation, and uploaded-root behavior.

The work is not committed. A clean filesystem backup was created from the current state into:

`C:\laragon\www\mockups\backups\mockups_best_results_snapshot_20260702_clean.tar.gz`

This archive was verified to contain key project files and to exclude `.git` and `mockups/backups`.

## Absolute rules before continuing

1. Do not run `git reset --hard`, `git checkout -- .`, `git clean`, or any destructive cleanup.
2. Do not delete generated outputs, prompt text files, analysis files, or untracked test fixtures unless the user explicitly asks.
3. Do not commit `storage/credentials.json`, `.env`, generated image/video outputs, or private credentials.
4. Before any major code change, create a second copy or commit/checkpoint of the current dirty state.
5. Prefer reading the current code over older docs. Some older docs describe flows that are now intentionally obsolete.

## Verification already run

PHP binary used:

`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`

Regression suite:

```bat
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe tests\run_regression_tests.php
```

Result:

`PASS: 221 | FAIL: 0`

Additional lint checks:

```bat
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -l app\Support\ArtworkPhysicalIntegrityPolicy.php
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe -l app\Services\GeminiMockupGenerator.php
```

Both passed with no syntax errors.

Important note: `php` is not currently on the shell PATH in this Codex session. Use the explicit Laragon PHP path above.

## Git/worktree state at handoff

Observed `git diff --stat`:

- 47 tracked files changed.
- About 1,983 insertions and 6,622 deletions.
- Large intentional deletion of legacy mockup analysis/context code.
- Large active additions/modifications around camera slots, prompt composition, inpainting/precomposition, and combination UI.

Tracked modified/deleted highlights:

- Modified:
  - `analyze.php`
  - `app/Config/mockup_camera_slots.php`
  - `app/Services/AdminPromptComposerPreview.php`
  - `app/Services/GeminiImageClient.php`
  - `app/Services/GeminiMockupGenerator.php`
  - `app/Services/MockupCombinationEngine.php`
  - `app/Services/MockupContextWorldRegistry.php`
  - `app/Services/OpenAIMockupGenerator.php`
  - `app/Services/ServiceFactory.php`
  - `app/Services/WorldMotherGenerator.php`
  - `app/Services/vertex_bridge.py`
  - `app/Support/WorldMotherCameraAuthorityPolicy.php`
  - `app/bootstrap.php`
  - `core_review.php`
  - `generate_mockup_combination.php`
  - `generate_one_mockup_from_composed_admin_prompt.php`
  - `mockup_combination_results.php`
  - `mockup_combinations_review.php`
  - `mockup_prompt_drafts_review.php`
  - `reanalyze.php`
  - `regenerate_mockup_proposals.php`
  - `report.php`
  - `toggle_world_mother_favorite.php`
  - `world_mother_media.php`

- Deleted legacy/suspect files:
  - `app/Contracts/ArtworkAnalyzerInterface.php`
  - `app/Contracts/ContextSelectorInterface.php`
  - `app/Data/context_library.php`
  - `app/Data/mockup_camera_archetypes.php`
  - `app/Services/CoreArtworkJsonBuilder.php`
  - `app/Services/GeminiArtworkAnalyzer.php`
  - `app/Services/MockArtworkAnalyzer.php`
  - `app/Services/MockContextSelector.php`
  - `app/Services/MockPromptBuilder.php`
  - `app/Services/MockupBranchContextBuilder.php`
  - `app/Services/MockupBranchPromptDraftBuilder.php`
  - `app/Services/MockupCameraArchetypeResolver.php`
  - `app/Services/MockupContextEngine.php`
  - `app/Services/MockupContextIdentity.php`
  - `app/Services/MockupVitalPresenceResolver.php`
  - `app/Services/OpenAIArtworkAnalyzer.php`
  - `app/Support/ArtworkDetailCropPolicy.php`
  - `app/Support/ArtworkDominancePolicy.php`
  - `app/Support/ArtworkEdgePolicy.php`
  - `app/Support/ArtworkScalePolicy.php`
  - `mockup_branches_review.php`

Untracked high-value files include:

- `docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md`
- `tests/`
- `tests/fixtures/camera_slots_snapshot.json`
- `app/Support/ArtworkPhysicalIntegrityPolicy.php`
- `.claude/settings.local.json`
- `.codex/config.toml`

There are many additional untracked generated artifacts under `analysis/`, `mockup-prompts/`, `results/`, `jobs/`, and related folders. Preserve them.

## Current active architecture

### 1. Active user flow

The active flow after root upload/selection goes toward:

- `upload_existing_root.php`
- `root_select.php`
- `select_root.php`
- `core_review.php`
- `mockup_combinations_review.php`
- `generate_mockup_combination.php`
- `mockup_combination_results.php`

The uploaded-root path is covered by tests and must remain protected:

- uploaded root is not regenerated;
- `status.json` records `root_source='uploaded_final'`;
- `generation_skipped=true`;
- redirect goes to `mockup_combinations_review.php`.

### 2. Legacy flow disabled

These endpoints now reject or redirect to the direct world mother combination flow:

- `analyze.php`
- `reanalyze.php`
- `regenerate_mockup_proposals.php`
- `generate_one_mockup_from_composed_admin_prompt.php`
- `mockup_prompt_drafts_review.php`
- `report.php` redirect path updated to `mockup_combinations_review.php`

The JSON error text is:

`Legacy mockup context analysis disabled. Use the direct world mother combination flow (mockup_combinations_review.php).`

Do not revive the old context-analysis chain unless the user makes a product decision to do so.

### 3. Prompt authority

Current mockup prompt composition is centered on:

- `app/Services/AdminPromptComposerPreview.php`
- `app/Support/PromptSettings.php`
- `app/Support/ArtworkPhysicalIntegrityPolicy.php`
- `app/Support/WorldMotherCameraAuthorityPolicy.php`
- `app/Config/mockup_camera_slots.php`
- `app/Services/MockupCombinationEngine.php`

`AdminPromptComposerPreview` now appends the new physical integrity policy:

`ArtworkPhysicalIntegrityPolicy::promptBlock(...)`

The old scattered policies were removed:

- `ArtworkScalePolicy`
- `ArtworkDominancePolicy`
- `ArtworkEdgePolicy`
- `ArtworkDetailCropPolicy`

The conceptual replacement is not "more prompt text"; it is one unified contract for:

- artwork physical truth;
- visual fidelity;
- scale class;
- orientation/aspect ratio;
- camera crop vs artwork mutation;
- edge/painted side behavior;
- presence/dominance resolved photographically, not by inflating artwork size.

### 4. Camera slots

There are exactly 15 active camera slots in the current regression baseline.

The snapshot file is:

`tests/fixtures/camera_slots_snapshot.json`

The tests assert:

- all active slots exist;
- all required fields exist;
- active slot names and base geometry match the snapshot;
- 14 active slots are expected as of 2026-07-02 after removing Camera 15 / Contrapicado Fuerte con Inpainting from the active flow;
- combo 17 and 1NV are not part of this baseline.

Important camera/inpainting slots and concepts:

- `nadir_extremo_arquitectonico`
- `detalle_textura_lienzo`
- `borde_canvas_closeup`
- `esquina_obra_perspectiva_extrema`
- `rasante_superficie_pintura`
- `obra_apoyada_suelo_7_8`

Camera 15 removal note:

- `camara_15_contrapicado_inpainting` remains in the slot catalog as disabled historical code, but it is no longer part of the active experimental set.
- Do not re-enable it unless the user explicitly asks to revisit that camera.
- The current active baseline is 14 slots.

### 5. Full prompt isolation

The regression suite confirms a key new rule: slot-specific full prompts are isolated.

For every slot with `full_prompt_template`, the composed prompt must not inherit generic blocks such as:

- `IMAGE ROLE CONTRACT`
- `MOCKUP CONTEXT PROPOSAL`
- `ROOT ARTWORK VISUAL FIDELITY POLICY`
- `WORLD MOTHER CAMERA AUTHORITY`
- `{{ARTWORK_TITLE}}`
- `{{ARTWORK_SIZE_CLASS}}`
- `{{NEGATIVE_PROMPT}}`
- atmospheric/context-family baggage

This protects detail/crop slots and special camera slots from being polluted by old generic prompt layers.

## Gemini/Vertex bridge state

Key files:

- `app/Services/GeminiMockupGenerator.php`
- `app/Services/GeminiImageClient.php`
- `app/Services/vertex_bridge.py`
- `app/Services/WorldMotherGenerator.php`

Important environment flags and metadata:

- `MOCKUP_USE_PRECOMPOSITION`
- `MOCKUP_PROMPT_FIRST_MODE`
- `MOCKUP_PROMPT_FIRST_NO_MASK_MODE`
- `force_disable_precomposition`
- `prompt_passthrough_mode`
- `slot_full_prompt_mode`
- `skip_world_visual_enhancer`

Observed important behavior:

- `generate_mockup_combination.php` passes `prompt_passthrough_mode` with the composed preview.
- `GeminiMockupGenerator` respects passthrough mode.
- `GeminiMockupGenerator` has targeted env overrides for inpainting/precomposition strategies.
- `WorldMotherGenerator` explicitly disables `MOCKUP_USE_PRECOMPOSITION`, because world mother generation should never use mockup precomposition machinery.
- `vertex_bridge.py` now has substantial logic for green silhouette masks, polygon/nadir precomposition, alpha masks, user-provided masks, and diagnostic logging.

Do not simplify `vertex_bridge.py` blindly. It contains hard-won behavior for inpainting and protected artwork geometry.

## Why the current results are better

The recent improvements seem to come from four linked changes:

1. The system stopped trying to repair every visual failure with another generic prompt paragraph.
2. Legacy context analysis and old prompt builders were disabled/removed from the live path.
3. Camera slots became the real owner of camera geometry and special prompt behavior.
4. Physical artwork integrity became one coherent policy instead of several independent scale/dominance/edge/detail policies that could contradict each other.

This is the product intuition to preserve: presence should be solved as a photographer would solve it, using camera distance, crop, wall choice, focal feel, light, and composition. The artwork should not become physically bigger just to satisfy visual dominance.

## Known risks

### 1. Dirty state is valuable but fragile

There is no commit yet. The current filesystem backup is important, but a git checkpoint would make collaboration much safer.

### 2. Credentials file modified

`storage/credentials.json` is modified. Do not commit or share it. If a future commit is made, explicitly exclude this file unless the user has a secure credential workflow.

### 3. Generated artifacts are numerous

There are many untracked artifacts. They may include the "best results" the user wants to preserve. Do not clean them.

### 4. Old docs conflict with current code

`CURRENT_PROJECT_STATUS.md` and `docs/CODEX_HANDOFF_ADMIN_V7_MOCKUPS.md` include historically useful details, but they describe flows that are partly obsolete after the July 1 audit and cleanup.

Authoritative docs now:

1. current code;
2. `docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md`;
3. this handoff;
4. tests.

### 5. World visual enhancer is likely a future cleanup target

`MockupWorldVisualPromptEnhancer` and `MockupContextWorldRegistry` still exist and are required by current loading paths, but previous audit notes suggest the enhancer may have lost the only path that actively needed it after legacy flows were disabled. Do not remove it casually; first trace live calls and test.

### 6. Results still need visual QA

Tests validate structure and prompt logic, not image quality. Before declaring production-ready, inspect actual generated images for:

- real artwork scale;
- identity preservation;
- no overpainting or invented marks;
- believable room scale anchors;
- Camera 15/nadir inpainting behavior;
- detail slots showing crop without mutating the physical artwork;
- world mother consistency without freezing layout.

## Recommended next steps for Claude Code

### Step 1: confirm backup/checkpoint

Check that this file exists and has a meaningful size:

```bat
dir backups\mockups_best_results_snapshot_20260702_clean.tar.gz
```

If missing or tiny, create a new archive before touching code.

### Step 2: create a git-safe checkpoint

Preferred options:

- create a branch from the current state;
- stage only intended source/test/docs changes;
- keep credentials and generated outputs out of the commit;
- optionally create a separate artifact archive for generated results.

Do not include:

- `.env`
- `storage/credentials.json`
- `results/`
- `uploads/`
- `analysis/` generated raw files unless deliberately needed;
- `mockup-prompts/` generated prompt corpus unless deliberately needed.

### Step 3: inspect the current UI visually

Open:

- `http://localhost/mockups/mockup_combinations_review.php?id={artwork_id}`
- `http://localhost/mockups/mockup_combination_results.php?...`

Use known recent artwork IDs from the generated filenames or DB.

### Step 4: validate one controlled generation

Run one generation per class:

- a normal environment slot;
- a detail crop slot;
- `contrapicado_7_8` or `contrapicado_raton_puro`;
- `obra_apoyada_suelo_7_8`;
- one environmental/nadir slot.

For each, save:

- final prompt;
- images used;
- env flags;
- output image;
- visual notes.

### Step 5: decide whether to keep or remove remaining world enhancer layer

Only after tracing live use:

- `MockupWorldVisualPromptEnhancer`
- `MockupContextWorldRegistry`
- `mockup_context_worlds.php`
- `mockup_context_families.php`
- `mockup_scene_variants.php`
- `mockup_camera_context_compatibility.php`

If the active direct flow is already full-prompt/slot-first, the enhancer may be redundant. But remove it only with tests and a clear product decision.

### Step 6: improve observability

Add or verify an audit view that shows:

- chosen world mother;
- chosen camera slot;
- generation strategy;
- final prompt exactly sent;
- env overrides;
- whether inpainting/precomposition was used;
- image references and masks;
- dimensions and orientation used by `ArtworkPhysicalIntegrityPolicy`.

This is more useful than adding more prompt text.

## Files Claude Code should read first

Read in this order:

1. `docs/CLAUDE_HANDOFF_BEST_RESULTS_20260702.md`
2. `docs/AUDITORIA_PROMPTS_MOCKUPS_20260701.md`
3. `tests/run_regression_tests.php`
4. `tests/regression/camera_slots_test.php`
5. `tests/regression/slot_full_prompt_isolation_test.php`
6. `tests/regression/uploaded_root_test.php`
7. `app/Config/mockup_camera_slots.php`
8. `app/Support/ArtworkPhysicalIntegrityPolicy.php`
9. `app/Services/AdminPromptComposerPreview.php`
10. `app/Services/MockupCombinationEngine.php`
11. `generate_mockup_combination.php`
12. `mockup_combinations_review.php`
13. `app/Services/GeminiMockupGenerator.php`
14. `app/Services/GeminiImageClient.php`
15. `app/Services/vertex_bridge.py`
16. `app/Services/WorldMotherGenerator.php`

## Suggested language to the next assistant

Use this summary when handing over:

"The project is in a valuable dirty state after a major July 1-2 mockup architecture cleanup. Preserve it before changing anything. The active system is now direct world-mother combinations with camera-slot authority and a unified ArtworkPhysicalIntegrityPolicy. Legacy context analysis and old prompt builders were disabled/removed. Camera 15 / Contrapicado Fuerte con Inpainting was removed from the active flow, leaving 14 active slots. Regression tests pass with Laragon PHP. The main risk is losing uncommitted generated results or accidentally reviving old prompt/context layers. Continue by checkpointing, visually QA-ing the best generated outputs, and improving traceability around prompt/env/mask usage rather than adding more generic prompt text."
