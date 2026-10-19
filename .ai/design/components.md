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

Rules for weight and stability (checked on the real page, not only in CSS):
- One gold action per view. Tools (the frame inspector) and secondary actions beside Use are small, 1 px dim-bordered (`#2e3d63`), parchment text, gold only on hover/focus/pressed. 30-36 px high on a mouse, 44 px only under `@media (pointer: coarse)`.
- A control whose label changes (Pause/Play, Equip/Equipping, Build/Building) reserves the width of its longest label (`min-width`), and a status text beside it is tabular with a fixed width. Toggling must never move a neighbour.
- Buttons keep their natural width inside a flex/grid row (`flex: 0 0 auto`); a stretched full-width button is a bug unless it is the sticky phone action bar.
- Fields and the buttons beside them share one height and one edge weight.
- A confirm row is the question on its own line, then Cancel (safe, focused) and the destructive verb right-aligned, the verb last.
- Before calling a screen done, measure it: list every button/field with size, font, border and colour and look for outliers; open each state (empty, loading, error, long text), not only the happy path.

Rules: one `.primary` per view. Squared, no radius. Hover is `filter: brightness(1.1)`. Every pressable control sets `cursor: pointer` (Tailwind 4 preflight resets buttons to the arrow; see `layout.md`). Destructive buttons are not a new colour: they are `.btn` with the confirm pattern in `patterns.md` and, once confirmed, `--blood` text on the confirm step only.

## Tabs

`.sheet-tabs` > `.sheet-tab` (14 px `--display`, `--dim`; selected: gold text and a 2 px gold underline that scales in 0.22 s). `role="tab"`, `aria-selected`, arrow keys rove, panel `.tab-panel.is-active` fades in 0.22 s.

## Tiles and pickers

- `.roster-grid` 4 columns (5 on a phone), gap 8 px, inside `.roster-scroll` (own scroller).
- `.slot`: a fighter tile (aspect 0.86, fill `#0e1a31`, border `#243150`). Hover: gold border at 55%; selected: `2px solid var(--rivet)` + 3 px soft ring; active: scale 0.96. Name `.nm` is a 10 px label at the bottom; `.tag` is a gold 9 px corner label (`YOU`, and the marker for a kind of fighter). The face animates only on hover or when selected.
- A minion the player has just built is a `.slot` too, badged `MINION DRAFT` (`.badge.draft`, `data-kind="minion"`): it is not counted as a fighter, no style chip claims it (only All shows it), and it is never equipped. Its stage actions are `Add to minions` (the primary button) and `Discard`.
- Another player's active custom fighter uses the same `.slot` preview, with a muted `.badge.other` naming its creator. The stage shows the author and moves; Equip and Edit stay unavailable. A live fighter change refreshes these slots while the sheet is open.
- `.slot-add`: dashed "add" tile in the grid (`1px dashed #3a4a70`, `--dim`, turns gold on hover/focus). It is an action, not a fighter; it is not a `.slot`, so selection, filters, keys and tooltips ignore it. Its label says what it does ("Create", "Manage"); it is the visible entry point to an editor, so it must be findable: do not hide it among slots with a 10 px label only.
- `.chips` / `.chip`: single-select filter row (11 px `--display`, `1px solid #2b3858`, selected `aria-pressed="true"` = gold fill, dark text). A filter chip filters; it never hides an item that has no matching value: items with no style get their own chip, they do not appear under every chip.
- `.move` / `.hotbar`: a numbered action button with a looping thumbnail (`aria-pressed`), key legend `.key`.
- `.menu`, `.mpick-menu`: dropdown, hover/focus row `rgba(251,191,36,0.1)`, `z-index: 20`.

## Show stage (preview of a fighter)

`.show-stage` (270 px desktop, 190 px portrait, 120 px landscape; accent glow floor line, sprite at `scale: 4`, boss on the right), `.show-info` (name, type and `.show-state`), `.hotbar`, `.inspector` (Pause, Frame, Play for a draft or version peek), and `.equip-row`. A draft is a `.slot .badge.draft` roster entry, using the same stage and moves as a saved fighter. Do not build a second preview widget.

`.show-author` (12 px `--display` gold, hidden when empty): its own line under `.show-type` reading "Created by <handle>" for a custom fighter and its draft; never merged into the description line, never shown for a roster fighter. The author is plain text (`textContent`), and it lives only in `sheetState()`, never in the public broadcast.

## Editor panel (the custom fighter editor, `.cce`)

