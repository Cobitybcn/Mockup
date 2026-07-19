# Artwork Mockups Components

## Purpose

This catalog documents reusable components already present in Artwork Mockups. It defines their purpose, visual rules, interaction rules, and limits without prescribing implementation details. Variants must remain supported by an implemented screen or approved reference.

## Decision Block

### Purpose

Represents a consequential choice in the studio. Decision Blocks support important decisions, principal navigation, creation, changes of context, and category selection. Existing examples include Create Art, New Camera, Explore Scenes, New Series, Add Sequence, and Series.

Square geometry communicates decisions. It is not a container for visual content.

### Visual Rules

- Use square or strongly square-led geometry.
- Give the decision clear presence and generous surrounding space.
- Use the established pastel identity of its section.
- Keep the label short and the surface visually simple.
- Decision Block labels must remain fully contained, optically centered, and limited to a maximum of two balanced lines.

### Interaction Rules

- Use the entire block as the decision surface.
- Make the destination or action clear before activation.
- Use one block for one primary decision.
- Keep state changes visible without turning the block into a settings panel.

### Avoid

- Using Decision Blocks for artworks, mockups, videos, or generated images.
- Replacing them with traditional SaaS buttons or generic cards.
- Filling them with metadata, explanations, or secondary utilities.
- Creating several competing primary decisions in the same area.

## Thumbnail Card

### Purpose

Represents visual content: artworks, mockups, videos, images, and generated content. Rectangular geometry communicates visual material rather than decisions.

### Visual Rules

- Use a rectangular image-led silhouette.
- Give the image most of the available surface.
- Preserve the crop or framing meaningful to its workflow.
- Keep labels, counters, and states compact.

### Interaction Rules

- Use the image as the primary surface for selecting, moving, opening, or acting on content.
- Place content-specific actions on the image.
- Provide clear focus, selection, and drag states without obscuring the image.
- Preserve the distinction between content interaction and a Decision Block action.

### Avoid

- Using a Thumbnail Card for principal navigation or creation decisions.
- Reducing the image to a small file icon.
- Surrounding it with excessive metadata.
- Reserving a permanent action bar below the image.
- Replacing rectangular content geometry with a square Decision Block.

## Workspace Panel

### Purpose

Creates a broad, calm work area for one stage of visual production.

### Visual Rules

- Keep the panel large, spacious, and visually quiet.
- Use thin borders and light surfaces to establish structure.
- Give active visual material more space than supporting controls.
- Preserve generous white space as functional working room.

### Interaction Rules

- Keep the primary task visible within the panel.
- Fold secondary settings when they are not immediately required.
- Present feedback close to the material or action that produced it.
- Support direct visual assignment where required.

### Avoid

- Dense dashboard modules.
- Multiple nested panels with equal visual emphasis.
- Strong elevation or heavy interface chrome.
- Long explanatory prose as primary panel content.

## Glass Action Button

### Purpose

Provides a compact action on an image without obscuring or overpowering it.

### Visual Rules

- Use a small circular form with restrained frosted glass.
- Use a clear icon without visible button text.
- Keep visual weight minimal.
- Place favorites at the top-left when present.
- Place secondary actions at the top-right.
- Preserve contrast over varied image content.

### Interaction Rules

- Keep the action on the image it affects.
- Provide an accessible name.
- Use a concise tooltip when the icon needs clarification.
- Keep the result associated with the same image.

### Avoid

- Toolbars below thumbnails.
- Exaggerated glassmorphism, glow, or blur.
- Text-heavy overlays.
- Typographic glyphs in place of aligned vector icons.
- Duplicating the same action elsewhere.

## Carousel

### Purpose

Presents a visual gallery horizontally while preserving useful Thumbnail Card scale.

### Visual Rules

- Maintain a horizontal reading direction.
- Keep images large enough for recognition and comparison.
- Use restrained navigation controls.
- Keep the gallery visually continuous.

### Interaction Rules

- Support direct horizontal browsing.
- Preserve the user's position.
- Indicate position or count only when useful.
- Keep movement user-directed.

### Avoid

- Shrinking images merely to show the entire collection at once.
- Large navigation bars or verbose pagination.
- Automatic movement that interrupts inspection.
- Mixing unrelated visual content without a grouping rule.

## Upload Area

### Purpose

Introduces real visual material into a studio workspace. In Visual DNA, the existing reference library itself is the entry surface for the user's own spaces, materials, light, atmosphere, furniture, textures, and related visual evidence.

### Visual Rules

- Reuse the library or workspace as the drop surface instead of adding a permanent form.
- Keep file selection as a small secondary icon action for accessibility.
- Derive initial title and category metadata without interrupting direct manipulation.
- Show the uploaded image immediately as a reusable Thumbnail Card in the active library.

