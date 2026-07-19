# Master Pattern: Carousels

## Pattern

Carousels are horizontal galleries inside the vertical workspace flow. They preserve the visual scale needed to recognize and compare images.

## Invariants

- Maintain a horizontal sequence.
- Keep thumbnails large enough for visual evaluation.
- Preserve consistent rhythm and spacing across items.
- Use restrained previous and next controls where necessary.
- Preserve the user's position.
- Show the current position or count only when it aids orientation.

## Never

- Shrink every item to fit the entire collection at once.
- Use large navigation bars or verbose pagination.
- Move automatically while the user is inspecting images.
- Mix unrelated content without a grouping rule.
- Give navigation controls more prominence than the images.

## Reference capture to store here

Store one wide `reference.png` showing an approved carousel with several complete thumbnails, at least one partially continuing item, its real spacing rhythm, and any established navigation or position indicator. The capture must demonstrate horizontal continuation and useful image scale.

Add `NOTES.md` only when the capture is approved, following the three-question reference format defined in `design-system/references/README.md`.
