# CODEX HANDOFF: ADMIN V7 MOCKUPS SYSTEM

This document establishes the state of the mockups system for **Codex** before it assumes the role of main project orchestrator.

---

## 1. Current Strategic Decision

* **Codex** will become the main project orchestrator.
* **Antigravity** will remain available to assist with isolated, specific tasks, including Vertex/Gemini/Google technology integration, Python bridge diagnostics, API-specific debugging, browser verification, and minor UI or style tasks.

---

## 2. Critical Project Rule

* **ADMIN Prompts Governance**: Prompts are strictly governed from the ADMIN panel database settings.
* **No Code Injections**: Do not hardcode, inject, append, or rewrite master prompt content directly inside PHP or Python files.
* **Manual Admin Updates**: Any prompt wording or instruction changes must be made manually through the ADMIN user interface.
* **Code Responsibility**: Code must only ensure reading from the ADMIN prompt settings, variable replacement, strict prompt pass-through, traceability audit logging, validation, and safe fallbacks.

---

## 3. Current ADMIN Prompt Structure

The system relies on two main settings-configured prompts:

### A. Artwork Analysis / Form 2
* **Role**: Analyzes the definitive root artwork image and suggests contextual spaces.
* **Output**: Must return valid JSON only (no markdown, backticks, or comment wrappers). The JSON contains:
  * `suggested_titles[]` (curated title alternatives)
  * `contextual_proposals[]` (array of contextual mockup spaces, containing `camera_view`, `camera_distance`, `camera_angle_notes`, `mockup_prompt`, and `negative_prompt`).
* **Current Intended 6-Proposal Camera Distribution**:
  1. **Proposal 1**: `front view`
  2. **Proposal 2**: `three-quarter left view`
  3. **Proposal 3**: `three-quarter right view`
  4. **Proposal 4**: `high-angle view` / aerial elevated view
  5. **Proposal 5**: `low-angle view` / contrapicado
  6. **Proposal 6**: `low floor wide low-angle view` / dramatic low floor contrapicado

### B. Final Mockup Request / Admin V7
* **Role**: Generates the final photorealistic mockup image.
* **Placeholder**: Uses `{{MOCKUP_CONTEXT_PROPOSAL}}` as the placeholder where the chosen context proposal block is injected.
* **Subordination**: The injected context proposal acts as subordinated scene data. The generator must respect the proposal's `camera_view`, `camera_distance`, and `camera_angle_notes` without overriding the master scale, safety, and priority directives defined in the master prompt.

---

## 4. Recent Prompt Cleanup Summary

During recent diagnostic reviews, two major issues were addressed:

1. **Prompt 1 (Analysis) Camera System Contradictions**:
   * *Problem*: The analysis prompt previously mixed a legacy "seven-eighths" camera distribution with the new "high-angle / low-angle / low floor wide low-angle" distribution.
   * *Resolution*: Cleaned up to enforce only the new 6-camera distribution.

2. **Prompt 2 (Final Request) Composition Restrictions**:
   * *Problem*: The final request prompt template contained very strict negative directives (`No floor-dominant composition`, `No room-dominant composition`, `No full-room context`, `No excessive distance`) intended to keep the artwork prominent. However, when an advanced angle (high-angle looking at the floor or low floor wide view) was requested, these directives directly contradicted the angle's requirements, forcing the model to fallback to eye-level frontal views.
   * *Resolution*: These rules were softened or made conditional for advanced camera views so that the model can render the requested camera angles without artificial suppression.

---

## 5. Phase Separation

It is critical to distinguish between the two core phases of the generation pipeline:

### PHASE 1 — Root Artwork Views
* **Status**: Fully functional and must not be altered or damaged.
* **Purpose**: Generates and selects the definitive root artwork image.
* **Outputs**:
  * Frontal root view (`v1`)
  * Three-quarter left root view (`v2`)
  * Three-quarter right root view (`v3`)
  * Prepares the `CORE JSON` data containing dimensions and orientation.

### PHASE 2 — Curated Contextual Mockups
* **Status**: Under review. Must use the new composed Admin V7 prompt flow.
* **Flow**:
  `mockup_prompt_drafts_review.php`  
  &rarr; `generate_one_mockup_from_composed_admin_prompt.php`  
  &rarr; `AdminPromptComposerPreview`  
  &rarr; `GeminiMockupGenerator`  
  &rarr; `vertex_bridge.py`

