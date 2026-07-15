# Frontend (project-specific)

## Livewire 4 + Alpine

- State lives server-side in the Livewire component; Alpine handles purely client-side interactivity (overlays, toggles, canvas HUD positioning). Don't duplicate server state into Alpine stores.
- Blade views receive already-shaped data from services — no query building or aggregation in blades or Livewire `render()` beyond delegating to a service.
- Check `resources/views/livewire/` and `resources/views/partials/` for an existing component before writing a new one.

## Battlefield (Phaser 3)

- All game code lives under `resources/js/battlefield/`. Deep knowledge: `.ai/domain/battlefield.md` and the `battlefield` skill.
- Decision logic must be extractable: pure functions in their own modules so Vitest can cover them without a Phaser runtime.
- Fighter sprite sheets are `frameWidth: 100` — never upscale or regenerate sheets at other sizes.

## Build & verification

- Every JS/CSS change needs `npm run build` before it exists anywhere but your editor.
- The team does not test locally — changes are verified on staging. Build, then deploy per the standing staging workflow (rsync `public/build/`), then verify in the browser there.
- Tailwind 4 (CSS-first config); prefer existing utility patterns in the blades over new custom CSS.
