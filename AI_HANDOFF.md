# AI Handoff — Social Video beta (2026-06-20)

## Purpose of this handoff

The user wants Gemini to continue the Social Video work from the actual current state. Read this file before making changes. The active product goal has changed several times; the latest direction takes priority.

## Latest product direction (authoritative)

Social Video must create a video **from the user-selected, manually ordered existing mockups only**.

- No root artwork image in this FFmpeg step.
- No AI-generated scenes, images, prompts, captions, or narrative structure in this FFmpeg step.
- No milestone timeline, archetypes, reference uploads, or manual anchor logic.
- The user orders a variable number of mockups (ideal: 2–12; six is the common case).
- FFmpeg creates a vertical social MP4 with restrained Ken Burns movement and premium/curatorial transitions.
- The generated MP4 should preserve each mockup rather than destructively cropping it.

The next desired phase is a **second, optional Veo finishing pass**:

`ordered mockups → FFmpeg base MP4 → Veo video input/reference + one Admin-configured creative-finishing prompt → final MP4`

Do not implement that Veo phase by describing an MP4 in text. The MP4 must be accepted as an actual video input/reference by the supported Veo API/model. The current bridge does **not** support this yet.

## Current active UI and routes

- `social_video.php` now immediately includes `social_video_simple.php` and exits. The older implementation below that exit is legacy/unreachable and should eventually be removed carefully.
- `social_video_simple.php` is the active interface. It shows the selected mockups as a re-orderable grid and a single main action, **Generar video**.
- `report.php` and `artwork.php` both contain a **Generate Video** entry link from generated mockup cards.
- `generate_social_video.php` validates ownership and selected mockups, then creates the FFmpeg MP4 directly.
- `delete_social_video.php` deletes a generated MP4 and clears only its workflow video fields.
- Generated MP4s remain private and are served through `media.php` under `results/social-video/`.

## FFmpeg implementation

Primary service: `app/Services/MockupSequenceVideoService.php`

Current intent of the implementation:

- Inputs: ordered mockup filenames; options for aspect ratio, width/height, FPS, still duration and transition duration.
- Defaults: 9:16, 1080×1920, 30 FPS, 3.8 seconds per mockup, 1 second transition.
- Supports 2–12 mockups.
- Creates H.264 / `yuv420p` MP4 files named `social-video-{artworkId}-{timestamp}.mp4`.
- Builds a blurred/darkened background from each mockup and overlays the complete mockup on top, avoiding destructive crop of the artwork/interior.
- Alternates intended movement presets: slow zoom in/out, left/right gallery pans, vertical breath, collector gaze, architectural slide.
- Alternates intended sober `xfade` transitions: fade, smooth directions, wipes and circleopen.
- Returns metadata with mockup count, transitions, duration, aspect, dimensions, FPS, movements and transitions.

### Important: verify this FFmpeg filter end-to-end

PHP linting passed, but the latest multi-filter Ken Burns implementation has **not yet been rendered and visually reviewed** after the last change. Gemini must run a controlled test with 2–3 real mockups, inspect the output, and correct any FFmpeg expression/xfade issue before treating it as production-ready.

Specifically verify:

1. Every selected mockup appears exactly and in the chosen order.
2. No artwork is materially cropped or deformed.
3. The blurred background does not alter the foreground mockup.
4. Movement remains slow and not distracting.
5. Each N-image sequence produces N-1 transitions.
6. FFmpeg output is playable in the browser and downloadable through `media.php`.

## Veo status and required next investigation

Existing Veo path:

- `social_video_run.php`
- `app/Services/VeoVideoClient.php`
- `app/Services/veo_bridge.py`

It currently supports only **one image** attached as `instance['image']` (`bytesBase64Encoded`). It does not send mockups as actual visual inputs and cannot accept an MP4. Previous Veo results therefore did not reliably resemble selected mockups. Do not reuse that path for mockup-to-video generation.

For the desired finishing pass, Gemini must:

1. Confirm the configured Vertex Veo model’s supported video-input/video-extension API schema using current official Google documentation.
2. Determine whether input video must be supplied as a GCS URI, uploaded file resource, or inline bytes, and whether arbitrary FFmpeg MP4s are accepted.
3. Update `veo_bridge.py` and `VeoVideoClient.php` to pass the FFmpeg MP4 as a real video input/reference.
4. Add a single Admin-editable prompt specifically for creative finishing of that base video. It must not ask Veo to recreate the artwork or invent a new scene.
5. Preserve the FFmpeg MP4 as the source/base artifact and save Veo output as a separate final artifact, so the user can keep or delete either intentionally.
6. Test one small real video end-to-end before exposing it broadly.

Do not guess field names such as `video`, `lastFrame`, or `gcsUri` without verifying the model/API contract. The previous assistant explicitly paused this work rather than sending an invented request shape.

## Prompt/Admin cleanup already performed

The former Social Video Selector Prompt, Social Video Director Prompt and archetype UI were removed from `admin_prompts.php`, `PromptSettings`, and matching stored `app_settings` rows. This was intentional because the current FFmpeg-only stage does not use prompts.

When adding the Veo finishing phase, introduce one clearly named prompt only, e.g. `social_video_veo_finishing_prompt`. Do not restore selector, archetype, timeline, or director prompts.

## Earlier experiments to treat as obsolete

- `social_video_timeline.php`: five-milestone UI experiment; no longer the target UX.
- `app/Services/SocialVideoGapFillService.php`: gap-fill image experiment; no longer needed.
- `app/Support/SocialVideoArchetypes.php`: archetype persistence experiment; no longer needed.
- Selector/archetype methods remaining in `SocialVideoService.php` are legacy code; they are not active due to the early route exit.

Gemini may remove these obsolete files/code only after confirming no active route or include requires them. Keep the working FFmpeg route intact.

## Current repository state

This worktree is dirty. Relevant modified/new files include:

- `social_video.php`
- `social_video_simple.php` (new active UI)
- `generate_social_video.php` (new)
- `delete_social_video.php` (new)
- `app/Services/MockupSequenceVideoService.php` (new)
- `app/bootstrap.php`
- `admin_prompts.php`
- `app/Support/PromptSettings.php`
- `report.php`
- `artwork.php`

Other new files are legacy experiments listed above. Do not discard unrelated user changes.

## Security and operational constraints

- Preserve ownership checks: only the owner of an artwork may generate, view, download or delete its social video.
- Do not expose `results/social-video/` directly; retain `media.php` authorization.
- Do not commit generated outputs, storage, analysis, prompt folders, or credentials.
- The FFmpeg generator currently runs synchronously in the web request. For longer sequences it needs progress reporting or a background job; do not claim live progress until it exists.
- Existing Veo requests are also synchronous and require provider/model/GCS/credential validation before use.

## Recommended next steps for Gemini

1. Run and inspect a 2–3 mockup FFmpeg test; fix the current filter graph if needed.
2. Move the generated-video preview immediately below the Generate Video control and keep it compact (thumbnail-scale), as requested; inspect the rendered active page, not just source.
3. Ensure the existing FFmpeg output can be downloaded and deleted cleanly.
4. Research and implement verified Veo MP4 input/video-extension support as the optional finishing stage.
5. Add only one Admin prompt for that optional Veo finish.
6. Remove obsolete timeline/archetype/selector code after the active FFmpeg flow is confirmed.