### Interaction Rules

- Validate the real image format before adding it to the workspace.
- Accept direct file selection, desktop drop, browser-image drop, and clipboard paste through the same library surface.
- When a board exposes an existing Drop Zone, dropping new visual material there must both preserve it in the library and assign it to that zone immediately.
- When another browser supplies a URL instead of a file, import it only after validating it as public image material.
- Preserve the uploaded asset so it can be reused in ordered visual sets.
- Confirm success in the current workspace without interrupting the visual flow.
- Make the difference between real user material and illustrative examples explicit.

### Avoid

- Adding a permanent metadata form between the visual material and the reference library.
- Requiring a second confirmation after an image is dropped or pasted.

### Avoid

- Presenting example imagery as if it were usable source material.
- Hiding required metadata in a separate administrative screen.
- Replacing the image-led upload surface with a dense file table.
- Starting generation automatically after upload.

## Drop Zone

### Purpose

Signals a valid destination for visual material being moved within a workspace.

### Visual Rules

- Make the destination understandable before dragging begins.
- Use a quiet but unmistakable active state.
- Preserve the surrounding layout during drag feedback.
- Let assigned imagery confirm a successful drop.

### Interaction Rules

- Accept only valid draggable material.
- Indicate when an item enters or leaves the destination.
- Confirm the resulting assignment in place.
- Provide an accessible alternative to drag and drop.

### Avoid

- Using instructional text as the only destination indicator.
- Saturated or alarming active colors.
- Moving the target during the gesture.
- Using drag and drop when the outcome cannot be understood or recovered.

## Workspace Header

### Purpose

Introduces a worktable, establishes editorial hierarchy, and explains the immediate production context.

### Visual Rules

- Use the established editorial title character.
- Keep the description short and practical.
- Preserve generous white space around the heading group.
- Keep counters and utilities visually subordinate.

### Interaction Rules

- Place only workspace-level actions beside the header.
- Keep image-specific actions on images.
- Maintain a stable relationship with the workspace it introduces.
- Let the next primary decision follow naturally in the vertical flow.

### Avoid

- Dashboard headers dominated by metrics, filters, or controls.
- Long introductory copy.
- Multiple headings with equal weight in one area.
- Generic administrative typography.

## Primary Action

### Purpose

Represents the principal production commitment of the active workspace. It preferably uses a Decision Block when the action is important enough to define the next stage.

### Visual Rules

- Give the action substantial presence and generous surrounding space.
- Use the established pastel identity of its section.
- Use a short, action-oriented label.
- Prefer square Decision Block geometry over a traditional SaaS button for major commitments.

### Interaction Rules

- Provide one clear primary commitment per workspace stage.
- Show loading, success, and failure near the action or result.
- Prevent accidental duplicate execution while processing.
- Keep secondary actions subordinate.

### Avoid

- Saturated corporate button colors.
- Several competing primary actions.
- Generic framework button styling.
- Reducing the principal commitment to a toolbar utility.

## Toolbar

### Purpose

Groups a small set of contextual utilities for the active workspace or collection.

### Visual Rules

- Keep the set short, compact, and context-specific.
- Use unobtrusive controls and established icons.
- Position it close to the workspace it controls.
- Keep it lighter than imagery and the primary action.

### Interaction Rules

- Limit actions to the active context.
- Keep image-specific actions on images.
- Preserve predictable ordering and accessible labels.
- Reveal secondary controls only when needed.

### Avoid

- Large application-wide command bars.
- Duplicating actions already present on images.
- Filling the toolbar with rare settings.
- Giving utilities more prominence than the workspace.

## Badge

### Purpose

Communicates a concise operational state without interrupting the visual workflow.

### Visual Rules

- Use short, unambiguous language.
- Use a muted semantic color consistent with the section.
- Keep it compact and close to the item it describes.
- Keep it subordinate to imagery.

### Interaction Rules

- Update the badge when its state changes.
- Ensure meaning does not depend on color alone.
- Make it interactive only when an established action exists.

### Avoid

- Full sentences.
- Decorative states with no operational value.
- Saturated colors for routine conditions.
- Several badges communicating the same state.

## Counter

### Purpose

Shows the quantity of items in a collection, assignment, result set, or carousel position.

### Visual Rules

- Keep it compact and close to the relevant heading or collection.
- Use the section's muted color family.
- Give the number less prominence than visual content.
- Pair it with a short label when its meaning is not self-evident.

### Interaction Rules

- Update immediately when direct manipulation changes the collection.
- Preserve consistent counting language within a workflow.
- Use it as navigation only when that behavior is established and clear.

### Avoid

- Turning counters into KPI metrics.
- Displaying counts that do not support a decision.
- Repeating the same count in several controls.
- Making a count the dominant element of a workspace.