> [!WARNING]
> Do not mix Phase 1 root views (which are isolated product photos of the painting itself) with Phase 2 contextual mockups (which are interior scenes showing the painting installed on walls).

---

## 6. Known Concern to Audit Later

There is a background queue pathway that automatically triggers immediately after selecting a root image:
* **Pathway**: `select_root.php` &rarr; `MockupBatchQueue::enqueueInitialBatch` &rarr; `process_mockup_queue.php` &rarr; `GeminiMockupGenerator` &rarr; `vertex_bridge.py`
* **Concern**: This automatic queue reads the pre-calculated prompt from the `mockup_contexts.prompt` database column. This column is written by `MockPromptBuilder` during context analysis, bypassing the Admin V7 composed prompt template.
* **Action Required**: Codex must audit this background path carefully to determine whether it should be modified to use the V7 composed flow or if it is required to preserve Phase 1 compatibility. Do not disable it blindly, as it currently generates the initial mockup set.

---

## 7. "Regenerate Mockup Proposals" Endpoint

* **File**: `regenerate_mockup_proposals.php`
* **Purpose**: Regeneates only the mockup context proposals for an existing artwork (deleting and replacing rows in the `mockup_contexts` database table).
* **Constraints**: It must **not** re-upload the artwork, regenerate root artwork images, repeat CORE analysis, edit `.core.json`, or modify existing generated mockup image files.
* **Known Recent Issue**: A browser error saying `"Path cannot be empty"` has been observed.
* **Investigation Area**: Check the image path resolution logic inside `regenerate_mockup_proposals.php` before it invokes `MockupContextEngine::analyzeArtworkContext()`.

---

## 8. Key Files Directory

Below are the key files of the system and their respective roles:

