# Frontend (project-specific)

## Livewire 4 + Alpine

- State lives server-side in the Livewire component; Alpine handles purely client-side interactivity (overlays, toggles, canvas HUD positioning). Don't duplicate server state into Alpine stores.
- Blade views receive already-shaped data from services — no query building or aggregation in blades or Livewire `render()` beyond delegating to a service.
- Check `resources/views/livewire/` and `resources/views/partials/` for an existing component before writing a new one.
- Admin UI is Filament v5 (`app/Filament/`, custom views in `resources/views/filament/`) — build admin pages/widgets there, not as new Livewire pages. Widget data comes from a `Services/Analytics/*Query` class.

## Battlefield (Phaser 3)

- All game code lives under `resources/js/battlefield/`. Deep knowledge: `.ai/domain/battlefield.md` and the `battlefield` skill.
- Decision logic must be extractable: pure functions in their own modules so Vitest can cover them without a Phaser runtime.
- Fighter sprite sheets are `frameWidth: 100` — never upscale or regenerate sheets at other sizes.
- Import game modules via the `@battlefield/...` alias (defined in both `vite.config.js` and `vitest.config.js`), not relative `../../` paths.
- Adding/removing a module under `resources/js/battlefield/**` → update the Key Files table in `resources/js/battlefield/CLAUDE.md` in the same commit. Changes there should go through the `battlefield-reviewer` agent.
- Adding a fighter/boss/companion/minion sprite → follow `public/assets/battlefield/CLAUDE.md` (formats, naming) and credit it in `CREDITS.md`. Fighter source frames live in `resources/assets/battlefield/fighters/` and are packed into a gitignored atlas by `scripts/pack-sprites.js` (runs automatically in `npm run build` / `npm run dev`).
- Tunables live in `resources/js/battlefield/config/` (`fighters.js`, `bosses.js`, `companions.js`, `layouts.js`, `timings.js`), not as literals in managers.
- New real-time event → use the `scaffold-broadcast-event` skill (PHP event → `ECHO_EVENT_MAP` in `resources/js/battlefield/index.js` → scene bus key).

## Build & verification

- Every JS/CSS change needs `npm run build` before it exists anywhere but your editor.
- Vite entry points: `resources/css/app.css`, `resources/js/app.js` (Echo + lazily-imported battlefield), `resources/js/ide-bridge.js` (IDE webview bridge). `VITE_REVERB_*` env values are baked in at build time — build with the target environment's values.
- The team does not test locally — changes are verified on staging. Build, then deploy per the standing staging workflow (rsync `public/build/`), then verify in the browser there.
- Tailwind 4 (CSS-first config); prefer existing utility patterns in the blades over new custom CSS.