Opening the editor adds `.char-body.forging`: the roster gives way to the live stage and `.cce-drawer` side by side. At 900 px and below they stack, with the stage above a scrollable editor. Back restores the roster. When there is no fighter, `.forge-blueprint` replaces all battle visuals with a quiet motion example; it disappears as soon as a fighter exists.

| Class | Look | Use |
|---|---|---|
| `.cce` > `.cce-sec` | grid of sections, 18 px apart, a gold hairline between them; `h3` is 15 px `--display` gold | one concern per section (preview, upload, your fighter, versions) |
| `.cce-head` | title left, a `.show-state` badge right | a section whose state is a badge (`Previewing`, `Equipped`, `In use`) |
| `.cce-note`, `.cce-ok` | 13 px `--dim` / `--life` | a hint or a status line next to a control: the reason a button is disabled, "File uploaded." |
| `.field` + `.field-count` | label above (12 px `--display` `--dim`), 44 px input on the tile fill, a `n / max` counter | a text field whose length is limited and read by others |
| `.upload-bar` | 6 px bar, fills by `transform: scaleX(var(--p))` | upload progress (never animate `width`) |
| `.cce-steps` | three numbered steps (Make a sheet, Upload and build, Try it then use it); the current one gold, finished ones green with a check | where the player is in the flow |
| `.cce-step-caption` | current step name beside the compact three-number progress on a phone | keeps the step readable without squeezing three labels into one row |
| `.cce-drop` + `.cce-chips` | dashed drop zone with an icon, one primary button and requirement chips; solid gold on drag-over | choosing the pose sheet (drag and drop or button) |
| `.cce-filecard` | thumbnail, file name and size, progress or build bar, Uploaded check | the chosen file after it is picked |
| `.forge-prototype`, `.forge-action`, `.forge-motion-frame` | four selectable source rows, the existing Lancer's motion shown as a one-colour alpha silhouette, and the selected row's required pose count | explains Idle, Walk, Attack and Death without implying the empty stage has a fighter |
| `.cce-thumb` | 96 x 72 chosen-file image | confirms the selected sheet before building |
| `.build-bar` | 6 px indeterminate transform animation, stopped under reduced motion | server-side build progress after upload finishes |
| `.versions` > `.version` | auto-fill grid of 150 px tiles, `.current` has the gold border; `.vsprite` is the idle strip at 1x | numbered versions with Preview, Equip and Delete actions |
| `.forge-tabs` > `.sheet-tab` + `.forge-pane` | the sheet tab look (gold text, 2 px underline) at 44 px high, in a strip above the editor; `role="tablist"`, arrow keys rove (Home/End too), the panel not shown is `hidden`; `.forge-pane` is `display: contents` so the editor inside stays a direct flex child of the drawer | `Fighter` \| `Minions` in the Forge. `character-stage.js` owns them (no Alpine state) and announces a pick with the `custom-forge-tab` event; the stage shows the draft the open tab owns. Not sheet tabs: only `.sheet-tab[data-tab]` is the sheet's |
| `.minion-field-view` > `.mfv-sprite` (`.mfv-fighter`, `.mfv-minion`) + `.mfv-caption` | 88 px box on the tile fill, a floor line 8 px up; the equipped fighter and the minion idle strip, each at the scale the battlefield draws them (`fieldSizeMinionScale`) | "At field size" beside a minion on the stage; hidden for a fighter and while the drawer is open; sprites stop under reduced motion |
| `.confirm-row` | an `.alert.crit` row that replaces the control in place, with a `.btn.blood` (the verb) and a `.btn` Cancel | confirming a destructive action (see `patterns.md`): names the thing, Cancel focused, Escape cancels |
| `.btn.blood` | `.btn` with `--blood` text and border | the confirming button of a destructive action, only on the confirm step |

## Profile mini stage

`.mini-stage` shows the equipped fighter and one `.mini-minion` per live subagent. The default kinds are `.m-goomba` and `.m-demon`; with minions of the player's own every spot is `.mini-minion.m-custom` (88 px), drawn from the idle strip of one of the set in turn. The blade sets three custom properties on it and the CSS steps the strip by them, like `.vsprite`: `--strip` (`url(...)`), `--frames`, `--rate`. Under reduced motion it holds frame 0. `.stage-links` (right of the name, wraps) holds two `.link` buttons, `Change character` and `Customize minions` (opens Forge on the Minions tab), both at least 44 px high.

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
