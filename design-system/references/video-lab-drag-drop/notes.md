# Approved Visual Reference

## What pattern does this screen define?

This capture, scoped narrowly to the reference catalog rail and sequence Drop Zones in Video Lab (`platform/video.php`), defines the approved Drag Before Forms pattern: a horizontal rail of real, selectable reference images; a selected reference shown with a quiet ring state and a plain-language hint ("Referencia seleccionada. Haz clic en el destino donde quieras utilizarla, o arrástrala."); and numbered Sequence cards whose two open slots are dashed, circular "+" Drop Zones with a small "Arrastra aquí" label, ready to receive material by click or drag.

This reference covers only the catalog rail and Drop Zone slots. It does **not** approve the rest of this screen.

## When should it be reused?

Reuse this Drop Zone treatment (dashed circular target, small instruction label, ring state on the source once selected, click-or-drag as equivalent actions) anywhere material must be assigned into an ordered slot — sequences, boards, camera assignments. It is the reference for `MASTER_PATTERNS/05_drag_drop`.

## What must never change?

- The destination is visible before the gesture starts (empty slots always show their dashed outline and "+"), never appearing only mid-drag.
- Selecting a source gives it a quiet ring/selected state, not a saturated highlight.
- Click and drag remain equivalent ways to fill a slot — drag is never the only path.
- Slots stay compact and subordinate to the reference imagery above them.

## Known deviation — do not copy

The header action row on this same screen (GUARDAR / NUEVO / ELIMINAR as three equal-weight pastel buttons) is flagged `NEEDS CONSISTENCY PASS` in `design-system/audits/VISUAL_CONSISTENCY_MATRIX.md` for having competing primary actions instead of one clear Primary Action. Do not use that part of this screen as a reference.
