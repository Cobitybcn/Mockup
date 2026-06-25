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
theartworkcurator.ai

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
   4. Curated Mockups (`report.php` / `mockup_batch_wait.php`)
   5. Artwork Details / Publish (`artwork.php` / `publish.php`)
3. **Data Source**: `core_review.php` reads directly from `analysis/core/{artwork_id}.core.json`.
4. **Curatorial Exploration Branches**: The 3 proposals parsed from `publishing_texts.suggested_titles` are shown as three future mockup exploration directions (Branch 1, 2, and 3), not final title choices.
5. **Explore Directions**: The "Explore this direction" buttons are placeholders and remain disabled until Phase 2 mockup generation is developed.
6. **Future Integrations**: The Mockups Builder must read technical and physical data directly from `analysis/core/{artwork_id}.core.json`.