# Master Pattern: Drag and Drop

## Pattern

Drag and drop is the primary method for ordering, grouping, moving, and assigning visual material. The gesture expresses the worktable metaphor directly.

## Invariants

- Make the image or thumbnail the draggable surface when established.
- Make valid destinations legible before dragging begins.
- Show a quiet but unmistakable active destination state.
- Keep the layout stable throughout the gesture.
- Confirm the result by updating the destination in place.
- Provide an accessible alternative that reaches the same result.

## Never

- Hide the destination until the user starts dragging.
- Require a separate drag handle when the image already performs that role.
- Use saturated alarm colors for normal drop feedback.
- Move the target during the gesture.
- Discard the current arrangement when an assignment fails.

## Reference capture to store here

Store one `reference.png` showing an approved drag operation with the draggable image and active destination visible in the same frame. The capture must clearly record the drag state, destination feedback, surrounding layout stability, and the spatial relationship between source and target.

Add `NOTES.md` only when the capture is approved, following the three-question reference format defined in `design-system/references/README.md`.
