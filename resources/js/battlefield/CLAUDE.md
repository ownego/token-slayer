# Battlefield JS Conventions

How to write code in `resources/js/battlefield/`. Related docs — read, don't duplicate:
- `FILES.md` (this folder) — Key Files (what each module does and exports) and Test Files (what each test covers). Look up a module there before changing it, and update its row in the same commit.
- `.ai/domain/battlefield.md` — invariants + companion (Necromancer/BatSwarm/Minions) behaviour.
- `.claude/skills/battlefield/SKILL.md` — data flow, boot payload, recipes (new fighter/boss/minion/event), staging debugging.
- `public/assets/battlefield/CLAUDE.md` — sprite formats, roster, cache-busting.

## Architecture
- `scene.js` (~480 lines) is the coordinator: it loads assets, instantiates managers, wires bus handlers, tears down. Domain logic lives in sibling modules.
- Each manager is an ES6 class taking `scene` in its constructor and stored on it (`scene.boss`, `scene.fighter`, `scene.minions`, …).
- Shared state lives on `scene` as the single source of truth (see Scene State below). Cross-manager calls go through `this.scene.<manager>.method()`, usually with optional chaining (`this.scene.bubble?.showFighterTooltip?.(id)`) so a manager that isn't wired yet is a no-op, not a crash.
- Imports: `@battlefield/...` alias (vite.config.js + vitest.config.js) or a same-directory `./x.js`. No `../../` climbing.
- Barrel modules: `x.js` → `export * from './x/index.js'` (fighter, boss, attacks, config). Import the barrel (`@battlefield/boss.js`); reach into the directory only for a pure helper (`@battlefield/boss/bat-wander.js`).

## Manager wiring order in `scene.create()`
Actual order (do not reorder casually — later managers read state the earlier ones created):
1. Camera zoom (`renderScale × WORLD_ZOOM`), spark texture, the living-sky `environment` (replaces the old flat background + static vignette).
2. `Boss` (`boss.create(state)` — also creates `scene.batSwarm`) → `Necromancer` → `Bubble`, `Charge`, `Impact`, `Projectile`, `Attacks` → `HeroEffects` (`scene.heroEffects`, capped by `HERO_EFFECT_CAP`).
3. `scene.fighters`/`scene.damageTotals` maps → `Fighter` + `fighter.seedInitial(state)` → `Minions`.
4. Seed charges and minion counts from `state.fighters` by calling the live handlers with a synthesized payload (keep in sync with the PHP `broadcastWith()`).
5. `_busHandlers` map → `MoveInput.setup()` → `bindBus(bus, _busHandlers)`.
6. `_busHandlers` is bound through `shared/bus-bindings.js`'s `bindBus(bus, handlers)`, whose returned `unbind()` is stashed as `this._unbindBus`. Both `events.once(SHUTDOWN, …)` and `events.once(DESTROY, …)` call the same `_teardown()`, guarded by a `_tornDown` flag (reset each `create()`) so a real page teardown and a `scene.restart()` rotate both release exactly once: `_unbindBus()`, `boss.destroy()` (per-boss script), `minions.destroy()`, `heroEffects.clear()`, `environment.destroy()`. A new manager holding timers/tweens/listeners adds its `destroy()` call inside `_teardown()`.

`update(time, delta)` runs every frame: ticks the environment, emits charge sparks and repositions activity bubbles, then `minions.update(time)`.

## Scene State
| Field | Shape |
|---|---|
| `scene.fighters` | `Map<userId, FighterEntry>` (below) |
| `scene.charges` | `Map<userId, {ring, breath, bubble, activity, sparks}>` (`charge.js`) |
| `scene.damageTotals` | `Map<userId, number>` — cumulative damage vs current boss; drives `damageScale` |
| `scene.bossState` | `{number, name, currentHp, maxHp, script?}` — `script` only for a boss with a per-boss script (`boss/scripts/`) (+ `scene.bossSprite`, `lastKnownBossHp`). The Phaser `hpBar*`/`hpText`/`bossNameText` this used to also carry are gone — the DOM boss plate (`hud/boss-plate.js`) replaced them, driven off `bossState` via the bus, not scene properties |
| `scene.minions.byUser` | `Map<userId, Minion[]>` (below) |
| `scene.layout` / `scene.mode` | the live layout `index.js` derived with `config/layouts.js`'s `layoutFor` (registry key `layout`; `LAYOUTS[mode]` only as a fallback) / `'landscape'｜'portrait'` |
| `scene.currentUserId` | viewer's user id (click-to-move only moves this fighter) |
| `scene.isShuttingDown` | true once `shutdown` fired — guard async callbacks with it |

