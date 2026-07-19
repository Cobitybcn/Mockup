# Visual References

## Purpose

This directory contains approved visual evidence for the Artwork Mockups design system. References document how the established language appears in real screens; they are not inspiration boards and must not be used to propose unrelated redesigns.

## Organization

Each approved reference should have its own clearly named directory based on the screen or workflow it represents.

```text
references/
  reference-name/
    reference.png
    NOTES.md
```

Do not remove or overwrite existing reference directories when adding a new one.

## Required Files

### `reference.png`

The approved screen image. It should show enough of the interface to make hierarchy, composition, image treatment, panels, actions, and spacing understandable.

### `NOTES.md`

A concise description of the visual pattern represented by the screen.

`NOTES.md` must answer only these three questions:

1. **What pattern does this screen define?**
2. **When should it be reused?**
3. **What must never change?**

It should not contain implementation rules, CSS values, redesign proposals, feature requirements, or a general product specification.

## Reference Policy

- Add a reference only when the screen is implemented and approved as representative of the product.
- Use the closest existing reference before adding another.
- Keep notes specific to the represented visual pattern.
- Do not use references from unrelated products as authority for Artwork Mockups.
- When a reference becomes outdated, preserve its history and document the approved replacement deliberately.
