# Battlefield JS Conventions

How to write code in `resources/js/battlefield/`. Related docs — read, don't duplicate:
- `.ai/domain/battlefield.md` — invariants + companion (Necromancer/BatSwarm/Minions) behaviour.
- `.claude/skills/battlefield/SKILL.md` — data flow, boot payload, recipes (new fighter/boss/minion/event), staging debugging.
- `public/assets/battlefield/CLAUDE.md` — sprite formats, roster, cache-busting.

## Architecture
- `scene.js` (~250 lines) is the coordinator: it loads assets, instantiates managers, wires bus handlers, tears down. Domain logic lives in sibling modules.
- Each manager is an ES6 class taking `scene` in its constructor and stored on it (`scene.boss`, `scene.fighter`, `scene.minions`, …).
- Shared state lives on `scene` as the single source of truth (see Scene State below). Cross-manager calls go through `this.scene.<manager>.method()`, usually with optional chaining (`this.scene.bubble?.showFighterTooltip?.(id)`) so a manager that isn't wired yet is a no-op, not a crash.
- Imports: `@battlefield/...` alias (vite.config.js + vitest.config.js) or a same-directory `./x.js`. No `../../` climbing.
- Barrel modules: `x.js` → `export * from './x/index.js'` (fighter, boss, attacks, leaderboard, config). Import the barrel (`@battlefield/boss.js`); reach into the directory only for a pure helper (`@battlefield/boss/bat-wander.js`).

## Manager wiring order in `scene.create()`
Actual order (do not reorder casually — later managers read state the earlier ones created):
1. Camera zoom (`renderScale × WORLD_ZOOM`), background, vignette, spark texture.
2. `Boss` (`boss.create(state)` — also creates `scene.batSwarm`) → `Necromancer` → `Bubble`, `Charge`, `Impact`, `Projectile`, `Attacks`.
3. `scene.fighters`/`scene.damageTotals` maps → `Fighter` + `fighter.seedInitial(state)` → `Minions` → `Leaderboard` + `seed`.
4. Seed charges and minion counts from `state.fighters` by calling the live handlers with a synthesized payload (keep in sync with the PHP `broadcastWith()`).
5. `_busHandlers` map → `MoveInput.setup()` → `bus.on` for every handler.
6. `events.once('shutdown')`: `bus.off` all handlers, `leaderboard.destroy()`, `minions.destroy()`. A new manager holding timers/tweens/listeners adds its `destroy()` here.

`update(time)` runs every frame: repositions activity bubbles, then `minions.update(time)`.

## Scene State
| Field | Shape |
|---|---|
| `scene.fighters` | `Map<userId, FighterEntry>` (below) |
| `scene.charges` | `Map<userId, {ring, trail, fireEmitter, fireEmbers, breath, bubble, activity}>` (`charge.js`) |
| `scene.damageTotals` | `Map<userId, number>` — cumulative damage vs current boss; drives `damageScale` |
| `scene.bossState` | `{number, name, currentHp, maxHp}` (+ `scene.bossSprite`, `hpBar*`, `hpText`, `bossNameText`, `lastKnownBossHp`) |
| `scene.minions.byUser` | `Map<userId, Minion[]>` (below) |
| `scene.layout` / `scene.mode` | `LAYOUTS[mode]` / `'landscape'｜'portrait'` |
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
  ftype,                 // FIGHTER_TYPES entry
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
  sprite, badge,         // world-space Sprite + avatar-badge Image (NOT container children)
  typeKey, type,         // MINION_TYPES entry key + the entry itself
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
Google JavaScript Style Guide §7. The codebase uses **`@return`** (≈240 uses vs 7 `@returns`) — keep `@return` for consistency.

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
- Enums in `constants.js`: `SCENE_KEY`, `TextureKey`, `BusEvent`, `AnimState`, `AttackType`, `BossPhase`, `DreadknightAttack`; plus `PREVIEW_SPRITE_SCALE` (character-select preview) and `WORLD_ZOOM` (extra camera zoom, currently 1; mirrored by the DOM Damage HUD).
- Never use magic strings for bus events, texture keys, or fighter anim states in logic. (Known exception: `minions.js` uses plain `'idle'`/`'walk'` for its own `animState`.)
- Module-private tuning constants go at the top of the module in `SCREAMING_SNAKE_CASE`, each with a one-line why (`minions.js` is the model).

