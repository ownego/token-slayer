# Domain: Battlefield (Phaser renderer)

The deep operational guide is the `battlefield` skill (`.claude/skills/battlefield/SKILL.md`) — activate it for any work under `resources/js/battlefield/`. This file records the invariants that outlive any single change.

## Invariants

1. **Snapshot round-trip.** `snapshotState()` output must stay byte-shape-compatible with the `data-battlefield-state` boot payload. The scene is destroyed and re-booted on orientation change; any state not captured in the snapshot is silently lost on rotate.
2. **Teardown symmetry.** Everything created via `scene.add.*`, tweens, timers, and `bus.on` must be released in `shutdown`. Leaks only surface after long sessions.
3. **Sprite sheets.** Fighter sheets are `frameWidth: 100`. Never upscale (a Real-ESRGAN attempt produced broken 19200×3200 sheets). Boss sheets carry their own per-type frame data in `resources/js/battlefield/config/bosses.js`.
4. **Boss cycle.** `Boss.bossTypeFor(number)` = `BOSS_TYPES[number % BOSS_TYPES.length]` — reordering `BOSS_TYPES` changes which visual each boss number gets, nothing else.
5. **Key discipline.** Fighters/charges are keyed by `user_id` from broadcast payloads — keep key types consistent (number vs string never collide-match).
6. **Layout.** Two separate logical spaces: landscape 960×540, portrait 540×960. Positions that cross the wire are normalized fractions of the sender's logical space; receiver-side clamping lives in `move-geometry.js`.

## Testability

Pure logic (movement geometry, layout math, config) is extracted into plain modules and covered by Vitest in `tests/js/`. Scene-coupled code is verified on staging.
