# AI Handoff

## Repository state

- Current branch: `codex-system-audit`
- Latest commits:
  - `61d9842` Add auth page image assets
  - `691e5a3` Implement Social Video beta workflow
  - `eebbc2e` fix: corregir extracción de JSON en MockupContextEngine
  - `fe8c735` feat: optimizar paralelismo y migrar a MySQL
- The branch was clean when this handoff was written.

## Current workflow labels

The sidebar’s current product flow is:

1. Upload Artwork
2. Select Root Artwork
3. Curated Mockups
4. Artwork Details
5. Social Video (beta)

## Social Video beta: current behavior

- An artwork page links to `social_video.php?id=<artwork_id>`.
- The page prepares an editable setup proposal from the root artwork, analysis, mockups, and artist profile.
- It can generate and save a JSON video concept, then submit a Veo generation request.
- The generation route calls Veo synchronously, creates one to five sequential segments, extracts a final frame for continuity, concatenates multi-segment output with FFmpeg, and saves the final relative MP4 path on `social_video_workflows`.
- `media.php` authorizes and serves final files under `results/social-video/*.mp4`.
- Admin API settings include Veo enablement, model, region, resolution, output bucket, and FFmpeg path.

## Main files involved

- UI and routes: `social_video.php`, `social_video_run.php`, `artwork.php`, `sidebar.php`, `media.php`
- Services: `app/Services/SocialVideoService.php`, `app/Services/VeoVideoClient.php`, `app/Services/veo_bridge.py`
- Wiring and configuration: `app/bootstrap.php`, `app/Support/ProviderSettings.php`, `admin_api_keys.php`, `admin_prompts.php`, `app/Support/PromptSettings.php`, `config.php`
- Persistence: `app/Support/Database.php` (`social_video_workflows`, `social_video_jobs`)
- Operational scripts: `scripts/inspect_social_video_error.php`, `scripts/repair_social_video_status.php`, `scripts/test_veo_single_segment.php`, `scripts/test_veo_continue_segment.php`, `scripts/test_veo_two_segments.php`

## Pending work

- Implement an actual isolated background queue/worker for `social_video_jobs`; the table is currently not consumed.
- Make `social_video_run.php` POST-only, add CSRF protection, and prevent duplicate concurrent runs for an artwork.
- Move long-running Veo work out of the web request and add durable polling/retry/error state.
- Require the intended global real-API safeguards before allowing Veo execution.
- Validate all Veo configuration before submission; avoid relying on fallback project/bucket values.
- Ensure Python receives credentials when they are configured only in `.env` (the current dotenv loader does not call `putenv`).
- Decide whether selected mockup references should become actual Veo image inputs; current generation uses only the root artwork.
- Add integration tests for SQLite and MySQL schema initialization, Veo disabled/enabled flows, media authorization, FFmpeg failure, and timeout/retry handling.

## Do not change without explicit direction

- Do not remove or rename the existing five workflow labels/routes; they are user-facing navigation.
- Do not expose `results/social-video` directly or weaken the ownership checks in `media.php`.
- Do not commit generated folders (`results/`, `storage/`, `analysis/`, `mockup-prompts/`) or credentials.
- Do not change the persisted workflow/job schema casually; it supports both SQLite and MySQL initialization.
- Do not treat the current synchronous generator as a safe production queue.

## Instructions for the next coding assistant

1. Start by reading this file and checking `git status`/recent commits.
2. Keep Social Video work scoped to its own queue and avoid changing mockup-worker behavior.
3. Before any provider change, trace the path from `ProviderSettings` through `VeoVideoClient` to `veo_bridge.py` and verify credentials/configuration in the target environment.
4. Preserve authorization checks for artwork ownership and generated media.
5. Prefer small, separately testable changes. Run PHP linting for changed PHP files and test both the disabled and enabled Social Video paths.
6. Update this handoff when behavior, schema, routes, or operational risks change.
