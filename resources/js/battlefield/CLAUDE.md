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
- Barrel modules: `x.js` → `export * from './x/index.js'` (fighter, boss, attacks, config). Import the barrel (`@battlefield/boss.js`); reach into the directory only for a pure helper (`@battlefield/boss/bat-wander.js`).

## Manager wiring order in `scene.create()`
Actual order (do not reorder casually — later managers read state the earlier ones created):
1. Camera zoom (`renderScale × WORLD_ZOOM`), spark texture, the living-sky `environment` (replaces the old flat background + static vignette).
2. `Boss` (`boss.create(state)` — also creates `scene.batSwarm`) → `Necromancer` → `Bubble`, `Charge`, `Impact`, `Projectile`, `Attacks`.
3. `scene.fighters`/`scene.damageTotals` maps → `Fighter` + `fighter.seedInitial(state)` → `Minions`.
4. Seed charges and minion counts from `state.fighters` by calling the live handlers with a synthesized payload (keep in sync with the PHP `broadcastWith()`).
5. `_busHandlers` map → `MoveInput.setup()` → `bus.on` for every handler.
6. `_busHandlers` is bound through `shared/bus-bindings.js`'s `bindBus(bus, handlers)`, whose returned `unbind()` is stashed as `this._unbindBus`. Both `events.once(SHUTDOWN, …)` and `events.once(DESTROY, …)` call the same `_teardown()`, guarded by a `_tornDown` flag (reset each `create()`) so a real page teardown and a `scene.restart()` rotate both release exactly once: `_unbindBus()`, `minions.destroy()`. A new manager holding timers/tweens/listeners adds its `destroy()` call inside `_teardown()`.

`update(time)` runs every frame: repositions activity bubbles, then `minions.update(time)`.

