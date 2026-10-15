# Tokens

## Game world: the fighter sheet (`.fs`, `resources/css/fighter-sheet.css`)

Declared on `.fs` (the first block, plus `--line`, `--line-2`, `--sk` in the "app additions" block near the end); use the variable, never the hex. Per-element runtime variables (`--acc`, `--d`, `--x`, `--c1`..`--c4`, `--side`...) are set inline by the sheet's JS and are not tokens.

| Token | Value | Use |
|---|---|---|
| `--night` | `#0b1629` | HUD panel fill, empty-state fill |
| `--night-deep` | `#060d1a` | page-deep fill, text on a gold button, toast fill |
| `--rivet` | `#fbbf24` | the one accent: panel border, titles, selected state, primary button, focus ring |
| `--parchment` | `#e8dfc8` | body text (warm, never pure white; `#fff` only for the one headline per panel) |
| `--dim` | `#8b93a7` | secondary text, labels, inactive tabs |
| `--ember` | `#f97316` | output tokens = damage |
| `--frost` | `#7dd3fc` | cache tokens |
| `--blood` | `#ef4444` | critical / error |
| `--life` | `#4ade80` | OK, live, positive delta |
| `--mana` | `#60a5fa` | info, pending |
| `--accent` / `--acc` | `#88ee44` default | the equipped fighter's own colour: stage glow, never used for UI chrome |
| `--line` / `--line-2` | `rgba(120,140,190,0.22)` / `0.32` | hairlines and borders added by the "app additions" block (same values as `--bf-line`); declared late, at the end of the file, on `.fs` |
| `--sk` | Silkscreen, ui-monospace | micro labels (9-12 px, uppercase); same as `--bf-sk`; also declared late |
| `--display` | Chakra Petch, Instrument Sans, system-ui | headings, buttons, tabs, tags, numbers in controls |
| `--body` | Instrument Sans, system-ui | running text, notes, tooltips |

Other literals the sheet uses repeatedly (treat as tokens, do not invent neighbours): tile fill `#0e1a31`, tile border `#243150`, tile hover/selected fill `#12213d` / `#15233f`, dashed "add" border `#3a4a70`, tooltip fill `#111c33`, hairline `rgba(251,191,36,0.18)` between columns, muted key legend `#5b6478`, amber warning `#f59e0b` / `#fcd34d`, soft red text `#fca5a5`, button hard shadow `#b45309`.

## Game world: the HUD (`.bf-*`, `resources/css/battlefield-hud.css`, on `:root`)

| Token | Value | Use |
|---|---|---|
| `--bf-panel` | `rgba(9,15,30,0.86)` | panel fill (slightly opaque instead of blur, because the canvas is live behind it) |
| `--bf-line` | `rgba(120,140,190,0.22)` | panel border, dashed dividers |
| `--bf-text` | `#e8eefc` | text |
| `--bf-dim` | `#8b97b3` | labels |
| `--bf-gold` | `#fbbf24` | accent |
| `--bf-px` | Pixelify Sans | names, labels, titles |
| `--bf-sk` | Silkscreen | small caps labels (10-12 px) |
| `--bf-num` / `--bf-num-px` | Press Start 2P / 8px | damage numbers only; the size is a multiple of 8 so glyph pixels land on whole screen pixels; a zoomed tier divides it by its zoom |

## App world (Tailwind pages)

Light surfaces, `gray-200` borders, `gray-700` text, `orange-400/600` hover and accent (see `partials/account-nav.blade.php`). Orange here is the same family as the game's `--ember`, not as `--rivet`; do not put gold (`#fbbf24`) on a light page or orange on a dark panel.

## Fonts: where each one is loaded

| Font | Loaded by | Available on |
|---|---|---|
| Instrument Sans 400/500/600 | `bunny()` in `vite.config.js` and a `<link>` in `battlefield.blade.php` | every page via Vite; battlefield page also by link |
| Press Start 2P 400 | `bunny()` in `vite.config.js` | every page via Vite |
| Chakra Petch 500/600/700 | `<link>` (Google Fonts) in `battlefield.blade.php` | the battlefield page only |
| Chakra Petch 700 latin | self-hosted `/fonts/chakra-petch-700-latin.woff2`, `@font-face` in `app.css` | every page that loads `app.css` (700 only) |
| Pixelify Sans 500/700, Silkscreen | `<link>` in `battlefield.blade.php` | the battlefield page only |

Consequence: a control styled with `--display` or `--bf-px` renders in the real font only where it is loaded. A game-world control placed on a page that is not the battlefield (for example the editor on `/character`) falls back to `Instrument Sans` / `system-ui` / `Arial` unless that page loads the font. New game-world UI that can appear outside the battlefield must say which page loads its fonts.

## Shape, borders, depth

- Corners are square. `border-radius: 50%` only for circles and dots (avatars, status dots); `999px` only for scrollbar thumbs. No 4-12 px rounded cards or buttons in the game world.
- Panels: 1 px hairline border (`--bf-line` or `rgba(251,191,36,0.18)`); the sheet itself is the one `2px solid var(--rivet)` frame with four 6 px rivets and a faint outer ring.
- Depth is a hard offset shadow (primary button `3px 3px 0 #b45309`) or a glow in the accent colour, never a soft blurred drop shadow, except under the sheet.
- Dashed borders mean "empty / placeholder / add": the empty state (`1px dashed rgba(251,191,36,0.45)`), the add tile (`1px dashed #3a4a70`), HUD dividers.
- Sprites and pixel art: `image-rendering: pixelated`, integer scales only, never blurred, frames are 100 x 100 and are never upscaled in the files (scale in CSS or the camera).

## Spacing and type scale (what the sheet really uses)

- Gaps: 6, 8, 10, 12, 14, 18 px; panel padding 4/22 px horizontal, 14-22 px vertical. Pick from these.
- Type: headings 24-26 px `--display` 700; section titles 15 px `--display` 600; controls and tabs 14 px `--display`; secondary text 12-13 px; micro labels 9-11 px `--display` or `--sk`. Body text 14 px / 1.45.

## Known drift (do not copy; fix in a dedicated change)

- The two token sets duplicate meaning with different values: text `#e8dfc8` (sheet) vs `#e8eefc` (HUD), dim `#8b93a7` vs `#8b97b3`. Same accent value under two names (`--rivet`, `--bf-gold`).
- Several literal hexes above are used instead of tokens.
