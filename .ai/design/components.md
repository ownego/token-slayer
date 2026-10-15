# Components (game world, `.fs` sheet)

Class names are the real ones in `resources/css/fighter-sheet.css`. Reuse them; do not make a parallel `my-feature-btn`. A component that is missing is added here and to the CSS together.

State vocabulary used below: default, hover, `:focus-visible`, active (pressed), selected (`aria-selected` / `aria-pressed`), disabled. Every interactive component must define all that apply; the global focus ring is `.fs :focus-visible { outline: 2px solid var(--rivet); outline-offset: 2px }`, and components may replace it with a border/colour change only if the change is at least as visible.

## Sheet frame

`.overlay` (fixed, dimmed `rgba(2,6,23,0.45)`) > `.sheet` (the riveted panel: `2px solid var(--rivet)`, four `.rivet` corners, max 1000 px wide, never taller than the viewport). Header `.sheet-head` (title `h1` 26 px `--display` gold, meta in `--dim`, `.head-links`, `.close`), then tabs, then a body whose column scrolls while header and footer stay put. A new screen inside the sheet is a tab panel or a panel that replaces the stage in place (`.tab-panel`, `.is-active`); it is not a second modal on top.

## Buttons

| Class | Look | Use |
|---|---|---|
| `.btn` | `14px --display 700`, 9/18 px padding, `2px solid var(--rivet)`, transparent, gold text | secondary action |
| `.btn.primary` | gold fill, dark text, hard shadow `3px 3px 0 #b45309`, presses in (`translate(2px,2px)`, shadow 1 px) | the one main action of a view (Equip, Use) |
| `.btn[disabled]` | opacity 0.55, default cursor, no shadow | unavailable: always show why next to it (`.equip-note`) |
| `.btn.flash` | 0.6 s gold halo | confirmation that the action happened |
| `.close` | 28 px square, `--dim` border, gold on hover/focus | close the sheet |
| `.link` | 13 px gold text, underline, 3 px offset | an inline text action or a link |
| `.fold` | 12 px `--dim`, rotating triangle | expand/collapse |

Rules: one `.primary` per view. Squared, no radius. Hover is `filter: brightness(1.1)`. Every pressable control sets `cursor: pointer` (Tailwind 4 preflight resets buttons to the arrow; see `layout.md`). Destructive buttons are not a new colour: they are `.btn` with the confirm pattern in `patterns.md` and, once confirmed, `--blood` text on the confirm step only.

## Tabs

`.sheet-tabs` > `.sheet-tab` (14 px `--display`, `--dim`; selected: gold text and a 2 px gold underline that scales in 0.22 s). `role="tab"`, `aria-selected`, arrow keys rove, panel `.tab-panel.is-active` fades in 0.22 s.

## Tiles and pickers

- `.roster-grid` 4 columns (5 on a phone), gap 8 px, inside `.roster-scroll` (own scroller).
- `.slot`: a fighter tile (aspect 0.86, fill `#0e1a31`, border `#243150`). Hover: gold border at 55%; selected: `2px solid var(--rivet)` + 3 px soft ring; active: scale 0.96. Name `.nm` is a 10 px label at the bottom; `.tag` is a gold 9 px corner label (`YOU`, and the marker for a kind of fighter). The face animates only on hover or when selected.
- `.slot-add`: dashed "add" tile in the grid (`1px dashed #3a4a70`, `--dim`, turns gold on hover/focus). It is an action, not a fighter; it is not a `.slot`, so selection, filters, keys and tooltips ignore it. Its label says what it does ("Create", "Manage"); it is the visible entry point to an editor, so it must be findable: do not hide it among slots with a 10 px label only.
- `.chips` / `.chip`: single-select filter row (11 px `--display`, `1px solid #2b3858`, selected `aria-pressed="true"` = gold fill, dark text). A filter chip filters; it never hides an item that has no matching value: items with no style get their own chip, they do not appear under every chip.
- `.move` / `.hotbar`: a numbered action button with a looping thumbnail (`aria-pressed`), key legend `.key`.
- `.menu`, `.mpick-menu`: dropdown, hover/focus row `rgba(251,191,36,0.1)`, `z-index: 20`.

## Show stage (preview of a fighter)

`.show-stage` (270 px tall, accent glow floor line, sprite at `scale: 4`, the boss on the right), `.show-info` (name `h2` 24 px, type, `.show-state` badge: `Equipped` green, `Previewing` gold), `.hotbar`, `.equip-row` (`.equip-note` + `.btn.primary`). Any fighter (roster or custom) is previewed here, with the same stage, scale, moves and equip row; do not build a second preview widget (see `motion.md` for rates and `patterns.md` for the flow).

## Feedback

- `.alert` (info `--mana`, default amber, `.crit` `--blood`): left 3 px bar, 8/12 px padding, optional `.act` button on the right, `.dismiss` x; stacks in `.heads` (shows two, "more" for the rest); collapses with a 0.28 s height transition. Use it for something that needs the player's attention in a view.
- `.toast`: fixed bottom centre, `--night-deep` fill, gold 1 px border, 13 px; fades in and out on `opacity`, `z-index: 70`. Use it for a result that needs no decision ("Name saved").
- `.term` + `.tip`: dotted underline jargon with a 240 px explanation on hover/focus; `.tipbox` is the small fixed label tooltip (`z-index: 40`/70).
- `.probe-error`, `.g-proj.warn/.crit`: inline red/amber notes with a left bar.
- `.empty`: dashed gold box with an `h2` 20 px, a one-line reason and a numbered list of next steps.
- Status colour = meaning: `--life` OK/live, `--mana` info/pending, amber warning, `--blood` critical; never decorate with them.

## Data display

`.stat-tile` (label 9 px, value 14 px `--display` tabular-nums, `.hot` = gold), `.stat-grid` (4 or 3 columns), the gauges/meters, `.pop` / `.dmg-pop` for live damage numbers. Numbers use `font-variant-numeric: tabular-nums`.

## Scrollbars

Thin, gold at 35% (`scrollbar-width: thin; scrollbar-color: rgba(251,191,36,0.35) transparent`), 8 px WebKit thumb with a transparent border. Every inner scroller uses this; never show a default light scrollbar inside the sheet.

## HUD panels (`.bf-*`)

`.bf-team`, `.bf-board`, `.bf-plate`, `.bf-feed`: `--bf-panel` fill, 1 px `--bf-line`, titles in Silkscreen 12 px `--bf-dim`, names in Pixelify Sans, numbers in Press Start 2P at `--bf-num-px`. The HUD overlays the canvas with `pointer-events: none` on the grid and `auto` on the panels. A new HUD element is a new panel in this grid area scheme, not a free-floating box.

## App-world controls (Tailwind pages)

Buttons and links follow `account-nav`: `rounded-lg border border-gray-200 px-3 py-1 text-gray-700 hover:border-orange-400 hover:text-orange-600`. This is the only place rounded corners are right. Do not place these on a dark game panel.
