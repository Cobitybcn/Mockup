# Artwork Mockups Visual Constitution

## Purpose

This directory is the visual authority for Artwork Mockups. It documents the existing identity of the product so that future interface work remains a natural continuation of the same digital artist studio.

This is not a generic design system. It is a constitution for the visual and interaction patterns already present in the application. It must be used to preserve continuity, not to justify redesign.

## Authority Hierarchy

Read and apply the documentation in this order:

```text
Studio Protocol
      ↓
Visual Language
      ↓
Components
      ↓
Interaction Patterns
      ↓
Forbidden Patterns
      ↓
UI Preferences
      ↓
References
      ↓
Master Patterns
```

### Studio Protocol

`00_STUDIO_PROTOCOL.md` defines what Artwork Mockups is, what it is not, and why the artwork must remain the protagonist.

### Visual Language

`01_VISUAL_LANGUAGE.md` describes the conceptual visual vocabulary: square identity, image scale, typography, color, space, borders, composition, and density.

### Components

`02_COMPONENTS.md` documents reusable components already present in the product, including their visual and interaction rules.

### Interaction Patterns

`03_INTERACTION_PATTERNS.md` explains how people work in the application through drag and drop, boards, visual assignment, image actions, and horizontal browsing.

### Forbidden Patterns

`04_FORBIDDEN_PATTERNS.md` defines the changes that would break the identity of the product.

### UI Preferences

`UI_PREFERENCES.md` gives inherited, more specific guidance for forms, panels, and typography. It applies when a more specific approved reference does not already cover the case.

### References

`references/` contains approved visual evidence from implemented screens. Each reference defines when a visual arrangement should be reused and which relationships must remain unchanged.

### Master Patterns

`MASTER_PATTERNS/` isolates the recurring visual patterns that form the product's DNA. These records are pattern-focused and are not descriptions of individual screens.

## Resolving Contradictions

When written guidance and an approved visual reference appear to conflict, the visual reference has priority because it is direct evidence of the implemented and approved language.

Use the closest relevant reference. Preserve its hierarchy, image treatment, component relationships, spacing character, action placement, and visual density. Written guidance should then be clarified so the contradiction does not persist.

Master Patterns summarize recurring evidence but do not override a more specific approved reference.

## Change Protocol

Before changing an interface:

1. Read the Studio Protocol and Visual Language.
2. Find the closest approved reference.
3. Identify the existing component and master pattern that solve the same problem.
4. Reuse the established pattern.
5. Check the proposal against the Forbidden Patterns.

New documentation must describe implemented and approved behavior. It must not introduce speculative components, styles, or visual languages.
