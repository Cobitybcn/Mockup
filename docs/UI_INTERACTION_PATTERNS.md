# UI Interaction Patterns

## Edge-anchored image controls

This pattern was established in `create_scenes.php` and should be considered for future mockup editing tools.

### Visual language

- Controls sit half over the image and half over the surrounding card.
- Use small, circular, translucent controls so the image remains dominant.
- A measurement circle uses three levels: a minimal axis label (`W` or `H`) at the top, an integer value in the center, and its automatic unit at the bottom.
- The orientation selector sits centered on the top image edge using the same half-image/half-card placement. It opens `Vertical`, `Horizontal`, and `Square` options.
- Avoid visible sliders, large steppers, plus/minus buttons, and opaque panels over the image.

### Touch behavior

- Press and drag upward on a value to increase it.
- Drag downward to decrease it.
- Values advance in whole-number steps and provide subtle haptic feedback when supported.
- Preserve keyboard access with arrow keys and expose slider semantics through ARIA.

### Measurement units

- Do not show a separate unit selector.
- Select the unit from the device locale: imperial regions use inches; other regions use centimeters.
- Convert the actual value with `1 in = 2.54 cm`; never change only the displayed suffix.
- Keep the resolved unit visible in minimal form inside the measurement circle so the number is not ambiguous.

### Reuse in mockup editing

Good candidates include artwork scale, crop dimensions, placement offsets, margins, and other direct-manipulation values. Keep the number of simultaneous overlays low, anchor each control to the edge it affects, and preserve the mockup as the primary visual surface.
