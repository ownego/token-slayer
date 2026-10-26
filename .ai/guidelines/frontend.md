# Frontend (project-specific)

Look and behaviour of every screen (tokens, components, states, motion, layout, accessibility, copy) are documented in `.ai/design/`; read `.ai/design/README.md` before touching UI.

## Interaction rules (every screen a player uses)

The working flows are the reference: the Fighter tab's roster and Equip, and the battlefield itself. Before building or changing a screen, open the closest working flow and match how it responds; a deliberate difference is named in the PR.

- **Instant.** Picking, previewing, toggling, and switching tabs or moves run in the browser and change the screen in the same frame. A server call is for an action that stores, builds or broadcasts. Example: the Fighter roster picks, previews and plays moves in the browser and calls the server only on Equip.
- **One stage.** An element that draws a preview stays mounted and swaps its content. Give it a fixed `wire:key` (or `wire:ignore`); a key that changes with the selection re-creates it, so its animation restarts and every strip reloads.
- **Alive.** Anything that appears, changes or leaves on a stage or on the field plays the game's own motion for it (summon circle and burst, Clawd poof, pop, a death strip played backwards), timed by `.ai/design/motion.md`; under reduced motion it changes at once.
- **Lands on the field.** A commit that changes what the field shows (Equip) ends where the player sees the result: the sheet folds and the camera pushes onto their fighter (`fighter-equipped`). When the field has nothing to show yet, the toast says where it will show.
- **Busy and refused.** A server action shows it is running (button disabled, a verb with an ellipsis), ignores a second press, and on a refusal keeps what the player chose and says why in the panel.
- **Fits every screen.** A new or changed screen works in the four layout classes of `.ai/design/layout.md`, using their media queries and no new breakpoint: desktop, tablet `(max-width: 1000px) and (min-width: 641px)`, phone portrait `(max-width: 640px)` and phone landscape `(max-height: 500px) and (orientation: landscape)`. On a phone the screen's primary action row is sticky at the bottom with full-width buttons, touch targets are at least 44 px, a stage stacked under its list scrolls into view when the player picks, and in landscape the stage and its actions fit without scrolling past them. `tests/js/sheet/fighter-sheet-css.test.js` gets a case for each phone rule the screen adds.
- **Measured on staging.** Before the PR, use every new control on staging with the network panel open (a pick or a toggle sends nothing, a commit sends one request), and look at the screen at the four layout sizes (devtools device mode is enough: 1280 x 800, 900 x 700, 390 x 844, 844 x 390). The PR template's checklist records both.

## Livewire 4 + Alpine

- What is stored lives in the Livewire component: what the database holds and what its actions check. What the player is choosing before a commit (the selected cell, unsaved ticks, the move on a stage, an open overlay) lives in Alpine (see Interaction rules); this narrows the generic Livewire advice to keep state server-side, which covers stored state only. Alpine reads stored state from the component (a `#[Locked]` property through `$wire`) rather than keeping its own copy.
- Blade views receive already-shaped data from services — no query building or aggregation in blades or Livewire `render()` beyond delegating to a service.
- Check `resources/views/livewire/` and `resources/views/partials/` for an existing component before writing a new one.
- Admin UI is Filament v5 (`app/Filament/`, custom views in `resources/views/filament/`) — build admin pages/widgets there, not as new Livewire pages. Widget data comes from a `Services/Analytics/*Query` class.

## Battlefield (Phaser 3)

- All game code lives under `resources/js/battlefield/`. Deep knowledge: `.ai/domain/battlefield.md` and the `battlefield` skill.
- Decision logic must be extractable: pure functions in their own modules so Vitest can cover them without a Phaser runtime.
- Fighter sprite sheets are `frameWidth: 100` — never upscale or regenerate sheets at other sizes.
- Import game modules via the `@battlefield/...` alias (defined in both `vite.config.js` and `vitest.config.js`), not relative `../../` paths.
- Adding/removing a module under `resources/js/battlefield/**` → update its row in `resources/js/battlefield/FILES.md` (Key Files, and Test Files for its test) in the same commit. Changes there should go through the `battlefield-reviewer` agent.
- Adding a fighter/boss/companion/minion sprite → follow `public/assets/battlefield/CLAUDE.md` (formats, naming) and credit it in `CREDITS.md`. Fighter source frames live in `resources/assets/battlefield/fighters/` and are packed into a gitignored atlas by `scripts/pack-sprites.js`; hero strips live in `resources/assets/battlefield/heroes/` and are packed into a second atlas by `npm run pack-heroes` (both run automatically in `npm run build` / `npm run dev`).
- Tunables live in `resources/js/battlefield/config/` (`fighters.js`, `heroes.js`, `bosses.js`, `companions.js`, `layouts.js`, `timings.js`), not as literals in managers.
- New real-time event → use the `scaffold-broadcast-event` skill (PHP event → `ECHO_EVENT_MAP` in `resources/js/battlefield/constants.js` → scene bus key).

## Build & verification

- Every JS/CSS change needs `npm run build` before it exists anywhere but your editor.
- Vite entry points: `resources/css/app.css`, `resources/css/battlefield-hud.css`, `resources/css/fighter-sheet.css`, `resources/js/app.js` (Echo + lazily-imported battlefield), `resources/js/ide-bridge.js` (IDE webview bridge). `VITE_REVERB_*` env values are baked in at build time — build with the target environment's values.
- The team does not test locally — changes are verified on staging. Build, then deploy per the standing staging workflow (rsync `public/build/`), then verify in the browser there.
- Tailwind 4 (CSS-first config); prefer existing utility patterns in the blades over new custom CSS.