### FighterEntry (`fighter/index.js`, `scene.fighters.set(...)`)
```
{
  id,                    // user ID (numeric, from the payload — keep key types consistent)
  sprite,                // Phaser.GameObjects.Container (depth 2) — position this, not body
  body,                  // Phaser.GameObjects.Sprite (the animated character, atlas frames)
  head,                  // Phaser.GameObjects.Image (avatar bubble); texture `fighter-<id>` once loaded, fallback before
  handle,                // Phaser.GameObjects.Text | null (world-space name label; null in crowded layouts)
  handleText,            // string (raw untruncated display name)
  pos: { x, y },         // last settled logical position (NOT a live sprite read — see domain invariant #9)
  baseSize,              // displaySize at creation
  displaySize,           // current logical size in px (from fighterDisplayConfig)
  avatarSize,            // avatar image size in px
  avatarUrl,             // `/avatars/<id>?v=<ms>` (null when no id) — real texture loads async
  legH,                  // container-local px from center to foot (× sprite.scaleX for world space)
  ftype,                 // FIGHTER_TYPES entry, or a custom fighter type (`custom: true`, own `texture`, `spec` — fighter/custom-fighter.js)
  bodyFitted,            // true while `body` is scaled/offset for a type with its own bodyScale (custom or hero; _fitBody puts a roster body back)
  rosterKey,             // the roster character (the payload's `character`) — what snapshotState() writes, never a custom key
  customRequest,         // {wantedKey, wantedSpec, loadingKey, failedKey, retryAt} — fighter/custom-fighter-requests.js
  minionPool,            // the player's minion pool: normalised `{rev, defaults: [key], custom: [{version, urlBase, animations, bodyPx}]}`, or null = no information (summon all five defaults). `addFighter` sets it from the boot payload / join; afterwards only `Minions#handleMinionsChanged` writes it, and only when `rev` is newer (`isNewerPool`) — re-joins, `charge.js` and resync just forward the raw `minion_pool` object to it (a missing key forwards nothing), and `updateCharacters` never touches it
  releaseOnApply,        // the custom looks left behind while an attack still draws from them (a list: two swaps can land in one attack); released when that attack ends
  swapTarget,            // the custom look an animated swap is bringing in, until it lands | null; released if the fighter leaves first
  heroMoveCancel,        // set while a hero move drives the container (super punch): puts it back on pos at its depth | null — attacks/hero/bespoke.js
  damageScale,           // 1.0–1.4 from damageScaleMultiplier
  animState,             // AnimState value
  isStunned,             // always false today (stun is visual-only, boss/stun.js)
  lastStunAt,            // ms — star-orbit effect cooldown only
  waypointMoving,        // local click-to-move animation in progress
  hasCustomPosition,     // true once moved/restored; relayoutFighters() must not grid-snap these
  rescaleTween,          // active tween or null
  // Added lazily by applyFlair (undefined until the first flair hit):
  flairState,            // {flair, expiresAt} (fighter/flair.js)
  flairColor,            // hex string | null
  flairRing,             // Array<{ch, phase, glowBack, text, trail: Arc[]}> | null
  flairSparkles,         // Array<{text, phase, speed, sizeScale}> | null
  flairRingTicker,       // TimerEvent | null — drives updateFlairRing() every 16ms
  flairAngle,            // radians
  flairLastBurstAt,      // ms — post-hit spin-up (flair.js spinMultiplier)
  flairBurstAt,          // ms — debounces burstFlair within 300ms
  flairTimer,            // TimerEvent | null — fires destroyFlair()
}
```

### Minion (`minions.js` `_spawnOne`)
```
{
  sprite, badge,         // world-space Sprite + avatar-badge Image (NOT container children); `badge` and `summonCircle` exist only once `_beginSummon` ran
  typeKey, type,         // MINION_TYPES entry key + the entry itself, or a custom look's key/type (`minion-<user>-<version>`); both `null` while `waitingForLook`
  userId, spawnX, spawnY, // owner id + where the summon started (the wait path creates the sprite before it has a look)
  awaitingCustom,        // the owner's pool has a custom look that is not in yet (set at spawn and at every pool draw, whatever the minion shows) — draws again when a pool look lands
  pendingLook,           // a look parked until the minion is free (end of summon / fidget / `_endFightRole`)
  waitingForLook, waitStartedAt, waitTimer, // hidden and waiting up to `TIMINGS.customMinionWaitMs` for the set; `waitTimer` is released in `_teardownMinion`
  swapping,              // mid look-swap poof — not fight-eligible, no fidget
  charHeight,            // type.charHeight — scale divisor (see config/companions.js)
  animState,             // 'idle' | 'walk' (plain strings here)
  summoning,             // true during circle + rise-in; forces MINION_DEPTH_FRONT
  summonCircle,          // necromancer circle sprite | null — dismissed early if the fighter moves
  summonTimer, idleTimer, fidgetTimer,  // TimerEvents — cleared in _teardownMinion
  fidgeting, fighting, reacting, pinned, // pinned = update() must not walk it (fight/travel in progress)
  fightCooldownUntil,    // scene time ms
  fightTimers,           // TimerEvent[] backstops — cleared on teardown
  idleOffset: {x, y},    // wander offset within its home slot
  homeAngle, homeRadius, // current idle target (set directly each frame by _setHomeTarget)
  zoneKey,               // gathering-zone id (sorted neighbor ids) | null
  wasTrailFollowing,     // per-minion trail→idle transition detector (triggers _resyncMinionHome)
  assignedAgentId,       // hook v7 agent_id currently "using a tool" on this minion | null
  toolRing,              // Graphics | null — redrawn every frame
}
```
Any new field that owns a Phaser object/timer must be released in `_teardownMinion` (covers `_destroyOne`, `despawnAll`, `destroy`).

## JSDoc Convention (JS — NOT the PHP DocBlock rules)
Google JavaScript Style Guide §7. Always **`@return`** (singular), never `@returns`.

- **Types always in braces**: `{number}`, `{Function|null}`, `{Array<{x: number, y: number}>}`, `{{user_id: number|string, count: number}}` for payloads.
- **Method descriptions** start with a third-person verb: "Returns …", "Spawns …".
- `@return` may be omitted only when there is no non-empty `return`; existing code usually still writes `@return {void}`.

| Location | JSDoc required |
|---|---|
| Manager classes | `/** One-line description. */` on the class |
| Public + private (`_`) manager methods | description + `@param` per arg + `@return` |
| Event handlers (`handleXxx`) | `@param` with the payload shape inline |
| Pure module exports | description + `@param` per arg + `@return` |
| Tunable module constants | `/** why this value */` one-liner (see the top of `minions.js`) |

## Testing Pattern
Mapping (matches the `tdd` skill): `resources/js/battlefield/<path>.js` → `tests/js/<path>.test.js` (subdirectories mirror: `boss/bat-wander.js` → `tests/js/boss/bat-wander.test.js`). Run `npx vitest run tests/js/<file>.test.js`.

1. **Preferred — pure module.** Extract the decision/geometry into a Phaser-free module (`minion-fight.js`, `move-geometry.js`, `render-scale.js`) and test that. No mocks needed.
2. **Manager method with a fake scene** — only when the logic can't be pulled out (e.g. `Fighter.handleHit` mid blade-dash, `Impact` flinch). Lives in `tests/js/managers/<Class>.test.js`; stubs Phaser with `vi.mock('phaser', () => ({ default: { … } }))` (only what the import chain touches — see `tests/js/managers/Fighter.test.js`) and passes a hand-built `scene` object.
3. Rendering/animation feel is verified on staging (see the skill's "Debug on staging").

Tests that lock config — update them in the same change: `config.test.js` (roster order, strip files/frame grids, minion attack/reaction rules), `constants.test.js` (full `BusEvent`/`TextureKey` lists).

## Constants
- Enums in `constants.js`: `SCENE_KEY`, `TextureKey`, `BusEvent`, `AnimState`, `AttackType`, `BossPhase`, `DreadknightAttack`; plus `PREVIEW_SPRITE_SCALE` (`fighter/preview.js`'s static roster thumbnails on the fighter sheet's Character tab) and `WORLD_ZOOM` (extra camera zoom, currently 1; mirrored by the DOM Damage HUD).
- Never use magic strings for bus events, texture keys, or fighter anim states in logic. (Known exception: `minions.js` uses plain `'idle'`/`'walk'` for its own `animState`.)
- Module-private tuning constants go at the top of the module in `SCREAMING_SNAKE_CASE`, each with a one-line why (`minions.js` is the model).

## Recipes

**Add a manager module**
1. Create `resources/js/battlefield/<name>.js` exporting `class <Name>` with `constructor(scene)`. Put any decision logic in a separate pure module first (TDD it).
2. Instantiate it in `scene.create()` at the right spot in the wiring order above; if it reacts to broadcasts, add a `BusEvent` + `_busHandlers` entry (see the skill's broadcast recipe).
3. If it owns timers/tweens/listeners or sprites not destroyed with the display list, add `destroy()` and call it from `_teardown()`. If it has per-frame work, call it from `scene.update()`.
4. If it holds state that must survive rotate, capture it in `snapshot.js` (and the Livewire boot payload).
5. Add a row to Key Files in `FILES.md`.

**Add a pure helper + Vitest**
1. Write `tests/js/<module>.test.js` importing from `@battlefield/<module>.js`; run it, watch it fail.
2. Implement as plain `export function` with no Phaser import; one-line comment or JSDoc per the table above.
3. Call it from the manager; add its rows to the Key Files and Test Files tables in `FILES.md`.
