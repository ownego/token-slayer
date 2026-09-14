# Domain: Battlefield (Phaser renderer)

The deep operational guide is the `battlefield` skill (`.claude/skills/battlefield/SKILL.md`) — activate it for any work under `resources/js/battlefield/`. This file records the invariants that outlive any single change.

## Invariants

1. **Snapshot round-trip.** `snapshotState()` output must stay byte-shape-compatible with the `data-battlefield-state` boot payload. The scene is destroyed and re-booted on orientation change; any state not captured in the snapshot is silently lost on rotate.
2. **Teardown symmetry.** Everything created via `scene.add.*`, tweens, timers, and `bus.on` must be released in `shutdown`. Leaks only surface after long sessions.
3. **Sprite sheets.** Fighter sheets are `frameWidth: 100`. Never upscale (a Real-ESRGAN attempt produced broken 19200×3200 sheets). Boss sheets carry their own per-type frame data in `resources/js/battlefield/config/bosses.js`.
4. **Boss cycle.** `Boss.bossTypeFor(number)` = `BOSS_TYPES[number % BOSS_TYPES.length]` — reordering `BOSS_TYPES` changes which visual each boss number gets, nothing else.
5. **Key discipline.** Fighters/charges are keyed by `user_id` from broadcast payloads — keep key types consistent (number vs string never collide-match).
6. **Layout.** Two separate logical spaces: landscape 960×540, portrait 540×960. Positions that cross the wire are normalized fractions of the sender's logical space; receiver-side clamping lives in `move-geometry.js`.
7. **Fighter size depends on headcount, by design.** `fighterDisplayConfig(count, mode)` (`layout.js`) steps `displaySize` down as more fighters join (landscape: 45px ≤14, 36px ≤28, 27px beyond; portrait: 54px ≤8, 45px beyond) so a crowded roster doesn't overflow the canvas. On top of that, `damageScaleMultiplier(damage, maxHp)` adds up to another ×1.4 for a fighter whose cumulative damage share against the current boss's `maxHp` is high. A near-empty battlefield showing oversized fighters is this combination working as intended, not a bug.
8. **Atlas cache-busting.** `ATLAS_VERSION` (`config/atlas-version.js`) must be bumped whenever the fighter atlas PNG/JSON changes — browsers otherwise keep serving the stale cached atlas after a deploy, since the file path itself doesn't change.

## Companions

Two ambient actors are neither a **fighter** (player-controlled, keyed by `user_id`) nor a **boss** (the thing being fought, cycled by `Boss.bossTypeFor`) — they exist purely to dress the fight and never affect real HP/damage math, which always lands on the boss regardless of which branch below fires:

- **Necromancer.** A permanent fixture (present regardless of which boss is up) that performs the "join" ceremony: teleports beside a newly-joined fighter, casts, and the fighter visibly rises into existence there. Since it's one shared sprite, summons are queued one at a time; once `SUMMON_QUEUE_BURST_LIMIT` summons are already waiting (e.g. a whole team starting sessions at once), a further join skips the ceremony and reveals immediately rather than growing the wait.
- **Priest's heal flourish.** Cosmetic-only branch on Priest's attack, decided purely by the damage number's parity: even damage plays the normal elemental burst on the boss; odd damage instead shows the hit "healing" the Necromancer, which then itself visibly bolts the boss — standing in for the hit landing. The real damage/HP always applies the same way regardless of which branch plays.
- **BatSwarm.** Five bats spawn fresh alongside each boss fight (destroyed and respawned per boss, never carried over) and die off in a fixed order as boss HP crosses 80/60/40/20/10% of its max — thresholds, not a countdown, so a scene reboot mid-fight (orientation change) can re-derive which bats are alive from the current HP alone, no separate snapshot needed. A hit's visual target (a specific bat, or the boss itself) is chosen cosmetically from the damage number's last digit; a bat whose threshold this hit actually crossed always dies and takes the hit visually, overriding whatever the last digit alone would have picked.

## Testability

Pure logic (movement geometry, layout math, config) is extracted into plain modules and covered by Vitest in `tests/js/`. Scene-coupled code is verified on staging.
