# Uploadiny Design System — "The studio table"

The backoffice is a light studio: a pale grey table, white panels that float
above it, and the screenshot as the hero in the middle. Interface chrome stays
quiet so the feedback images carry the colour.

## Taste brief (2026-10-06)

- Wanted: airy light ground; floating white rounded panels with soft two-layer
  shadows; the image centred as hero; tool settings in small floating cards;
  pill-shaped segmented controls; outlined icons; one confident accent.
- Banned: the former heavy navy admin sidebar, boxy bordered panels, gradient
  blobs, more than one accent hue in chrome.
- Boldness 3: calm daily tool with one signature moment.
- References (Bruno's screenshots): PixorAI studio layout, Zensync UI kit,
  Eduvo dashboard. Principles adapted, nothing copied.

## Signature moment

Every upload chunk is a small deck of prints. On hover or keyboard focus the
deck fans open (transform only), showing how many files the chunk holds before
it is opened. Reduced motion shows the deck already fanned, without movement.

## Tokens

- Ground `#eef0f4`, panel `#ffffff`, raised hover `#f7f8fb`.
- Ink `#0e1525`, secondary `#4a5568`, muted `#6b7385` (≥ 4.5:1 on panel).
- Accent indigo `#5146e5` (hover `#4338ca`, tint `#eeedfd`). Danger `#c2263a`.
- Radius: panel 20 px, control 12 px, pill 999 px. Child radius ≤ parent.
- Shadow: `0 1px 2px rgb(14 21 37 / .05), 0 10px 30px -12px rgb(14 21 37 / .14)`.
- Type: Plus Jakarta Sans for headings and brand; Inter for interface text.
  Scale 12 / 13 / 14 / 16 / 20 / 28 / 36. Tight tracking on headings.
- Motion: 150–200 ms ease-out on transform, opacity, colour and shadow only.

## Rules

- Annotation ink colours are content, not chrome; keep them untouched.
- Every interactive element has hover, focus-visible (2 px accent ring),
  pressed (scale .97) and disabled states. Targets ≥ 40 px on desktop, 44 px on
  touch.
- The content area is always light. Do not introduce a dark theme without a
  product decision.
- iPhone app and Share Extension use the same ground, panel, ink and accent.
