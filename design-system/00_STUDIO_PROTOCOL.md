# Artwork Mockups Studio Protocol

## Purpose

This protocol defines the philosophy behind the visual system of Artwork Mockups. It is the starting point for evaluating every interface decision and the standard against which future visual work must be reviewed.

The system must evolve through continuity. New capabilities should extend the established visual language, reuse its patterns, and preserve the character of the existing workspace.

## What Artwork Mockups Is Not

Artwork Mockups is not:

- a SaaS dashboard;
- Material Design;
- a Bootstrap Admin interface;
- an enterprise resource planning system;
- a content management system;
- an administrative control panel;
- a dense reporting interface;
- a collection of generic cards and utility widgets.

Patterns associated with those products must not become the default vocabulary of the application.

## What Artwork Mockups Is

Artwork Mockups is:

- a digital artist studio;
- a visual production environment;
- an image-first workspace;
- a creative organization tool;
- a worktable built around artworks, mockups, cameras, videos, sequences, and publications.

The interface supports an image-led creative process. The application should feel like a physical studio where artists organize artworks, mockups, cameras, videos, sequences, and publications. Material is seen, moved, compared, grouped, and prepared for its next stage.

## The Artwork Is the Protagonist

The artwork is always the protagonist.

The interface supports the artwork.

The interface never competes with it.

It frames the work, clarifies its context, and makes production actions available while remaining visually subordinate.

This principle has direct consequences:

- Images receive the greatest visual presence.
- Supporting information remains concise and secondary.
- Controls stay restrained and close to the material they affect.
- Surfaces provide space around the work rather than filling every area with interface elements.
- Color identifies context and workflow without overpowering the artwork.

When a choice must be made between displaying more interface and giving the artwork more room, the artwork takes priority.

## Continuity Before Novelty

Existing screens and approved references are the source of truth. A new feature does not justify a new visual direction.

Before proposing or changing an interface:

1. Find the closest approved reference in `design-system/references/`.
2. Review its image and notes.
3. Identify the existing components and interaction patterns that already solve the problem.
4. Extend those patterns with the smallest necessary change.

Reuse is a design requirement. A component should be introduced only when no established component can express the same purpose.

## Studio Qualities

Every screen should preserve the following qualities:

- **Visual calm:** generous space, low noise, and clear grouping.
- **Image priority:** artwork and production images lead the composition.
- **Editorial character:** titles and hierarchy feel considered rather than corporate.
- **Direct manipulation:** material is moved and assigned wherever possible.
- **Quiet utility:** actions are available without becoming the dominant visual layer.
- **Production clarity:** boards, panels, sequences, and publications communicate where material belongs and what happens next.

## Authority and Growth

The numbered documents in this directory form the official visual authority of Artwork Mockups. They describe the existing system rather than a future redesign.

The documentation may grow as approved patterns appear. Additions must be supported by implemented screens or approved references. Speculative styles and components do not belong in this system.
