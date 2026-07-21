# Master Pattern: Thumbnail Cards

## Pattern

Thumbnail Cards represent visual content: artworks, mockups, videos, images, and generated material. They are working material rather than decisions or file icons.

Rectangular geometry communicates visual content. It must remain semantically distinct from square Decision Blocks.

## Invariants

- Give the image most of the available surface.
- Use rectangular image-led geometry.
- Preserve the crop or framing meaningful to the workflow.
- Keep labels, counters, and states compact.
- Use the image as the direct manipulation surface when established.
- Keep interface decoration from competing with the content.

## Never

- Use a Thumbnail Card for principal navigation, creation, or context decisions.
- Reduce thumbnails to dense administrative records.
- Surround them with repeated metadata.
- Reserve a permanent toolbar below the image.
- Replace rectangular content geometry with a square Decision Block.

## Reference capture to store here

Store one tightly framed `reference.png` showing an approved group of rectangular Thumbnail Cards with real image crops, concise labels, spacing relationships, and an established selection, focus, or drag state. Include enough neighboring cards to demonstrate comparison scale and soft visual density.

Add `NOTES.md` only when the capture is approved, following the three-question format defined in `design-system/references/README.md`.

**Approved reference on file:** `design-system/references/scene-mockups/` (Scene Mockups screen, `platform/mockup_combination_results.php`).
