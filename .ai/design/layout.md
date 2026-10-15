# Layout, responsiveness and accessibility

## Layout classes (the three the sheet is built for)

| Class | Rule in `fighter-sheet.css` | What changes |
|---|---|---|
| Desktop | default; the sheet is up to 1000 px wide, `max-height: calc(100dvh - 32px)` | two columns (fighter / content), header and footer fixed, the content column scrolls |
| Tablet | `(max-width: 1000px) and (min-width: 641px)` | left column 220 px instead of 250 |
| Phone portrait | `(max-width: 640px)` | the sheet is full screen (`100vw x 100dvh`, no border, no rivets), one column, header wraps and the link labels become icons, the primary action row is sticky at the bottom with full-width buttons, the character roster becomes 5 columns with a 170 px scroller and a 190 px stage |
| Phone landscape | `(max-height: 500px) and (orientation: landscape)` | full screen, one compact header row, columns scroll down only, mini stage 120 px |

New UI must be checked on all four. Do not hard-code a pixel position, a device flag or a size that only works at the width you tested; derive layout from the container (the HUD uses container queries: `container: hud / size`). Before calling a sizing bug, measure the rendered size (font size times every ancestor's zoom or transform), not one computed style.

Safe areas: the battlefield page is `viewport-fit=cover`; a full-screen sheet pads by `env(safe-area-inset-*)`. New fixed or sticky elements do the same.

## Scroll

The sheet never outgrows the viewport. Scrolling happens inside one named container per column (`.main`, `.showcase`, `.roster-scroll`) with the sheet's thin gold scrollbar. No page-level scrolling behind the overlay, no nested scrollers that each show a sideways bar.

## z-index scale (do not invent new numbers)

| Value | Used by |
|---|---|
| 0-6 | stacking inside a panel (sprites, effects, avatar, tip inside a term) |
| 20 | menus (`.mpick-menu`), the old `.toast` base, the projectile |
| 40 | `.tipbox` (before the sheet override) |
| 60 | the sheet `.overlay`, the boss stone flight |
| 70 | toast and tipbox over the sheet |

Anything above the sheet (a confirm, a toast) uses 70; a new layer needs a new row here.

## Touch and pointer

- Interactive targets are at least 44 x 44 px on a phone (padding counts); the sheet's 28 px `.close` and 60 px tiles are near that limit: do not go smaller, and give small icon buttons extra hit area.
- Every control that can be pressed shows the hand cursor. Tailwind 4's preflight leaves buttons on the arrow, so the sheet's own classes set `cursor: pointer` themselves (`.btn`, `.slot`, `.chip`, `.move`, `.link`...); a button outside them must set it too (or rely on a global `button:not(:disabled)` rule in `@layer base` if the app adds one). `cursor: help` only on `.term`.
- Hover is never the only way to see something: what a hover reveals (tooltip, face animation) must also appear on focus and on selection, because a phone has no hover.

## Keyboard and focus

- Everything operable by mouse is operable by keyboard: tiles and chips are buttons with `aria-selected` / `aria-pressed`; tabs rove with arrows; Escape closes the sheet or the innermost panel and returns focus to the opener (`sheet/index.js`).
- Opening a panel moves focus into it (the first control, or the safe choice in a confirm); closing returns it to what opened it. A selected tile must be reachable again after the panel closes.
- The focus ring is 2 px gold with a 2 px offset; the selected state is a thicker gold border plus a ring. Both are gold, so they must differ in shape (outline vs border) and a selected item also changes its label.
- The roster is a `role="group"` of `aria-pressed` buttons (or a listbox with arrow keys); do not mix patterns on one screen.

## Screen readers and contrast

- Controls have an accessible name (`aria-label` for icon-only ones: "Create a custom fighter"); decorative sprites and glows are `aria-hidden`.
- Changes that appear after an action (a toast, an error, a result) are in an `aria-live` region; an error is tied to its field with `aria-describedby`.
- Text on the dark panels is `--parchment` or `#fff`; `--dim` is for secondary text only and must not carry the only copy of something that matters. Status is never colour alone (text or icon too).
- Do not rely on a colour that only the accent communicates (the equipped fighter's `--acc` is decoration).

## Verify

The team verifies on staging, not locally: build, deploy, then check each state at desktop width and on a phone-size window in portrait and landscape, with the keyboard only and with reduced motion on, and keep a screenshot per state in the PR.
