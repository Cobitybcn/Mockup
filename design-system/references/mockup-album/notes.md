# Approved Visual Reference

## What pattern does this screen define?

Mockup Album (`platform/mockups.php`) defines the Workspace Header + horizontal Carousel pattern for a large private archive. The header is a single soft pastel band with an editorial title, one short instruction line, and two subordinate actions (Create Art, Import Mockups) kept visually lighter than the title. Below it, a labeled horizontal rail ("Favorite Mockups") shows large, evenly sized Thumbnail Cards with a star Glass Action and a compact caption (title + id), scrollable sideways instead of wrapping into a dense grid.

## When should it be reused?

Reuse this pattern for any large private library or archive screen that needs a highlighted horizontal subset (favorites, recents, selected) above the full paginated archive — e.g. Video library, Scene Studio references. It is the reference for `MASTER_PATTERNS/04_workspace_layout`, `MASTER_PATTERNS/07_headers`, and `MASTER_PATTERNS/08_carousels`.

## What must never change?

- The header band stays a single flat pastel surface with one title and one instruction line; secondary actions (Create Art, Import Mockups) remain visually subordinate, never styled as the primary commitment.
- The favorites rail scrolls horizontally at full Thumbnail Card scale; it must not shrink cards to show more at once or convert into a table/list.
- Search stays a single plain input plus one button, not an expanded filter panel.
- Captions stay compact (title + id), never expanded metadata blocks under each card.
