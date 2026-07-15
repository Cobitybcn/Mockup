# Current Project Status — The Artwork Curator

This file is the current working reference for Codex.

Older documentation files may be outdated:

- README.md
- INSTRUCCIONES_CODEX.md
- CONTEXTO_BETA_MOCKUPS.md

These files must be read as historical context, not as absolute truth.

Current source of truth:

1. The current codebase.
2. The current working system at http://localhost/mockups
3. This file.

Project name:
The Artwork Curator

Domain:
artworkmockups.com

Tagline:
AI-curated mockups for paintings, drawings and original artworks.

Current goal:
Perform a full system audit before making new changes.

Important:
Codex must not modify code yet.
Codex must first compare documentation with the actual current codebase.

Expected Codex output:
A technical audit report explaining what is current, what is outdated, what is risky, and what the safest next development steps are.

---

## Core Workflow Status (Stable & Frozen)

The CORE JSON 1.1 architecture and Core Review workflow are now stable and frozen.

### Key Facts:
1. **Redirection Target**: After selecting the root artwork in `select_root.php`, the system redirects to `core_review.php?id={artwork_id}`.
2. **Workflow Order**:
   1. Upload Artwork (`artwork_new.php`)
   2. Select Root Artwork (`root_select.php` / `waiting.php`)
   3. Review Artwork Core (`core_review.php`)
   4. Artwork Details (`artwork_details.php` → `artwork.php` / `publish.php`)
   5. Curated Mockups (`curated_mockups.php` → `report.php` / `mockup_batch_wait.php`)
3. **Data Source**: `core_review.php` reads directly from `analysis/core/{artwork_id}.core.json`.
4. **Curatorial Exploration Branches**: The 3 proposals parsed from `publishing_texts.suggested_titles` are shown as three future mockup exploration directions (Branch 1, 2, and 3), not final title choices.
5. **Explore Directions**: The "Explore this direction" buttons are placeholders and remain disabled until Phase 2 mockup generation is developed.
6. **Future Integrations**: The Mockups Builder must read technical and physical data directly from `analysis/core/{artwork_id}.core.json`.

---

## Phase 2.0, 2.1 & 2.2 Status (Implemented & Validated)

The Mockup Branch Context Builder (Phase 2.0), Mockup Branch Prompt Draft Builder (Phase 2.1), and Manual Mockup Prompt Draft Approval (Phase 2.2) are completed and validated.

### Key Facts:
1. **Mockup Branch Contexts**: `MockupBranchContextBuilder.php` reads the CORE JSON 1.1 and outputs normalized branch contexts to `analysis/mockup-branches/{artwork_id}.branches.json`.
2. **Review Screen (Phase 2.0)**: `mockup_branches_review.php` displays these branches in a read-only technical review panel. If the JSON is missing, it auto-generates it on the fly.
3. **Prompt Draft Builder (Phase 2.1)**: `MockupBranchPromptDraftBuilder.php` reads CORE JSON and branches JSON and outputs exactly 3 prompt drafts with 10 segmented blocks to `analysis/mockup-prompt-drafts/{artwork_id}.prompt-drafts.json`.
4. **Drafts Review Screen (Phase 2.1)**: `mockup_prompt_drafts_review.php` renders the prompt drafts in cards with read-only textareas. It dynamically auto-generates missing drafts JSON files.
5. **Prompt Approval Service (Phase 2.2)**: `MockupPromptApprovalService.php` accepts approved draft indexes, merges them cleanly without duplicates, and saves them to `analysis/mockup-approved-prompts/{artwork_id}.approved-prompts.json`.
6. **Approval Endpoint & Action Buttons (Phase 2.2)**: `approve_mockup_prompt_draft.php` processes GET approval requests, and `mockup_prompt_drafts_review.php` renders action buttons or check badges per draft card and displays a summary table of approved prompts if they exist.
7. **No Generation Pipeline Modifications**: No Vertex/Gemini connections are triggered, and existing mockup pipelines (`MockPromptBuilder.php`, `generate_mockup.php`, `process_mockup_queue.php`, `vertex_bridge.py`) are unmodified.

---

## Phase 2.3 Status (Completed & Validated)

The Admin V7 Composed Prompt and One-by-One Generation flow are completed and validated.

### Key Facts:
1. **Sovereign Prompt**: The Admin V7 prompt stored in `mockup_final_request` (via `PromptSettings::mockupFinalRequest()`) is the sole template authority. It contains the `{{MOCKUP_CONTEXT_PROPOSAL}}` placeholder.
2. **Strict Composing**: `AdminPromptComposerPreview.php` parses context proposals from `mockup_contexts` and replaces `{{MOCKUP_CONTEXT_PROPOSAL}}` with a clean, un-contaminated context block.
3. **Passthrough Generation Mode**: `GeminiMockupGenerator.php` accepts a `prompt_passthrough_mode` metadata parameter which, if specified, forces Vertex/Gemini to receive the exact composed prompt string without appending legacy/experimental directives.
4. **Controlled Endpoint**: `generate_one_mockup_from_composed_admin_prompt.php` performs authentication, ownership validation, deterministically computes proposal indexes, writes a pre-generation audit JSON, processes credit deduction if applicable, triggers passthrough generation, inserts the resulting mockup to database, and updates the audit JSON with result details.
5. **Auditing**: Creates comprehensive audit files in `analysis/mockup-generation-audit/{artwork_id}/composed-admin-context-{context_id}-{timestamp}.generation.json` verifying that the prompt sent to Vertex matches the composed prompt exactly.
6. **UI Integration**: Added a dedicated "Phase 2.3 — Admin V7 Composed Prompt Test" section to `mockup_prompt_drafts_review.php` with read-only previews, copy-to-clipboard actions, and interactive generation controls.