## Recipes

**Add a manager module**
1. Create `resources/js/battlefield/<name>.js` exporting `class <Name>` with `constructor(scene)`. Put any decision logic in a separate pure module first (TDD it).
2. Instantiate it in `scene.create()` at the right spot in the wiring order above; if it reacts to broadcasts, add a `BusEvent` + `_busHandlers` entry (see the skill's broadcast recipe).
3. If it owns timers/tweens/listeners or sprites not destroyed with the display list, add `destroy()` and call it from the `shutdown` block. If it has per-frame work, call it from `scene.update()`.
4. If it holds state that must survive rotate, capture it in `snapshot.js` (and the Livewire boot payload).
5. Add a row to Key Files below.

**Add a pure helper + Vitest**
1. Write `tests/js/<module>.test.js` importing from `@battlefield/<module>.js`; run it, watch it fail.
2. Implement as plain `export function` with no Phaser import; one-line comment or JSDoc per the table above.
3. Call it from the manager; add it to the Key Files and Test Files tables.

## Key Files

| File | Responsibility |
|---|---|
| `index.js` | Entry: `window.bootBattlefield(mount, state)`, `detectMode`, `ECHO_EVENT_MAP` Echo wiring, reconnect → `Battlefield::resync()` → `positions-resynced`, resize/orientation handling (`applyModeChange` snapshots then `scene.restart()`s the same game), exposes `window.__battlefield` (bus, game, scene, formatHp, computeHudTop, preview helpers) for the blade's inline scripts |
| `scene.js` | Phaser scene coordinator; preload of atlas/bosses/companions/FX; instantiates all managers |
| `bus.js` | Shared `Phaser.Events.EventEmitter`. Scene handlers listen via `BusEvent`; `battlefield.blade.php` also listens (`leaderboard-updated`, `show-mvp-overlay`, `boss-spawned`, `hit`) through `window.__battlefield.bus` |
| `snapshot.js` | `snapshotState(currentState, scene)` — scene → boot-payload shape for restart on orientation change |
| `constants.js` | Enums + `PREVIEW_SPRITE_SCALE`, `WORLD_ZOOM` |
| `config.js` → `config/` | `bosses.js` (`BG_COLOR`, `BOSS_TYPES`), `fighters.js` (`FIGHTER_TYPES`), `companions.js` (`BAT_CONFIG`, `NECROMANCER_CONFIG`, `MINION_TYPES`, `MINION_CLASH_EFFECTS`), `layouts.js` (`LAYOUTS`), `timings.js` (`TIMINGS`). `atlas-version.js` (`ATLAS_VERSION`) is **generated and gitignored** by `scripts/pack-sprites.js` — never hand-edit; not re-exported by the barrel, imported directly by `scene.js` and `character-preview/scene.js` |
| `layout.js` | Pure: `computeFighterPositions`, `fighterDisplayConfig`, `damageScaleMultiplier`, `rowsNeeded`; `chargeFootY` is dead code (wrong ratio — don't use it for foot anchors) |
| `move-geometry.js` | Pure move-target geometry: `isValidMoveTarget`, `bypassY`, `clampMoveTarget`, `snapToValidTarget`, `isInsideLeaderboardPanel`, `planRoute`, `moveOrigin` — boss/HP-bar column, leaderboard and Damage HUD exclusion zones; size-aware margins. `planRoute` is shared by `move-input.js` (local) and `Fighter.handleFighterMoved` (remote echo) so all clients draw the same detour; both plan from `moveOrigin(...)`, never the raw sprite (a blade dash parks the sprite inside the boss column) |
| `fighter-placement.js` | Pure `resolveFighterPlacement(saved, gridPos, ctx)` — restore persisted position vs grid slot; snaps an invalid saved spot. Shared by `seedInitial` and `handleFighterJoined` |
| `hud-position.js` | Pure `computeHudTop(...)` — Damage HUD vertical offset clear of the nav pills; exposed on `window.__battlefield` |
| `resync.js` | Pure `driftedPositions(local, server, epsilon)` — repairs positions lost while Reverb was disconnected (no replay) |
| `render-scale.js` | Pure `canvasSizeFor(cssWidth, dpr, layout)` — canvas px + `renderScale` (camera zoom), 1×–2.5×. Boot and `applyModeChange` must both size through it (domain invariant #10) |
| `format.js` | Pure `formatHp` — K/M/B; exposed on `window.__battlefield` for the HUD |
| `attacks.js` → `attacks/` | `class Attacks` — dispatch per `AttackType` to `arrow.js`, `blade.js`, `blast.js`, `shuriken.js`, `slash.js`; shared `fx.js` (trails/bursts); pure `priest-heal.js` (`isHealRoll`) |
| `projectile.js` | `class Projectile` — projectile flight for every attack type |
| `projectile-textures.js` | Canvas-drawn projectile textures: `ensureSlashTexture`, `ensureShurikenTexture`, `ensureArrowTexture`, `ensureBladeTexture` (registered once, reused) |
| `spark-texture.js` | `ensureSparkTexture(scene)` — shared particle texture (`TextureKey.SPARK`) |
| `impact.js` | `class Impact` — damage popup, hit flash, boss flinch (tracks its own rest scale — never baseline on the current scale) |
| `charge.js` | `class Charge` — charging ring, trail, fire emitters (`scene.charges`) |
| `bubble.js` | `class Bubble` — activity bubble + hover tooltip |
| `move-input.js` | `class MoveInput` — click-to-move for the viewer's own fighter, chevron, ripple; clicks on the TOP DAMAGE panel are ignored |
| `leaderboard.js` → `leaderboard/` | `class Leaderboard` — TOP DAMAGE panel, `static abbreviateDamage`, `static showMvpCard`; `doom-fire.js`, `mvp.js`; legacy `makeMethods` factory (tested, not wired into the scene) |
| `boss.js` → `boss/` | `class Boss` — spawn/kill, patrol, react anims, HP bar, `static bossTypeFor`; `dreadknight.js` (turn-based patrol for `boss-abyssal-dreadknight`); `stun.js` (visual-only); `bats.js` (`class BatSwarm`); pure `bat-targeting.js` (`computeBatHitTarget`, `BAT_HP_THRESHOLDS`), `bat-wander.js` (`randomWanderPoint`, shared with necromancer + minions), `summon-queue.js` (`shouldSkipSummonFlourish`, `SUMMON_QUEUE_BURST_LIMIT`) |
| `necromancer.js` | `class Necromancer` — permanent join-ceremony fixture; `spawnSummonCircle(x, y, scale = 2.6)` reused by minions |
| `minions.js` | `class Minions` — per-fighter subagent swarm (`FighterAgentCountChanged` / `FighterAgentToolUsed`). Behaviour spec: `.ai/domain/battlefield.md` Companions → Minions |
| `minion-fight.js` | Pure `countSidesNear`, `pickAttackerSide`, `travelLanding`, `pickRandom`, `fidgetAttacks`, `flipToFace`, `flipToFaceAngle` |
| `minion-trail.js` | Pure `sampleTrail`, `pushTrailSample` — follow-the-leader replay |
| `minion-layout.js` | Pure `homeSlotOffset`, `homeSlotAngle`, `shortestAngleDelta`, `isInFrontOfFighter` |
| `minion-grouping.js` | Pure `isNear`, `clusterAngles`, `computeZones`, `zoneFanOffset` — gathering zones |
| `fighter.js` → `fighter/` | `class Fighter` — lifecycle, hits, moves, flair ring; `avatar.js` (texture load + loading/failed fallbacks); `animations.js` (`registerFighterAnimations` / `registerAllFighterAnimations` — atlas anims, idempotent, derives `<key>-summon` from reversed death when absent); pure `preview.js` (`centeredScaleFit`, `drawFighterPreview`); pure `flair.js`; `flair-font.js` (model-ring webfont + load gate) |
| `character-preview/` | Separate mini Phaser app for `livewire/character-select.blade.php`: `game.js`, `scene.js` (loads the atlas itself), `attack-labels.js`, `moveset.js`, `skill-loop.js` |

## Test Files
| Test | What it covers |
|---|---|
| `tests/js/layout.test.js` | computeFighterPositions, fighterDisplayConfig, damageScaleMultiplier, chargeFootY, rowsNeeded |
| `tests/js/move-geometry.test.js` | isValidMoveTarget (edges, boss column, leaderboard, Damage HUD), bypassY, clampMoveTarget, snapToValidTarget, isInsideLeaderboardPanel, moveOrigin |
| `tests/js/fighter-placement.test.js` | resolveFighterPlacement — restore, snap-instead-of-reset, grid fallback |
| `tests/js/resync.test.js` | driftedPositions — tolerance, skips mid-waypoint and absent fighters |
| `tests/js/hud-position.test.js` | computeHudTop — nav clearance, letterboxing, offset parent, no-nav (IDE embed) |
| `tests/js/render-scale.test.js` | canvasSizeFor — width×dpr, 1× floor, 2.5× cap, portrait |
| `tests/js/format.test.js` | formatHp tiers |
| `tests/js/snapshot.test.js` | snapshotState — boss/leaderboard/fighters, character, damageTotals, charging, currentUserId, normalized position, agentCount |
| `tests/js/constants.test.js` | Full BusEvent / TextureKey lists, SCENE_KEY — update when adding an event/texture key |
| `tests/js/config.test.js` | FIGHTER_TYPES schema + exact key order (= PHP `FighterCharacter`) + atlas frames; BOSS_TYPES files/frame grids/looping idle+move; BAT/NECROMANCER strips; MINION_TYPES: exactly 5, strips on disk, one frame size per type, attacks one-shot with hitFrame/travel inside the strip, reaction ≥ 1800ms; LAYOUTS companion zones |
| `tests/js/pack-sprites.test.js` | Runs `scripts/pack-sprites.js`: atlas PNG/JSON exist, frame names, ≤4096 wide, ATLAS_VERSION content hash (skipped when source strips are absent) |
| `tests/js/minion-fight.test.js` | Area counting, weighted attacker roll, landing point, fidget filter, facing |
| `tests/js/minion-trail.test.js` | sampleTrail interpolation/clamping, history trimming |
| `tests/js/minion-layout.test.js` | Even slots, shortest angle delta, front/back predicate |
| `tests/js/minion-grouping.test.js` | isNear, clusterAngles, computeZones, zoneFanOffset |
| `tests/js/flair.test.js` | Flair state/ring-layout helpers |
| `tests/js/fighter-preview.test.js` | centeredScaleFit, drawFighterPreview |
| `tests/js/leaderboard.test.js` | Legacy `makeMethods` factory |
| `tests/js/boss/bat-targeting.test.js` | computeBatHitTarget — cosmetic routing, threshold-kill override |
| `tests/js/boss/bat-wander.test.js` | randomWanderPoint — uniform elliptical sampling |
| `tests/js/boss/summon-queue.test.js` | shouldSkipSummonFlourish burst limit |
| `tests/js/attacks/priest-heal.test.js` | isHealRoll parity |
| `tests/js/character-preview/attack-labels.test.js` | getAttackLabel + fallback |
| `tests/js/character-preview/moveset.test.js` | buildMoveset incl. reversed Summon-from-Death |
| `tests/js/character-preview/skill-loop.test.js` | createSkillLoop — looping vs one-shot, token cancel |
| `tests/js/managers/Boss.test.js` | hpBarColor, bossLabel, bossTypeFor, stun cooldown, dreadknight turn sequence (Phaser stubbed) |
| `tests/js/managers/Charge.test.js` | chargeParticleColors |
| `tests/js/managers/Fighter.test.js` | fighterRestScale; handleFighterMoved/handleHit with a fake scene — a mid-dash move/hit never records the boss column as home |
| `tests/js/managers/Impact.test.js` | Boss flinch around the rest scale under overlapping hits (fake scene) |
| `tests/js/managers/Leaderboard.test.js` | Leaderboard.abbreviateDamage |