## Scene State
| Field | Shape |
|---|---|
| `scene.fighters` | `Map<userId, FighterEntry>` (below) |
| `scene.charges` | `Map<userId, {ring, trail, fireEmitter, fireEmbers, breath, bubble, activity}>` (`charge.js`) |
| `scene.damageTotals` | `Map<userId, number>` — cumulative damage vs current boss; drives `damageScale` |
| `scene.bossState` | `{number, name, currentHp, maxHp}` (+ `scene.bossSprite`, `lastKnownBossHp`). The Phaser `hpBar*`/`hpText`/`bossNameText` this used to also carry are gone — the DOM boss plate (`hud/boss-plate.js`) replaced them, driven off `bossState` via the bus, not scene properties |
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
| `index.js` | Entry: `window.bootBattlefield(mount, state)`, `detectMode`, `ECHO_EVENT_MAP` Echo wiring, reconnect → `Battlefield::resync()` → `positions-resynced`, resize/orientation handling (`applyModeChange` snapshots then `scene.restart()`s the same game), exposes `window.__battlefield` (bus, bindBus, game, scene, formatHp, computeHudTop, preview helpers, `setHudZones(rects)`) for the blade's inline scripts. `bootBattlefield` also creates and bus-wires the DOM HUD's imperative controllers once per game — `keepHudOnCanvas`, `createBossPlate`, `createBoardView` (via `rankBoard` over `scene.damageTotals`), `createFeedView` — each torn down on `game.events.once('destroy', …)` |
| `scene.js` | Phaser scene coordinator; preload of atlas/bosses/companions/FX; instantiates all managers; `_teardown()` (via `bindBus`'s unbind + a `_tornDown` guard) runs on both SHUTDOWN and DESTROY, since `game.destroy(true)` never emits SHUTDOWN. `_zones` (live HUD rects in world space, refreshed by `window.__battlefield.setHudZones`) is read only by `move-geometry.js`'s click-to-move validation — a fighter already standing somewhere is never auto-repositioned when a zone appears over it (tried and reverted: pushing an already-placed/clicked fighter off its own spot overrides what the player asked for). `reducedMotion` (`matchMedia('(prefers-reduced-motion: reduce)')`, read once in `create()`) and `sparks` (one scene-wide particle emitter, `TextureKey.SPARK_STREAK`) back `charge.js`'s heel sparks — `update()` calls `charge.emitFor(fighter, dt)` per charging fighter. `spawnGate` (`ceremony.js`'s `createSpawnGate`) holds a `BOSS_SPAWNED` (and the new boss's first hits' visuals) that arrives during the kill ceremony; `wait`/`showKillCard`/`countdown` are the ceremony's own scene-side steps (real `setTimeout`, unaffected by the ceremony's slow-mo). `environment` (`environment/index.js`'s `createEnvironment`, replacing the old flat `BG_COLOR` rectangle + static vignette) is built right after the spark texture, ticked every frame from `update()` (`this.environment.tick(time, delta / 1000)`), and destroyed in `_teardown()` |
| `environment.js` → `environment/` | `createEnvironment(scene, {layout, sky}) → {update, tick, destroy, setClock, horizonY}` — the living sky + arena dressing. `sky-layer.js`'s pure, tested `skyFrame(date, site, box)` is the Phaser-agnostic seam (sun/moon position+visibility, night/twilight/day/warm coefficients); `dressing.js`'s pure, tested `dressingLayout({width, height, horizonY})` places the braziers/keep/mote-band/bird-band from the world box alone. `index.js` bakes the canvas textures (sky gradient, stars, moon phase, clouds, ridges, floor, vignette), draws the distant keep straight into the far ridge's own texture so it takes the same tint, and ticks cloud drift/twinkle/brazier-flicker/mote-wander/an occasional shooting star or bird flock — collapsed to a static snapshot under `scene.reducedMotion`. `setClock(minutesOfDay)` is the staging-only manual clock override behind `window.__battlefield.env.setClock`, gated on `?sky-debug=1` (`index.js`'s `bootBattlefield`) |
| `bus.js` | Shared `Phaser.Events.EventEmitter`, lives for the whole page (not per game instance). Scene handlers listen via `BusEvent`, unbound through `shared/bus-bindings.js`; the DOM HUD's own controllers (`hud/index.js`'s `battlefieldHud`, and `index.js`'s `bootBattlefield` wiring for the board/plate/feed) bind the same way through `window.__battlefield.bindBus` and unbind on `destroy()`/game destroy |
| `snapshot.js` | `snapshotState(currentState, scene)` — scene → boot-payload shape for restart on orientation change; `leaderboard` is built from `scene.damageTotals` (sorted, handles from `scene.fighters`), not the removed `scene.leaderboard.getRanked()` |
| `constants.js` | Enums + `PREVIEW_SPRITE_SCALE`, `WORLD_ZOOM` |
| `shared/hp-counter.js` | Pure `createHpCounter({ startTween, render, initial })` — one retargeted tween per HP bar so a burst of hits never stacks tweens; `set(target)` retargets from the value on screen, `reset(value)` stops and shows at once (respawn must not tween up from 0). Wired into `Impact`'s constructor (`scene.impact.hp`), reset from `Boss.handleBossSpawned` |
| `shared/depth.js` | Pure `setDepthIfChanged(obj, depth) → boolean` — skips Phaser's unconditional `displayList.queueDepthSort()` when a per-frame depth write hasn't actually changed. Used by `minions.js`, `fighter/index.js`'s flair ring, `boss/stun.js`'s star orbit |
| `shared/bus-bindings.js` | Pure `bindBus(bus, handlers) → unbind` — binds a handler map and returns one idempotent unbind function. Used by `scene.js`'s `_teardown()` and exposed on `window.__battlefield.bindBus` for the blade's Alpine components |
| `shared/flair-glyphs.js` | Pure `createGlyphCache({ exists, create })` — bakes each unique flair ring glyph (char × colour × px) once into a texture key instead of building ~20 canvas `Text` objects per flair (fixed the 125–155ms freeze in `startFlairRing`); `glyphKey`, `prewarm(label, color, px)`. Phaser adapter + the new lettering land in Task 26 |
| `shared/heel.js` | Pure `findHeel(alpha, box?) → {x,y}` — rear-most inked pixel in the rows just above a 100×100 idle frame's feet, from raw RGBA alpha; `heelToLocal(heel, scale, facing) → {dx,dy}` mirrors the offset for a left-facing fighter. Feeds the Task 22 sparks/heel wiring |
| `shared/sparks.js` | Pure `SPARK_EMITTER` (shared Phaser emitter config fragment: lifespan 180–380, gravityY 1100, ADD blend), `sparkAngle(facing, rnd)` (192–228° right, 312–348° left), `nextBurst({wait, moving}, dt, rnd) → {wait, count}` — 2–4 sparks standing, 2–6 moving, denser interval while moving. Wired in Task 22 |
| `shared/sky.js` | Pure real-astronomy sky: `sunPosition`/`moonPosition(date, lat, lon) → {el, az, ha, ha0}`, `moonPhase(date) → 0..1`, `skyColorsAt(el)`/`ambientAt(el)` (gradient stop tables), `mixHex`, `skyXY(p, worldWidth, horizonY)` — plots a body from its own day arc (`ha`/`ha0`), **not** azimuth, which wraps through 0/360 near the solstices at 21°N and would throw the noon sun off-screen. Consumed by `environment/sky-layer.js`'s `skyFrame` |
| `shared/clawd.js` | Pure Clawd mascot: `POSES`/`SEQUENCES` (`jump`, `look`, `idle`, `spin`, `skip`, `celebrate`) ported verbatim from Claude Code 2.1.283's `Dre` table at `TICK_MS` (60ms) per frame; `poseRects(pose) → [{x,y,on}]` quadrant pixels; `zoneOf(point, rect) → {col, row}` (3×2 pointer zones); `clawdStep(spot, zone) → {look, move, to}` — one column over hops, two over somersaults, own column just looks ahead (never `arms-up` on its own). Consumed by Tasks 25/40 |
| `shared/feed-merge.js` | Pure `createFeed({max, sameMs, crowdMs, lifeMs, historyMax}) → {push, visible, history}` — folds a burst of activity-feed events into readable lines: same actor+kind within `sameMs` → `×N`, other actors within `crowdMs` → `"a, b +N"`; `visible(now)` caps to `max` live lines (rest counted as `earlier`), lines age out after `lifeMs`; `history()` keeps the raw log |
| `hud/feed-view.js` | Pure `feedText(line) → string` ("a, b +2 joined the fight", "tungot started subagents ×3"), `heraldFor(event) → {kind:'kill'\|'spawn', text}`. `createFeedView(root) → {push(kind, actor), destroy()}` — the keyed DOM feed (owns a `createFeed` instance), FLIP-slides lines, caps to 3 + "+N earlier", a hover history of the last 12 events. Wired in `index.js`'s `bootBattlefield` to `fighter-joined` (kind `join`) and `fighter-agent-count-changed` **only on an increase** (kind `subagent`, tracked per user_id) — kills/spawns don't go through this view at all, they set `battlefieldHud`'s reactive `herald` instead |
| `shared/hud-zones.js` | Pure: `domToWorld(rect, canvasRect, worldWidth, pad)` — DOM panel rect → world-space exclusion zone through the canvas scale, consumed by `scene._zones`/`window.__battlefield.setHudZones` so `move-geometry.js`'s click-to-move validation rejects a target under a HUD panel. `footprint(f, {halfW, top, bottom})` and `resolveSpacing(fighters, zones, sizes, {iterations}) → fighters` (auto-repositions overlapping/occluded fighters) are tested but **not wired into the scene** — tried in Task 20, reverted at the user's request: auto-moving an already-placed/clicked fighter off its own spot silently overrides what the player asked for |
| `shared/camera-focus.js` | Pure `focusPlan(fighter, camera) → {x, y, zoom, duration, hold}|null` — the fighter sheet's equip transition target (the mockup's 2.2× push-in over 950ms, held 2600ms while the field plays the character swap), `null` when the fighter isn't on the field so the caller no-ops. `scene.focusFighter(userId)` adapts it to the real camera (pan+zoomTo, eases back to the previous framing after `duration + hold`ms via `time.delayedCall`, guarded by `isShuttingDown`), exposed as `window.__battlefield.focusFighter` |
| `shared/scene-ready.js` | Pure-ish `onSceneReady(game, key, fn) → stop` — calls `fn(scene)` once the scene exists and after every rotate-restart (waits for the game's `ready` when the scene manager hasn't booted it yet). `bootBattlefield()` wires the DOM HUD before Phaser has created the scene, so the Top Damage board's first render and the HUD's load intro both go through it (rendering only at boot left the board empty after a reload until the next hit) |
| `hud/reveal.js` | `revealHud(hud)` — numbers each HUD panel (`--i`) and adds `.ready`; the CSS keeps the HUD hidden until then and slides the panels in staggered. Called once, on the first scene-ready, together with the boss plate's `intro()` (fill from empty, number counting) and a `bf-hud-intro` event that makes `battlefieldHud` re-count its numbers over 1.2s (`countTo(el, to, fmt, dur)`) |
| `bubble-y.js` | Pure `activityFit(displaySize)` (the action bubble's bounded font 10–14px and line length 14–20 chars, shared with `move-geometry.js`'s zone clearance), `wrapActivity(text, maxChars, maxLines)` (up to two lines wrapped at words, ellipsis on overflow), and `bubbleCenterY({spriteY, headY, headH, scale, bubbleH})` — the activity bubble sits just above the avatar at the fighter's current (damage-grown) scale; used by `Bubble.activityBubbleY()` on create and by `scene.update()` every frame (the activity bubble object exposes `height()` for it) |
| `fighter/avatar-stack.js` | Pure `avatarCenterY(key, scale)` — each fighter's avatar above its own measured head (`sheet/sheet-roster.js`'s `BOX`) by 38/48 of a body height, the mockup's proportion; `addFighter` places the avatar with it and `_applyCharacter` moves it on a swap |
| `fighter/name-ring.js` | Pure `NAME_STYLE` (outlined pixel text, no pill), `NAME_DEPTH` (3, above the fighter containers so the YOU ring never covers a name), `YOU_NAME_COLOR` (#fde68a), `youRingSparks(t, count, w, h)` — the YOU ring's orbiting sparks, dimmer on the far side |
| `environment/pixel-art.js` | Pure pixel-art generators for the sky dressing: `cloudPixels` (cumulus: broad low lobes on a flat shadowed base, overlapping billows along the top, shaded creases), `cloudBank` (3–4 clouds grouped, filling only empty cells so no seams), `forestLayout`/`treePixels` (pines, tall pines, round trees, bushes in clumps with clearings), `nextSkyEvent(first, rnd)` (first flock/shooting star 4–12s after load, then every 15–45s), `mountainProfile` (a few big peaks of varied height and width, a small midpoint-displacement fractal and a bounded ±3px walk for pixel-scale crags, slope-limited) + `mountainShader(sky, band)` (per peak: lit left face / shaded right face split along a spine that wanders and drifts down, the split between two peaks at the valley bottom with seams slanting, rock darkening toward the base, dithered mist rising from it, ragged snow on the high peaks; build it once per range — `mountainPixel` is the single-pixel form for tests). The ranges are drawn on a coarse grid (R = W/480 screen px per art pixel) and enlarged with nearest-neighbour: at 1px the dither aliased into stripes. Two ranges: `env-back` hazier and taller behind `env-far`; `faceTone` (the older per-column face picker) is kept for its tests, `hillProfile` (rolling hills + pine positions), `castlePixels` (shaded keep, towers, roofs, crenels, gate, flag; its tone-1 cells above the gate are the night windows), `BIRD_FRAMES` + `flockLayout` (a V of flapping birds). `environment/index.js` paints them (greyscale `TONES`, tinted per minute) |
| `hud/sync.js` | Pure `hudBox(canvasRect, mountRect) → {left,top,width,height}` — the HUD overlay's box, exactly the FIT canvas's rect relative to its mount; `keepHudOnCanvas(game, mount, hud)` wires it to `game.scale`'s `resize` event + a `ResizeObserver` on the mount, called once per game in `index.js`'s `bootBattlefield` and torn down on `game.events.once('destroy', ...)` |
| `hud/boss-plate.js` | Pure `crackedSegments(prevHp, nextHp, maxHp) → number[]` (10%-segment indices crossed downward, top first), `plateState(hp, max) → 'normal'|'enraged'|'finish'` (enraged ≤50%, finish inside (0%,10%], 0 itself stays enraged). `createBossPlate(root) → {set, spawn, hit}` — the DOM boss plate (name/number, segmented HP bar with a lagging ghost bar, crack + pixel-shard burst per segment, every hit also throws `hitChips(damage)` (2–8, by order of magnitude) small shards off the bar's live edge — the 10% burst alone fired once every few hundred hits on a real boss; an instant render (spawn, the intro's last frame) keeps the current segment as the crack mark so a reload never re-cracks segments already lost; a 3px shake on hits >3500 dmg via a *relative* transform + `composite:'add'`, never an absolute `translate(-50%,...)` which fights the plate's own grid centring). Wired once per game in `index.js`'s `bootBattlefield`: seeded from the boot payload (so a reload mid-fight renders that reading directly, no crack cascade), then kept live by the `BOSS_HP_TICK` bus event (`impact.js`'s HP counter `render`, replacing the Phaser HP bar/text it used to draw) and `BOSS_SPAWNED`/`hit` |
| `hud/board-view.js` | Pure `boardEffects(prev, next)` — diffs two `rankBoard` snapshots into rank-change rolls, climbs + who they passed, and a new-#1 flag, derived from `rank` alone (never trusts a snapshot's own `climbed`/`passedIds`); `createBoardView(root, {avatar, name, color, isYou, handles})` (`handles` = the boot leaderboard, plus `remember(id, handle)` from each hit's `slack_handle`, names a hitter with no fighter on the field instead of `#id`; names are set as text, never HTML; every row's share bar is coloured and sized from its first render) — the keyed `<li>` DOM view (style B always on: gold #1 shine + embers; Pulse: `hit(id, dmg)` flashes the edge + pops + floats `+N`; Overtake: climb streak + passed-row shudder + crown drop). Wired in `index.js`'s `bootBattlefield` — `rankBoard(prevRanks, scene.damageTotals)` re-renders on every `hit` through `boardHitHandler(view, render)`, which waits a microtask (the HUD hears a hit before the scene's `handleHit` has added it to `damageTotals`), and resets `prevRanks` on `boss-spawned` |
| `hud/index.js` | Alpine `battlefieldHud` component (registered on `Alpine.data` from `resources/js/app.js`, before Livewire starts Alpine) — the team panel, the viewer's own row, the portrait board-sheet toggle, the herald strip (`onKill`/`onSpawn` call `heraldFor` and show it 4.5s via `_showHerald`); `_pushZones()` reports `.bf-team`/`.bf-plate`/`.bf-board`/`.bf-herald`'s rects to `window.__battlefield.setHudZones` every 500ms and right after `toggleBoard()` (the feed is excluded — its lines are `pointer-events:none`). Pure exports: `teamReducer(state, hit)`, `youRow(state, me)`, `msUntilLocalMidnight(now, timeZone)` (today/month rollover at local midnight), `countTo(el, to, fmt?)` (420ms ease-out count-up). Never imports `bus.js`/Phaser — stays importable in a plain Vitest run |
| `shared/board.js` | Pure `rankBoard(prevRanks, damageById, limit) → {rows, total, more, leader}` — sorts fighters by damage (zero-damage excluded), each row gets its `share` of `total`, `climbed` places since `prevRanks` and the `passedIds` it overtook (for the Overtake effect); `more` counts the overflow past `limit`, `leader` is the top id or `null` on an empty board. Consumed by Tasks 17/20 |
| `config.js` → `config/` | `bosses.js` (`BG_COLOR`, `BOSS_TYPES`), `fighters.js` (`FIGHTER_TYPES`), `companions.js` (`BAT_CONFIG`, `NECROMANCER_CONFIG`, `MINION_TYPES`, `MINION_CLASH_EFFECTS`), `layouts.js` (`LAYOUTS`), `timings.js` (`TIMINGS`). `atlas-version.js` (`ATLAS_VERSION`) is **generated and gitignored** by `scripts/pack-sprites.js` — never hand-edit; not re-exported by the barrel, imported directly by `scene.js` and `character-preview/scene.js` |
| `layout.js` | Pure: `horizonYFor(layout)` (the line at the mountains' foot — the environment's horizon and the highest a fighter's feet may stand, via `move-geometry.js`), `computeFighterPositions`, `fighterDisplayConfig`, `damageScaleMultiplier`, `rowsNeeded`; `chargeFootY` is dead code (wrong ratio — don't use it for foot anchors) |
| `move-geometry.js` | Pure move-target geometry: `isValidMoveTarget`, `bypassY`, `clampMoveTarget`, `snapToValidTarget`, `planRoute`, `moveOrigin` — feet never above `horizonYFor(layout)` (a sky click snaps down onto the ground), boss/HP-bar column exclusion plus every live HUD zone passed in via `ctx.zones` (`scene._zones`, refreshed by `window.__battlefield.setHudZones` — the old hardcoded LEADERBOARD/DAMAGE_HUD rect constants are gone now that those are real DOM panels); size-aware margins. `planRoute` is shared by `move-input.js` (local) and `Fighter.handleFighterMoved` (remote echo) so all clients draw the same detour; both plan from `moveOrigin(...)`, never the raw sprite (a blade dash parks the sprite inside the boss column) |
| `fighter-placement.js` | Pure `resolveFighterPlacement(saved, gridPos, ctx)` — restore persisted position vs grid slot; snaps an invalid saved spot. Shared by `seedInitial` and `handleFighterJoined` |
| `hud-position.js` | Pure `computeHudTop(...)` — Damage HUD vertical offset clear of the nav pills; exposed on `window.__battlefield` |
| `resync.js` | Pure `driftedPositions(local, server, epsilon)` — repairs positions lost while Reverb was disconnected (no replay) |
| `render-scale.js` | Pure `canvasSizeFor(cssWidth, dpr, layout)` — canvas px + `renderScale` (camera zoom), 1×–2.5×. Boot and `applyModeChange` must both size through it (domain invariant #10) |
| `format.js` | Pure `formatHp` — K/M/B; exposed on `window.__battlefield` for the HUD |
| `attacks.js` → `attacks/` | `class Attacks` — dispatch per `AttackType` to `arrow.js`, `blade.js`, `blast.js`, `shuriken.js`, `slash.js`; shared `fx.js` (trails/bursts); pure `priest-heal.js` (`isHealRoll`) |
| `projectile.js` | `class Projectile` — projectile flight for every attack type |
| `projectile-textures.js` | Canvas-drawn projectile textures: `ensureSlashTexture`, `ensureShurikenTexture`, `ensureArrowTexture`, `ensureBladeTexture` (registered once, reused) |
| `spark-texture.js` | `ensureSparkTexture(scene)` — shared particle texture (`TextureKey.SPARK`); `ensureSparkStreakTexture(scene)` — 14×3 canvas, transparent tail fading to a white head (`TextureKey.SPARK_STREAK`), tinted per-emit by `charge.js`'s heel sparks and the flair burst's streaks; `ensurePuffTexture(scene)` (`TextureKey.PUFF`, a soft white circle); `ensureClawdTextures(scene)` — bakes `clawd-default`/`clawd-crouch`/`clawd-arms` from `shared/clawd.js`'s `poseRects` (crouch = default baked one row lower, not a separate pose) for `minions.js`'s poof; `ensureSoftGlowTexture(scene)` (`TextureKey.SOFTGLOW`, 64×64 radial gradient, tinted per-use); `ensureMoteSoftTexture(scene)` (`TextureKey.MOTE_SOFT`, a tiny plus-shaped 4×4, tinted per-emit — brazier fire + dust/fireflies); `createFlairGlyphCache(scene)` — the Phaser adapter for `shared/flair-glyphs.js`'s `createGlyphCache`: bakes each unique (char, color, size) once as "Pixelify Sans" fill + 3px `#0b0716` stroke + a 2px hard drop shadow (`darkenHex(color, 0.55)`), fixing the ~20-Text-object-per-flair freeze |
| `impact.js` | Pure `createTextPool(make) → {take, release, size}` (exported for tests) — reused instead of created/destroyed per hit. `class Impact` — damage popup (from `this.damagePool`; the current user's own hit is gold/larger via `apply`'s 3rd `userId` param), hit flash, boss flinch (tracks its own rest scale — never baseline on the current scale) |
| `charge.js` | `class Charge` — charging ring + activity bubble (`scene.charges`). The old per-fighter fire/ember emitters and green speed-trail are gone; `static sparkTints(chargeColors)` (white-hot + the fighter's own brightest colours, never hard-coded green) and `emitFor(fighter, dt, rnd?)` (called from `scene.js`'s `update()` for every charging fighter) emit onto the one scene-wide `scene.sparks` emitter at the fighter's own rear heel (`shared/heel.js`'s `heelToLocal`, cached per fighter type on `ftype.heel` by `fighter/index.js`'s `cacheHeel`), timed by `shared/sparks.js`'s `nextBurst`. Skipped entirely when `scene.reducedMotion` (read once at boot) |
| `bubble.js` | `class Bubble` — activity bubble + hover tooltip. The tooltip's damage/rank now come from `rankBoard(new Map(), scene.damageTotals, Infinity)` (Task 12) instead of the removed `scene.leaderboard.damageFor`/`rankOf` |
| `move-input.js` | `class MoveInput` — click-to-move for the viewer's own fighter, chevron, ripple; a click on any DOM HUD panel never reaches the canvas at all (`pointer-events:auto`), so no panel-hover check is needed here any more |
| `ceremony.js` | Pure `ceremonyTimeline({reduced}) → {slowMs, timeScale, cardMs, countdown, stepMs}`; `runCeremony(scene, {killer, bossName, bossNumber}) → Promise<void>` — slow-mo + shake, the killer's card, then a 3-2-1 count, restoring `tweens.timeScale`/`anims.globalTimeScale` in a `finally` even if a step throws; `createSpawnGate() → {hold, open, busy}` — holds a boss spawn (and its first hits' visuals) that arrives mid-ceremony. Called from `boss/index.js`'s `handleBossKilled`, replacing the old Phaser MVP card |
| `boss.js` → `boss/` | `class Boss` — spawn/kill, patrol, react anims, HP bar, `static bossTypeFor`; only the current + one-ahead boss type are ever loaded (every sheet must fit a 4096px GPU texture) — `preloadNextType(number)` queues the type after `number` in the background (called from `create()` and again from `handleBossSpawned`), `_awaitBossTypeReady(bossType, onReady)` defers `_spawnNewBossSprite` until every texture key exists (`filecomplete-spritesheet-<key>`) so a spawn never shows the previous type's sprite under the new key, `_applyBossFilter` sets NEAREST only on keys that exist; pure `queueBossLoad`/`bossTextureKeys` (also used by scene.js's `preload()` for the boot boss); `dreadknight.js` (turn-based patrol for `boss-abyssal-dreadknight`); `stun.js` (visual-only); `bats.js` (`class BatSwarm`); pure `bat-targeting.js` (`computeBatHitTarget`, `BAT_HP_THRESHOLDS`), `bat-wander.js` (`randomWanderPoint`, shared with necromancer + minions), `summon-queue.js` (`shouldSkipSummonFlourish`, `SUMMON_QUEUE_BURST_LIMIT`) |
| `necromancer.js` | `class Necromancer` — permanent join-ceremony fixture; `spawnSummonCircle(x, y, scale = 2.6)` reused by minions |
| `minions.js` | `class Minions` — per-fighter subagent swarm (`FighterAgentCountChanged` / `FighterAgentToolUsed`). `POOF_SEQUENCE` (exported) — the Clawd crouch-with-dust/arms-up frames `_spawnOne` plays (`_playClawdPoof`, 70ms/frame) before a minion rises in, then a 10-puff burst (`_spawnPuffBurst`); skipped entirely under `scene.reducedMotion`. Behaviour spec: `.ai/domain/battlefield.md` Companions → Minions |
| `minion-fight.js` | Pure `countSidesNear`, `pickAttackerSide`, `travelLanding`, `pickRandom`, `fidgetAttacks`, `flipToFace`, `flipToFaceAngle` |
| `minion-trail.js` | Pure `sampleTrail`, `pushTrailSample` — follow-the-leader replay |
| `minion-layout.js` | Pure `homeSlotOffset`, `homeSlotAngle`, `shortestAngleDelta`, `isInFrontOfFighter` |
| `minion-grouping.js` | Pure `isNear`, `clusterAngles`, `computeZones`, `zoneFanOffset` — gathering zones |
| `fighter.js` → `fighter/` | `class Fighter` — lifecycle, hits, moves, flair ring; `updateCharacters(roster, {animate})` re-skins a boss change's whole field at once, but a `CHARACTER_CHANGED` equip (`animate: true`, skipped under `scene.reducedMotion`) plays the old fighter's death → a necromancer summon circle → the new fighter's summon → idle; the YOU ring (a flat gold ellipse under the feet, breathing ±7%, `Number(fighter.id) === Number(scene.currentUserId)` only) marks the viewer's own fighter; the name plate (`NAME_PLATE_STYLE`/`NAME_PLATE_FONT_PX`, exported) is a dark pill under the feet matching the DOM HUD's own look, fixed 12px regardless of fighter size, hidden when `fighterDisplayConfig(...).showHandle` is false; `handleHit` counts every hitter's damage toward `scene.damageTotals` even with no live fighter on the field (the visual grow/rescale stays gated on one); `cacheHeel` reads a fighter type's idle-frame-0 alpha once (`ftype.heel`, shared across every instance of that type) for `charge.js`'s heel sparks; the flair orbit ring (`startFlairRing`/`updateFlairRing`/`stopFlairRing`) is baked-texture `Image`s from `scene.flairGlyphCache` (one dim ghost `Image` per glyph, not the old Circle trail) positioned via `flair.js`'s pure `glyphState`; `burstFlair` is a small soft-glow + expanding-ring + streaks + `✦` pop, replacing the old 95px disc + 12 spokes; `avatar.js` (texture load + loading/failed fallbacks); `animations.js` (`registerFighterAnimations` / `registerAllFighterAnimations` — atlas anims, idempotent, derives `<key>-summon` from reversed death when absent); pure `preview.js` (`centeredScaleFit`, `drawFighterPreview`); pure `flair.js` (now including `glyphState`); `flair-font.js` (the ring's webfont — "Pixelify Sans", matching the DOM HUD, not the old Chakra Petch — + load gate; the Filament admin flair preview was not updated to match and may show a different face until it is) |
| `character-preview/` | Separate mini Phaser app that used to drive the fighter sheet's Character tab — **no longer mounted**: the tab is now the mockup's CSS sprite-strip stage (`sheet/character-stage.js`); still exposed on `window.__battlefield` (`createCharacterPreview` & co.) pending a decision to remove it: `game.js` (sizes from its mount element via `Scale.FIT` + a `ResizeObserver`, not a fixed 260px — sizing while hidden reads a 0×0 box, skipped), `scene.js` (loads the atlas itself; `stageScale(stageW, stageH, bodyH)`/`avatarFor(scale)` pure + tested size the fighter/avatar from the stage's own box; a fixed 17:30-local sky backdrop via `environment/sky-layer.js`'s `skyFrame`; real heel-tracked grinding sparks during the walk skill, same `shared/heel.js`+`shared/sparks.js` the live battlefield uses), `attack-labels.js`, `moveset.js`, `skill-loop.js`, `modal-fit.js` (pure `loadoutLayout`/`fitScale` — picks the panel's split vs stacked layout by viewport width and uniformly scales the fixed-size split card to fit, so zoom/DevTools/window size never overflow it; exposed on `window.__battlefield`), `scroll-thumb.js` (pure `thumbGeometry`/`scrollTopForThumb` — the roster's hand-drawn scrollbar; Chrome can't transition `::-webkit-scrollbar`, so the native bar is hidden and this thumb eases 2→4→8px like Firefox's overlay bar; also exposed on `window.__battlefield`) |
| `sheet/` | The fighter sheet's own JS (`App\Livewire\FighterSheet`, `resources/views/livewire/fighter-sheet*`). The markup and CSS (`resources/css/fighter-sheet.css`, every rule scoped under `.fs`, keyframes prefixed `fs-`) are a **verbatim port of the approved mockup** `docs/superpowers/mockups/2026-09-27-battlefield-redesign/fighter-sheet.html` — change the mockup's DOM/classes only deliberately, and port its behaviour rather than re-deriving it. Modules: `sheet-roster.js` (generated from the mockup: `ROSTER` — per fighter its attack type, accent and each animation's frames/rate/source strip file — plus `BOX` measured body boxes, `LABELS` move names, `TYPE_LINE`; `stripUrl(file)` resolves a strip under `resources/assets/battlefield/fighters/` through `import.meta.glob`, so Vite serves the real source strips); `sprite-strip.js` (the mockup's `strip`/`paint`/`frameIn` CSS background-step sprite player on plain `<span>`s, `standOn`, `moves(key)` hotbar list, `pretty`, `faceColor(userId)`, `chargePalette`/`paintCharge` from `config/fighters.js`'s `chargeColors`); `stage-sparks.js` (`sparkField(host, heel, scale) → stop` canvas heel sparks + `heelOf(key)`); `clawd-kit.js` (the mockup's ClawdKit: `draw`/`play`/`SEQ`/`ENTRANCES`/`ON_CLICK`, cells classed `b`/`e` for the CSS); `clawd-buddy.js` (`spotsFor(cols)`, `createBuddy(panel, {onComboChange}) → {onHit, onKill, destroy, combo}` — the mockup's live Clawd drawn into the panel's `.clawd.big`); `mini-stage.js` (`miniStage` Alpine component: the equipped fighter walking in its charge colours, one minion + crew Clawd per live subagent via `minionSpots(total, busy)` — busy = in a tool call right now, from `FIGHTER_AGENT_TOOL_USED` (seeded from the scene's own minions' `toolRing`), total from `FIGHTER_AGENT_COUNT_CHANGED`; the server can't tell busy from idle, so `SubagentCountCache::countFor()['busy']` is really the total; replays the crew entrance whenever the Profile tab re-activates); `sheet-ui.js` (pure `probeLabel`, `easeCount`, `agoLabel`, `crewReducer`/`crewCounts`, `bestComboKey`, `rovingIndex`); `account-card.js` (`accountCard(accountId)` — a card's own ↻: `$wire.refreshAccount` → `App\Services\Profile\AccountRefresh`, stamp "checking…" → "42% → 45%"/"failed", a minute's countdown); `pickers.js` (`periodTabs` — roving ←/→ that picks, Enter/Space opens More, Escape closes it, last period in `localStorage['ts:profile-period']`; `modelPicker` — ↑/↓/Enter/Escape); `character-stage.js` (`characterStage` Alpine component → `mountCharacterStage(root, opts)`: roster slots with hover peek and teammate faces, the summon/attack/death stage FX against a boss, hotbar with 1–9 keys, Equip → undo toast, `fighter-equipped` after 900ms (the sheet folds and closes, camera pushes in), then `$wire.equip(key)` at 2200ms so every client's field plays the swap in view — Undo before that sends nothing; the `$wire` is captured at init because the sheet is gone by then, and the toast host sits outside the sheet's `@if`); `index.js` (`createSheetShell` — tab switching, Escape, focus trap; `fighterSheetShell` — shell + refresh cooldown + the eased count-up on `#dmg` (live hits, and every re-render via its `data-value`) + the kills-chip bump on the viewer's own kill + `data-input` mouse/keyboard tracking for focus rings + `equipTransition()` folding the sheet and calling `window.__battlefield.focusFighter` on `fighter-equipped` + `sheet-open-tab`; `sparkChart` — the "Last 24 hours" readout; `agoTicker` — the header's ticking refresh stamp from `data-since`; `clawdBuddyPanel` — best combo kept per local day in `localStorage`); `hourly-bars.js` (pure `barHeights`, `readout`, `cooldownLabel`, `liveTokens`, `compact` — the JS twin of `App\Support\CompactNumber`); `character-tab.js` (pure `accentFor`, `filterRoster`, no longer used by the view). Every Alpine component is registered from `resources/js/app.js` — a `<script>` inserted by a Livewire morph never executes |

## Test Files
| Test | What it covers |
|---|---|
| `tests/js/layout.test.js` | computeFighterPositions, fighterDisplayConfig, damageScaleMultiplier, chargeFootY, rowsNeeded |
| `tests/js/move-geometry.test.js` | isValidMoveTarget (edges, boss column, live HUD zones + their action-bubble padding), bypassY, clampMoveTarget, snapToValidTarget, planRoute, moveOrigin |
| `tests/js/fighter-placement.test.js` | resolveFighterPlacement — restore, snap-instead-of-reset, grid fallback |
| `tests/js/resync.test.js` | driftedPositions — tolerance, skips mid-waypoint and absent fighters |
| `tests/js/hud-position.test.js` | computeHudTop — nav clearance, letterboxing, offset parent, no-nav (IDE embed) |
| `tests/js/render-scale.test.js` | canvasSizeFor — width×dpr, 1× floor, 2.5× cap, portrait |
| `tests/js/format.test.js` | formatHp tiers |
| `tests/js/shared/hp-counter.test.js` | createHpCounter — retarget stops the running tween, retargets from the shown value, reset shows immediately, render only fires on a rounded-value change |
| `tests/js/shared/depth.test.js` | setDepthIfChanged — skips the setter when unchanged, writes through on a real change |
| `tests/js/shared/bus-bindings.test.js` | bindBus — unbind removes every handler, idempotent on a second call |
| `tests/js/shared/flair-glyphs.test.js` | createGlyphCache/glyphKey/prewarm — each unique glyph baked once, keys differ by colour/size, spaces never baked |
| `tests/js/shared/heel.test.js` | findHeel — rear-most heel pixel above the feet ignoring an out-of-box spear tip, empty-frame fallback; heelToLocal mirrors dx by facing |
| `tests/js/shared/sparks.test.js` | sparkAngle range by facing, nextBurst wait/count timing standing vs moving, SPARK_EMITTER shape |
| `tests/js/shared/sky.test.js` | sunPosition elevation/azimuth at a real place (Hanoi) and time, sunrise window, moonPhase against real eclipse dates, skyColorsAt/ambientAt gradient stops, mixHex, skyXY rise/transit/set mapping and June/December solstice monotonic arcs |
| `tests/js/shared/clawd.test.js` | zoneOf 3×2 grid, clawdStep hop/flip/stay by column distance and never `arms-up`, poseRects default-pose eye cells, SEQUENCES.jump shape/timing |
| `tests/js/shared/feed-merge.test.js` | createFeed — same-actor fold with count, crowd fold across actors, 3-line cap with `earlier` overflow, 7s age-out, 12-event history newest-first |
| `tests/js/shared/hud-zones.test.js` | domToWorld canvas-scale conversion, resolveSpacing pushes overlapping footprints apart and out of a HUD zone, no-op when there's room |
| `tests/js/shared/camera-focus.test.js` | focusPlan — centers on the fighter's position at a 1.6x zoom, null for a missing fighter |
| `tests/js/shared/board.test.js` | rankBoard — rank/share ordering, climbed places + passedIds, zero-damage exclusion + overflow `more`, empty-board leader/NaN guard |
| `tests/js/shared/scene-ready.test.js` | onSceneReady — renders once the scene is created (the F5 empty-board bug), at once when already up and again after a restart, waits for the game's ready when the scene isn't there yet |
| `tests/js/hud/intro.test.js` | boss plate `intro()` fills from empty to the current HP with the number counting; countTo's own duration; revealHud staggers and marks ready |
| `tests/js/bubble-y.test.js` | bubbleCenterY clears the avatar at normal size and on a damage-grown fighter |
| `tests/js/managers/Bubble.test.js` | an activity bubble exposes `height()` (the frame loop reads it) |
| `tests/js/fighter/name-ring.test.js` | outlined name with no frame, above the fighter depth, your own in gold; ring sparks orbit the ellipse, dimmer behind |
| `tests/js/environment/mountains-v3.test.js` | jagged ridge (crags on the big slopes), whole band used without clipping, snow only on high ground with a ragged edge, lit face lighter than shadow, mist pale at the base, dithered face split |
| `tests/js/environment/pixel-art-v2.test.js` | billowing cloud tops and multi-lobed banks, varied mountain peaks, stripe-free faces on a rocky skyline, a mixed clumped forest on the hill line, distinct tree shapes, sky-event timing |
| `tests/js/fighter/avatar-stack.test.js` | per-fighter avatar height; the avatar clears every fighter's head, close above it |
| `tests/js/bubble-fit.test.js` | the action bubble stays compact at any fighter size; two-line word wrap with an ellipsis |
| `tests/js/environment/pixel-art.test.js` | rounded shaded clouds, mountains that slope (no long flats or cliffs) with several peaks, no striping from 1px wobbles, hills with trees on the crest, castle towers above its wall, two bird frames, a V flock |
| `tests/js/hud/sync.test.js` | hudBox — FIT canvas rect relative to the mount, letterboxed offsets |
| `tests/js/hud/boss-plate.test.js` | crackedSegments — cracked-segment indices top-first across a range, no-crack when the drop stays inside one segment; plateState — normal/enraged/finish thresholds, 0 hp stays enraged |
| `tests/js/hud/feed-view.test.js` | feedText — ×N for one repeating actor, "a, b +N" for several, singular phrasing; heraldFor — kill/spawn text shape |
| `tests/js/hud/board-view.test.js` | boardEffects — rank-change roll direction, new-leader-once, climb + who it passed, collapsed rows past the limit never roll |
| `tests/js/hud/board-hit.test.js` | a first hit gets its row and a compact `+N` even though the scene counts the damage after the HUD hears it |
| `tests/js/hud/team-flash.test.js` | a hit flashes all three team totals |
| `tests/js/hud/team.test.js` | teamReducer — hit folds into every window + the board; youRow — damage/share/ordinal rank, zero-damage no-NaN dash; msUntilLocalMidnight — countdown to the next Asia/Ho_Chi_Minh midnight |
| `tests/js/snapshot.test.js` | snapshotState — boss/leaderboard/fighters, character, damageTotals, charging, currentUserId, normalized position, agentCount, the sky site survives a round-trip |
| `tests/js/environment/sky-layer.test.js` | skyFrame — sun visible/high at noon and no stars, sun hidden and stars at full strength at midnight, a warm twilight weight at dusk |
| `tests/js/environment/dressing.test.js` | dressingLayout — brazier/mote placement derives from the world box in both landscape and portrait |
| `tests/js/constants.test.js` | Full BusEvent / TextureKey lists, SCENE_KEY — update when adding an event/texture key |
| `tests/js/config.test.js` | FIGHTER_TYPES schema + exact key order (= PHP `FighterCharacter`) + atlas frames; BOSS_TYPES files/frame grids/looping idle+move; BAT/NECROMANCER strips; MINION_TYPES: exactly 5, strips on disk, one frame size per type, attacks one-shot with hitFrame/travel inside the strip, reaction ≥ 1800ms; LAYOUTS companion zones |
| `tests/js/boss-assets.test.js` | Every PNG under `public/assets/battlefield/bosses` (recursive) fits a 4096px GPU texture — reads the PNG header directly, no Phaser |
| `tests/js/ceremony.test.js` | ceremonyTimeline — reduced-motion drops slow-mo/shake but keeps the card + count; createSpawnGate — holds while busy, runs at once otherwise, flushes in order on open; runCeremony — time scales restored even when a step throws |
| `tests/js/minion-poof.test.js` | POOF_SEQUENCE shape (crouch-with-dust then arms-up); `_spawnOne` never creates a Clawd pose texture under `scene.reducedMotion` |
| `tests/js/pack-sprites.test.js` | Runs `scripts/pack-sprites.js`: atlas PNG/JSON exist, frame names, ≤4096 wide, ATLAS_VERSION content hash (skipped when source strips are absent) |
| `tests/js/minion-fight.test.js` | Area counting, weighted attacker roll, landing point, fidget filter, facing |
| `tests/js/minion-trail.test.js` | sampleTrail interpolation/clamping, history trimming |
| `tests/js/minion-layout.test.js` | Even slots, shortest angle delta, front/back predicate |
| `tests/js/minion-grouping.test.js` | isNear, clusterAngles, computeZones, zoneFanOffset |
| `tests/js/flair.test.js` | Flair state/ring-layout helpers; `glyphState` — front/back scale+alpha, intro fly-in from centre, outro drift+fade, reduced-motion full-alpha-no-wave |
| `tests/js/fighter-preview.test.js` | centeredScaleFit, drawFighterPreview |
| `tests/js/boss/bat-targeting.test.js` | computeBatHitTarget — cosmetic routing, threshold-kill override |
| `tests/js/boss/bat-wander.test.js` | randomWanderPoint — uniform elliptical sampling |
| `tests/js/boss/summon-queue.test.js` | shouldSkipSummonFlourish burst limit |
| `tests/js/attacks/priest-heal.test.js` | isHealRoll parity |
| `tests/js/character-preview/attack-labels.test.js` | getAttackLabel + fallback |
| `tests/js/character-preview/moveset.test.js` | buildMoveset incl. reversed Summon-from-Death |
| `tests/js/character-preview/skill-loop.test.js` | createSkillLoop — looping vs one-shot, token cancel |
| `tests/js/character-preview/modal-fit.test.js` | loadoutLayout breakpoint; fitScale — never upscales, tighter axis wins, zoom levels fit, zero-size guard |
| `tests/js/character-preview/scroll-thumb.test.js` | thumbGeometry — no thumb when content fits, size/offset from scroll, min size, overscroll clamp; scrollTopForThumb inverse + clamping |
| `tests/js/character-preview/stage.test.js` | stageScale — sized from the stage's own box, smaller on a phone stage; avatarFor — battlefield proportions |
| `tests/js/sheet/hourly-bars.test.js` | barHeights — scale to the busiest hour with a 5% floor; readout — total until a bar is pinned; cooldownLabel; liveTokens — only the viewer's own hits move the ledger; compact — matches the mockup's fmt() and the PHP CompactNumber |
| `tests/js/sheet/index.test.js` | createSheetShell — Escape closes and returns focus to the opener, tab click/arrow-key switching, Tab wraps at the last focusable element (focus trap); equipTransition folds `.sheet`; clawdBuddyPanel — combo/bestToday update via onComboChange |
| `tests/js/sheet/clawd-buddy.test.js` | spotsFor — three spots span the panel; createBuddy — mounts/tears down an svg, onHit pops damage text, onKill celebrates; combo — exposed live and via onComboChange on every change including the window-expiry reset, a hit after the window restarts the streak at 1 not 0-then-1 |
| `tests/js/sheet/clawd-kit.test.js` | draw — body/eye cells, x shift of two svg units per column, crouch frames puff dust |
| `tests/js/sheet/sheet-roster.test.js` | the sheet roster covers every `FIGHTER_TYPES` key with a body box; every named strip resolves to a served file |
| `tests/js/sheet/sprite-strip.test.js` | moves — hotbar order and attack names by type, summon = reversed death; frameIn scale/centre; pretty; chargePalette from the fighter config; faceColor stable per user |
| `tests/js/sheet/mini-stage.test.js` | minionSpots — one per busy subagent alternating sides, none at zero, capped |
| `tests/js/sheet/character-stage.test.js` | mountCharacterStage — 20 slots with YOU on the equipped one, teammate faces, pick → Equip persists via `equip(key)` and dispatches `fighter-equipped` after 900ms, style chip filters and recounts |
| `tests/js/sheet/sheet-ui.test.js` | probeLabel, easeCount, agoLabel, crew busy/idle reducer, per-day best-combo key, rovingIndex |
| `tests/js/sheet/account-card.test.js` | accountCard — changed/failed/cooldown outcomes, meters re-rendered, minute countdown |
| `tests/js/sheet/pickers.test.js` | periodTabs — arrow roving picks, More opens on Enter and closes on Escape with focus back, remembered period, keys inside the date inputs ignored; modelPicker keyboard |
| `tests/js/sheet/fighter-sheet-css.test.js` | no rule mixes `translate` with `transform` (Lightning CSS drops it); the phone sheet is full width |
| `tests/js/sheet/character-tab.test.js` | filterRoster — "all" filter returns everything, an attack type returns only matching entries, an unmatched type returns empty |
| `tests/js/managers/Boss.test.js` | hpBarColor, bossLabel, bossTypeFor, stun cooldown, dreadknight turn sequence, bossTextureKeys (single-sheet vs multi-file), queueBossLoad (queues only missing keys, no-op when already loaded) (Phaser stubbed) |
| `tests/js/scene.test.js` | `BattlefieldScene#preload`'s `load.once('complete', …)` boot-only handler runs exactly once across repeated loader completions (fake `load` modeling Phaser's real once/on EventEmitter contract) |
| `tests/js/managers/Charge.test.js` | chargeParticleColors |
| `tests/js/managers/Fighter.test.js` | fighterRestScale; handleFighterMoved/handleHit with a fake scene — a mid-dash move/hit never records the boss column as home |
| `tests/js/managers/Impact.test.js` | Boss flinch around the rest scale under overlapping hits (fake scene); BOSS_HP_TICK bus emit |
| `tests/js/impact-pool.test.js` | createTextPool — a released item is reused by the next take(), make() only called once |
