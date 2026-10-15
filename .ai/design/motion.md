# Motion

## Durations and easing in use

| Purpose | Duration | Easing |
|---|---|---|
| Hover/colour change on tiles, chips, moves | 0.15 s | `ease` |
| Tooltip in/out | 0.12 s | `ease` |
| Panel/tab content in | 0.22 s | `ease`, 4 px rise |
| Tab underline | 0.22 s | `cubic-bezier(.2,.8,.2,1)` |
| Fold/collapse, alert collapse | 0.25-0.28 s | `cubic-bezier(.2,.8,.2,1)` |
| Fighter swap on the stage | 0.25 s | `ease` |
| Button flash, toast | 0.6 s / short fade | `ease` |
| Live damage pop | 0.9-1.1 s | `ease-out` |
| Stage background tint | 0.4 s | `ease` |
| Ambient loops (sunburst, ring, beam, glow) | 2.4-40 s | linear / ease-in-out |

Pick from these; a new duration needs a reason. Only `transform`, `opacity` and (rarely) `filter` animate; never layout properties, because Phaser keeps the main thread busy on the battlefield page.

## Reduced motion

Every animation has an off switch under `@media (prefers-reduced-motion: reduce)`. The sheet has a blanket rule at the end of its base block (`.fs *, ::before, ::after { animation: none !important; transition: none !important }`); new HUD or standalone CSS adds its own. A state that is only communicated by motion (a pulse that means "building") also needs a text or icon state.

## Sprites and previews

- Frames are 100 x 100. Scale only by integers in CSS (`scale: 3`, `4`) or the camera, with `image-rendering: pixelated`. Never smooth, never fractional-scale a frame.
- A preview plays at the in-game animation rate (the game's `animations` table: frames at rate), not faster. A preview that loops faster than the game misleads the player about how the fighter will look.
- A preview a player must judge (a fighter they are about to equip or create) shows the fighter at stage size (`.show-stage`, scale 4), lets them pick a pose (the `.move` hotbar), and can be paused or stepped; it does not rely on one small looping thumbnail.
- Idle faces in tiles animate on hover or when selected only.
- Load assets before showing: show the loading state (`patterns.md`) instead of a blank frame; a swap fades in over 0.25 s.

## Game feel

Impact feedback (shake `0.28 s`, damage pop, flash) belongs to game events (a hit, a kill, equip). Do not add it to ordinary form actions; a saved name is a toast, not a shake.
