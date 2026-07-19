# Artwork Mockups Visual Language

## Purpose

This document describes the visual language already present in Artwork Mockups. It is conceptual guidance, not a CSS specification. Future interface work must preserve these relationships even when content or workflow changes.

## Core Character

Artwork Mockups combines the atmosphere of an editorial studio with the clarity of a visual production workspace. The interface is spacious, image-led, and quiet. It organizes creative material without adopting the visual density of administrative software.

## Decision Geometry

Square and near-square blocks communicate decisions. They mark important creation actions, principal navigation, changes of context, and category selection. Their geometry gives these moments weight and makes them recognizable as choices rather than content.

Rectangular thumbnail cards communicate visual content. They represent artworks, mockups, images, videos, and generated material.

This distinction is fundamental. Square decision geometry and rectangular content geometry serve different meanings and must never be interchanged.

## Image Scale and Presence

Thumbnails are large enough to be read as visual material rather than file icons. Image cards give the image most of their available surface, and workspace compositions allow several images to be compared without reducing them to a dense list.

Images are the principal carriers of meaning. Labels, metadata, and controls support them but do not displace them.

## Space and Surfaces

The system uses large white space, broad panels, and vertical workspaces to separate stages of work. Empty space is functional: it creates focus, makes drag-and-drop destinations legible, and prevents controls from competing with images.

Surfaces are light and calm. Fine borders define panels, cards, and assignment areas. Borders create structure without adding visual weight. Strong shadows are not part of the primary language.

## Typography

Editorial serif typography is used for prominent titles and moments of identity. It gives the studio a cultural and artistic tone.

Discrete sans-serif typography is used for information, controls, labels, counts, and operational guidance. It should remain highly readable, restrained, and subordinate to both the title and the images.

Text hierarchy is achieved through role and placement rather than through excessive variation. Copy remains concise.

## Color

The base palette is warm, light, and neutral. Off-whites, soft surface tones, muted ink, and fine warm-gray borders provide the background for the artwork.

Muted pastel colors identify sections, workflows, and board families. Existing uses include:

- dusty rose for prominent creation actions and related studio areas;
- sage and muted green for website, completion, or affirmative contexts;
- warm ochre, copper, and soft yellow for selected, supporting, or secondary actions;
- muted lilac and blue for distinct board or channel identities;
- restrained channel colors where a publication destination must remain recognizable.

Color is organizational, not decorative. It should establish continuity within a section and distinguish adjacent work areas while remaining quiet enough for the artwork to lead.

Color identifies areas. It never decorates, and it never dominates.

## Composition

The overall workflow is vertical. A workspace moves from orientation and a primary decision into browsing, active work, assignments, and results.

Within that vertical flow, galleries are horizontal. Catalogs, visual pickers, series, favorites, and generated material move across rails or carousels. Wide working areas allow source images, references, camera views, combinations, or destinations to be seen together.

Horizontal browsing and vertical workflow are complementary. The application must not become a horizontal dashboard of equal-weight modules.

## Information Density

The interface uses little text. Titles orient the user, short instructions clarify the immediate task, and compact labels identify content. Secondary information should appear only when it supports a decision.

Long explanations, repeated metadata, and permanent instructional blocks weaken the image-led hierarchy.

## Iconography and Actions

Iconography is small, precise, and operational. Image actions live on the image they affect. Frosted-glass controls use a restrained translucent treatment so the image remains visible beneath the control.

Actions should be minimally invasive:

- prefer a clear icon to a long button label for familiar image operations;
- reveal secondary actions in context;
- keep controls visually lighter than primary creative material;
- reserve large pastel action blocks for the principal commitment on a screen.

## Visual Hierarchy

The intended hierarchy is:

1. Artwork and production images.
2. The active workspace or board.
3. The section title and immediate instruction.
4. The principal action.
5. Supporting labels, counts, statuses, and utilities.

Any screen that reverses this order should be reconsidered.

## Visual Rhythm

The visual rhythm is calm. Large white space, thin borders, soft pastel identity, restrained controls, minimal text, and substantial imagery create a low-pressure sequence through the workspace.

Visual density stays soft. The interface should reveal enough structure to guide production without filling every available area.

## Reference Standard

Approved references in `design-system/references/` demonstrate how these principles appear in real screens. Conceptual guidance and visual references must be read together. When a close reference exists, its established hierarchy, spacing, image treatment, panel treatment, and action placement take precedence over invention.