| File Path | Role |
| :--- | :--- |
| [`mockup_prompt_drafts_review.php`](file:///c:/laragon/www/mockups/mockup_prompt_drafts_review.php) | Main interface for Form 2.1 prompt draft review and testing composed V7 prompts. |
| [`regenerate_mockup_proposals.php`](file:///c:/laragon/www/mockups/regenerate_mockup_proposals.php) | Endpoint to regenerate contextual proposals for an artwork. |
| [`generate_one_mockup_from_composed_admin_prompt.php`](file:///c:/laragon/www/mockups/generate_one_mockup_from_composed_admin_prompt.php) | Endpoint that generates a single mockup using the composed V7 prompt. |
| [`select_root.php`](file:///c:/laragon/www/mockups/select_root.php) | Phase 1 root selection and triggers background batch queue. |
| [`core_review.php`](file:///c:/laragon/www/mockups/core_review.php) | Interface to review analysis metadata and dimensions (CORE JSON). |
| [`generate_mockup.php`](file:///c:/laragon/www/mockups/generate_mockup.php) | Legacy/individual mockup generation page (Form 2 submit handler). |
| [`process_mockup_queue.php`](file:///c:/laragon/www/mockups/process_mockup_queue.php) | CLI background worker that processes enqueued mockup jobs. |
| [`app/Services/AdminPromptComposerPreview.php`](file:///c:/laragon/www/mockups/app/Services/AdminPromptComposerPreview.php) | Service that builds the final composed prompt utilizing variables and placeholders. |
| [`app/Services/MockupContextEngine.php`](file:///c:/laragon/www/mockups/app/Services/MockupContextEngine.php) | Engine managing analysis prompts, context generation, and DB insertion. |
| [`app/Services/MockPromptBuilder.php`](file:///c:/laragon/www/mockups/app/Services/MockPromptBuilder.php) | Builder that creates the old-style/legacy prompts for the queue. |
| [`app/Services/GeminiMockupGenerator.php`](file:///c:/laragon/www/mockups/app/Services/GeminiMockupGenerator.php) | Mockup generator service that coordinates with GeminiImageClient. |
| [`app/Services/GeminiImageClient.php`](file:///c:/laragon/www/mockups/app/Services/GeminiImageClient.php) | Low-level PHP client calling `vertex_bridge.py` for model processing. |
| [`app/Services/vertex_bridge.py`](file:///c:/laragon/www/mockups/app/Services/vertex_bridge.py) | Python bridge script interacting with the Google GenAI SDK. |
| [`app/Services/MockupBatchQueue.php`](file:///c:/laragon/www/mockups/app/Services/MockupBatchQueue.php) | Service managing enqueuing, claiming, and completion of mockup jobs. |
| [`app/Support/PromptSettings.php`](file:///c:/laragon/www/mockups/app/Support/PromptSettings.php) | Loads prompt templates and default settings from the `app_settings` DB table. |
| [`app/Support/Logger.php`](file:///c:/laragon/www/mockups/app/Support/Logger.php) | Logs execution traces and logs detailed `logMockupGeneration` audits. |

---

## 9. Logs and Audit Locations

* **Application Logs**: [`logs/app.log`](file:///c:/laragon/www/mockups/logs/app.log) (contains `MOCKUP_AUDIT` entries detailing generated mockups, dimensions, MD5s, and camera details).
* **Prompt Logs**: [`logs/prompt_debug/`](file:///c:/laragon/www/mockups/logs/prompt_debug) (stores final prompts sent to Vertex as `mockup_{id}_final_prompt.txt`).
* **Python Logs**: [`logs/vertex_bridge.log`](file:///c:/laragon/www/mockups/logs/vertex_bridge.log) (stores execution parameters for Imagen runs).
* **Handoff Generation Audits**: [`analysis/mockup-generation-audit/`](file:///c:/laragon/www/mockups/analysis/mockup-generation-audit) (stores detailed pre-generation `.generation.json` files per context, mapping variables and exact prompt text).

---

## 10. Immediate Next Tasks for Codex

When Codex takes over, it should prioritize the following actions:

* [ ] **A. Create Git Checkpoint**: Create a clean Git commit before starting any code changes.
* [ ] **B. Verify Prompts in Admin**: Confirm that Prompt 1 (Analysis) and Prompt 2 (Final Request) are correctly stored in the database and loaded by the application.
* [ ] **C. Test Proposal Regeneration**: Debug the `"Path cannot be empty"` browser error in `regenerate_mockup_proposals.php`.
* [ ] **D. Verify Camera Views**: Confirm the database `mockup_contexts` are correctly populated with the 6 expected views.
* [ ] **E. Test Manual Generation**: Click "Generate 6 Reviewed Mockups" and verify that all 6 mockups are correctly run using the Admin V7 composed prompt.
* [ ] **F. Confirm Composed Prompt Authority**: Ensure the final prompt sent to Vertex matches the composed preview.
* [ ] **G. Phase 1 vs Phase 2 Separation**: Inspect the background queue worker flow to ensure Phase 2 contextual mockups do not accidentally run using legacy rules, while protecting the working Phase 1 root artwork generator.
* [ ] **H. Improve Traceability**: Standardize logger calls so that background queue mockups log details like camera view and human presence as cleanly as the manual composed flow does.

---

## 11. Git Information

* **Current Branch**: `codex-main-orchestration`
* **Latest 5 Commits**:
  * `b2273c71` Checkpoint: Admin V7 mockup prompt cleanup and phase separation audit
  * `21623da8` Complete CORE JSON 1.1 and core review workflow
  * `42278234` Fix status.json race condition and add prompt version badge next to Curatorial Direction
  * `0ddce6a4` Initial commit: Sistema Antigravity limpio y desde cero
  * `da31c7cb` update report.json with additional visual and purchase desire metrics
* **Current Git Status Summary**:
  * Added documentation file: `docs/CODEX_HANDOFF_ADMIN_V7_MOCKUPS.md`
  * Untracked temporary files (from this audit):
    * `scratch_audit.php`
    * `scratch_list_latest.php`
    * `scratch_check_logs.php`
    * `scratch_search_all_ids.php`
    * `scratch_git.php`
    * `scratch_git_output.json`
    * `scratch_latest_mockups.json`
    * `scratch_log_matches.json`
    * `scratch_errors.json`
    * `scratch_timeline.json`

*(Note: No files have been committed to git as part of this audit.)*
