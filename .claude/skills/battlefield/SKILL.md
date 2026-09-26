---
name: battlefield
description: Use when working in resources/js/battlefield/ — the Phaser 3 real-time battlefield game (scene, snapshot, fighters, boss, minions, necromancer, leaderboard, projectile, attacks, layout, bus), adding a fighter/boss/minion type or sprite, or wiring a Laravel broadcast event to it. Covers the Echo→bus→scene data flow, the boot payload/snapshot round-trip, Phaser teardown rules, add-a-type recipes, and staging debugging.
---

# Battlefield (Phaser game)

A Phaser 3 scene rendering live combat driven by Reverb broadcasts. Code: `resources/js/battlefield/`. Booted by `window.bootBattlefield(mount, state)` from `resources/views/livewire/battlefield.blade.php`, which carries a `data-battlefield-state` payload built by `App\Livewire\Battlefield`.

Read alongside (don't duplicate here):
- `resources/js/battlefield/CLAUDE.md` — code conventions, wiring order, entry/minion shapes, Key Files, testing pattern.
- `.ai/domain/battlefield.md` — invariants + companion behaviour.
- `public/assets/battlefield/CLAUDE.md` — sprite formats and cache-busting.
- `.ai/domain/broadcasting.md` — PHP↔JS payload contract rules.

## Data flow

```
PHP Event ──broadcastAs()──▶ Reverb public 'battlefield' channel
                                      │
                         index.js ECHO_EVENT_MAP  (PHP name → BusEvent key)
                                      ▼
broadcastWith() payload ──▶ bus.emit(key, payload)
                                      ├─▶ scene.js _busHandlers[key] → manager.handleX(payload)
                                      └─▶ battlefield.blade.php inline listeners (via window.__battlefield.bus)
```

Three names must line up: PHP `broadcastAs()` === `ECHO_EVENT_MAP` key; its value is a `BusEvent` constant (`constants.js`) === the `_busHandlers` key.

| `broadcastAs()` | `BusEvent` | Handler |
|---|---|---|
| `HitDealt` | `HIT` `'hit'` | `fighter.handleHit` (blade also listens) |
| `BossSpawned` | `BOSS_SPAWNED` | `boss.handleBossSpawned` (blade also listens) |
| `BossKilled` | `BOSS_KILLED` | `boss.handleBossKilled` |
| `FighterJoined` | `FIGHTER_JOINED` | `fighter.handleFighterJoined` |
| `FighterCharging` | `FIGHTER_CHARGING` | `charge.handleCharging` |
| `FighterIdled` | `FIGHTER_IDLED` | `fighter.handleIdled` + `minions.despawnAll` |
| `FighterMoved` | `FIGHTER_MOVED` | `fighter.handleFighterMoved` |
| `FighterChargeCleared` | `FIGHTER_CHARGE_CLEARED` | `charge.handleChargeCleared` |
| `FighterCharacterChanged` | `CHARACTER_CHANGED` | `fighter.updateCharacters([payload])` |
| `FighterAgentCountChanged` | `FIGHTER_AGENT_COUNT_CHANGED` | `minions.handleAgentCountChanged` |
| `FighterAgentToolUsed` | `FIGHTER_AGENT_TOOL_USED` | `minions.handleAgentToolUsed` |
| — (client-only, after Echo reconnect → `Battlefield::resync()`) | `POSITIONS_RESYNCED` | `fighter.reconcilePositions` |

Client-only bus events for the blade HUD (no PHP side): `leaderboard-updated` (`leaderboard/index.js`), `show-mvp-overlay` (`leaderboard/mvp.js`).

Echo listens with a leading dot (`.HitDealt`) because of the custom `broadcastAs()`. The handler reads exactly `broadcastWith()` — snake_case keys (`user_id`, `boss_hp_after`, …). Need a new field client-side → add it to `broadcastWith()` and the shape test.

## Boot payload ⇄ snapshot

`data-battlefield-state` (blade) and `snapshotState()` (`snapshot.js`) must produce the same shape:

```
{
  boss: {number, name, currentHp, maxHp},
  currentUserId,
  fighters: [{id, handle, avatarUrl, character, charging: {activity}|null,
              position: {x, y} (0–1 fractions)|null, agentCount}],
  leaderboard: [{userId, damage, handle}],
  damageTotals: [[userId, damage], ...],
  globalDamage: {allTime, monthly, daily, hourly}   // passed through untouched by the snapshot
}
```

Orientation flip: `applyModeChange` → `snapshotState` → resize → `registry.set('initialState', …)` → `scene.restart()`. A new boot field must be (1) emitted by the blade, (2) read in `create()`/a manager's seed, (3) captured in `snapshot.js`, (4) asserted in `tests/js/snapshot.test.js`. Missing (3) = lost on rotate.

## Phaser teardown rules

- Everything `scene.add.*` / tween / `scene.time.*` / `bus.on` you create must be released. Scene-level: extend the `events.once('shutdown')` block in `scene.js` (it already does `bus.off` for `_busHandlers`, `leaderboard.destroy()`, `minions.destroy()`). Entity-level: destroy on leave (fighter idle, boss killed, minion despawn).
- Objects NOT parented to a container (minions, their badges/rings, handle labels, summon circles) must be destroyed explicitly when their owner leaves; scene shutdown alone destroys the display list.
- Async callbacks (avatar loads, delayed calls) must guard `sprite?.active` / `scene.isShuttingDown` — the scene may have restarted.
- `window` listeners: only via `index.js`'s module-level `_cleanupResize` pattern.
- Maps are keyed by numeric `user_id` from payloads; a string key silently misses.

## Recipes

### Add a broadcast event
Use the `scaffold-broadcast-event` skill (PHP event → `ECHO_EVENT_MAP` → `BusEvent` → `_busHandlers` → `tests/Feature/Events/BroadcastShapeTest.php`). Battlefield-side extras: add the constant to `BusEvent` **and** to `tests/js/constants.test.js`'s full list; if the state it carries must survive a reload, seed it from the boot payload and the snapshot (above).

### Add a fighter type
1. Strips → `resources/assets/battlefield/fighters/` as `<key>-idle|walk|attack|death.png`, plus `<key>-attack<N>.png` / `<key>-effect<N>.png` per attack slot, optional `<key>-summon.png` (rise-in) / extra states. Every frame 100×100, horizontal strip — the packer derives frame count from width/100. Never upscale.
2. Append an entry to `FIGHTER_TYPES` (`config/fighters.js`): `key`, `attackType` (`AttackType`), `chargeColors` (exactly 5 hex numbers), `animations` (`idle`, `walk`, `attack`, `death` required; `{frames, rate}`; no `file`/`frameWidth`), `attacks: [{frames, rate, effectFrames}]` (N-th entry ↔ `attack<N>`/`effect<N>`; `effectFrames: 0` for no effect strip).
3. Append the same key as the **last** case of `App\Enums\FighterCharacter` and update both order-lock tests (`tests/js/config.test.js`, `tests/Unit/FighterCharacterTest.php` — it hardcodes the case count `% 20`). Order is a contract (domain invariant #11).
4. Character-select extras: `accentFor` map in `livewire/character-select.blade.php`; `ATTACK_LABELS` in `character-preview/attack-labels.js` only if the type needs more slots than its `attackType` row has (else "Attack N" fallback).
5. `npm run build` (repacks atlas + regenerates `ATLAS_VERSION`), `npx vitest run tests/js/config.test.js tests/js/pack-sprites.test.js`, then the PHP tests. Add a row to `public/assets/battlefield/CREDITS.md`.

### Add a boss type
1. Sprites → `public/assets/battlefield/bosses/`: either one sheet (`file`, `frameWidth/Height`, `idleStart/idleEnd`, optional `move|attack|hurt|death` `Start/End/FrameRate` ranges) or a folder of per-state strips (`animFiles: {idle, move?, attack?, hurt?, death?, getup?, …: {file, frameWidth, frameHeight, count, rate, loop?}}`). `idle` is required; `idle`/`move` strips must set `loop: true` or the patrol freezes (test-locked).
2. Add to `BOSS_TYPES` (`config/bosses.js`) with `key: 'boss-<name>'` and `scale`; optional `float: {amplitude, duration}`, `pixelArt: false` (smooth-filtered art). Position in the array decides which boss numbers get it (domain invariant #4) — append unless a reshuffle is intended.
3. Anything boss-specific beyond the generic patrol (like `boss/dreadknight.js`) is keyed by `isX(bossTypeKey)` in `boss/index.js`.
4. `npx vitest run tests/js/config.test.js` (files exist, frame grid divides the PNG), update `public/assets/battlefield/CLAUDE.md` + `CREDITS.md`.

### Add a minion type
1. Strips → `public/assets/battlefield/companions/<key>/`, **all strips of one type share one frame size**, every strip drawn facing RIGHT (code flips). Need `idle` + `walk` (both `loop: true`), ≥1 one-shot attack strip, and a reaction strip.
2. Append to `MINION_TYPES` (`config/companions.js`): `key`, `charHeight` (the character's ink height in frame px — the scale divisor that makes every type render at the same height), `animFiles` (`file` with `?v=<n>`), `attacks: [{anim, hitFrame, travel?: {from, to}, fidget?: false}]` (`hitFrame` 0-based inside the strip; `travel.to ≤ hitFrame`; the strip stays in place, code moves the sprite), `reaction: {anim, holdMs?, getUp?}` (total play time ≥ 1800ms, test-locked).
3. `tests/js/config.test.js` asserts **exactly 5** minion types — update that count. `minions.js` picks types uniformly at random; no other registration is needed (preload + `_ensureAnims` iterate `MINION_TYPES`).
4. Credit the source in `CREDITS.md`. Bump `?v=` on every later edit of a strip (7-day cache).

## Verify & debug

- JS: `npx vitest run [tests/js/<file>.test.js]` (includes the atlas pack step). Broadcast shape: `spin exec php php artisan test --compact --filter=BroadcastShape`.
- Every JS/CSS/sprite change: `npm run build`. The team verifies on staging, not locally — deploy per `.ai/guidelines/frontend.md`. The fighter atlas is written to `public/assets/battlefield/fighters/`, **outside** `public/build/`, so ship it too when fighter strips change.
- In the staging browser console, `window.__battlefield` exposes `{bus, game, scene, mode, renderScale, …}`. Simulate a broadcast without the backend: `__battlefield.bus.emit('fighter-agent-count-changed', {user_id: <id on screen>, count: 4})`; inspect state: `__battlefield.scene.fighters`, `__battlefield.scene.minions.byUser`.
- `[battlefield] window.Echo not available after retries` in the console = no live events will arrive (Echo/Reverb problem, not scene code).
- Review: dispatch the `battlefield-reviewer` agent on any non-trivial change here.
