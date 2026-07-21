# Approved Visual Reference

## What pattern does this screen define?

The Series screen (`platform/series.php`) top module defines the canonical Decision Block pattern: a row of square, pastel-colored blocks (STRATA, GENESIS, CORE, MEDITERRANEO ONIRICO, STRATIFIED FACES, INNER VORTEX), each one a single decision surface with a short bold label and a one-line status/count beneath it, plus a matching empty "+" block for creating a new one. Each block uses one flat, muted pastel color per section identity — no gradients, no icons, no imagery inside the block.

## When should it be reused?

Reuse this pattern whenever a screen needs to represent a small set of top-level, mutually exclusive choices or categories (series, boards, channels, camera groups) that the artist picks between before drilling into content. It is the reference for `MASTER_PATTERNS/01_decision_blocks` and for `MASTER_PATTERNS/06_section_colors`.

## What must never change?

- Square (not rectangular) geometry — never interchange with a Thumbnail Card.
- One flat muted pastel color per block, tied consistently to that section's identity elsewhere in the app.
- Label kept short, centered, max two lines; a subordinate one-line status/count below it, never a paragraph.
- The blocks sit in a single row with generous surrounding space, not a dense grid.
- The "add new" affordance stays visually the same size and weight as the other blocks (a quiet dashed "+" block), not a separate button.
