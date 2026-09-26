# Battlefield JS Conventions

## Architecture
- `scene.js` is the coordinator (~250 lines). Domain logic lives in sibling `.js` files.
- Each manager is an ES6 class receiving `scene` in its constructor.
- Shared state (`scene.fighters`, `scene.charges`, `scene.layout`) stays on `scene` as single source of truth.
- Cross-manager calls go through `this.scene.xyz.method()`.
- Import via `@battlefield/...` alias — no relative `../../` paths.

## Manager Wiring Order in `scene.create()`
Managers are always instantiated BEFORE initial state is seeded and BEFORE bus handlers are registered.
Cross-manager calls work because all managers exist on `scene` when any event fires.

## Entry Object Shape
Fighter entry object (lives in `scene.fighters: Map<userId, entry>`):
```
{
  id,                    // user ID
  sprite,                // Phaser.GameObjects.Container
  body,                  // Phaser.GameObjects.Sprite (the animated character)
  head,                  // Phaser.GameObjects.Image (avatar bubble)
  handle,                // Phaser.GameObjects.Text | null (username label)
  handleText,            // string (raw untruncated display name)
  pos: { x, y },         // last known logical position
  baseSize,              // displaySize at creation
  displaySize,           // current logical size in px
  avatarSize,            // avatar image size in px
  avatarUrl,             // avatar image URL, or null/undefined for fallback texture
  legH,                  // pixels from container center to foot
  ftype,                 // fighter type config object from FIGHTER_TYPES
  damageScale,           // float multiplier from accumulated damage
  animState,             // AnimState enum value
  isStunned,             // boolean — reserved for movement-lock stun; always false today (stun is visual-only, see boss/stun.js)
  lastStunAt,            // timestamp of last stun (ms) — drives star-orbit effect cooldown only
  waypointMoving,        // boolean — local waypoint animation in progress
  hasCustomPosition,     // boolean — true once fighter has moved (click-to-move or restored from persisted position); relayoutFighters() must not grid-snap these
  rescaleTween,          // active tween or null
  flairState,            // {flair, expiresAt} from fighter/flair.js, or undefined before the first flair hit
  flairColor,            // hex string for the active flair's orbit ring/burst, or null when no flair is active
  flairRing,             // Array<{ch, phase, glowBack, text: Phaser.GameObjects.Text, trail: Phaser.GameObjects.Arc[]}> | null — the orbiting name-ring glyphs + their comet-trail dots; glowBack tracks the last front/back side so glow (setShadow) only re-fires on an actual transition
  flairSparkles,         // Array<{text, phase, speed, sizeScale}> | null — independently-twinkling sparkles orbiting wider than the ring
  flairRingTicker,       // Phaser.Time.TimerEvent | null — drives updateFlairRing() every 16ms while flairRing is active
  flairAngle,            // radians — the ring's current rotation, advanced each tick by updateFlairRing()
  flairLastBurstAt,      // timestamp (ms) of the last triggering hit — drives the post-hit spin-up (flair.js's spinMultiplier)
  flairBurstAt,          // timestamp (ms) of the last burstFlair() call — debounces re-bursting within 300ms
  flairTimer,            // Phaser.Time.TimerEvent | null — fires destroyFlair() when the flair expires
}
```

## JSDoc Convention (JS — Google style, NOT PHP rules)
Follows [Google JavaScript Style Guide §7](https://google.github.io/styleguide/jsguide.html#jsdoc).

- **`@return`** not `@returns` (Google always uses singular)
- **Types always in braces**: `{number}`, `{string}`, `{Function|null}`, `{Array<{x: number, y: number}>}`
- **`@return` can be omitted** only when there is no non-empty `return` statement
- **Method descriptions** start with a third-person verb phrase: "Returns …", "Spawns …", "Triggers …"

### By location
| Location | JSDoc required |
|---|---|
| Manager classes | `/** One-line description. */` on the class |
| Public manager methods | Full block: description + `@param` per arg + `@return` |
| Private methods (`_` prefix) | Full block: description + `@param` per arg + `@return` |
| Event handlers (`handleXxx`) | `@param {object} payload` with shape inline if non-obvious |
| Module-level utility functions | Full block: description + `@param` per arg + `@return` |
| No-param void methods | Description only; omit `@param` and `@return` |

- PHP DocBlock rules do NOT apply to JS files

## Testing Pattern
- Only pure functions are unit-tested (vitest). No Phaser mocking.
- Write the failing test first (TDD), extract the pure function, then implement.
- Test files: `tests/js/managers/Xxx.test.js`
- Phaser-coupled manager methods are integration-tested manually.

## Constants
- Use `AnimState`, `AttackType`, `TextureKey`, `BusEvent`, `SCENE_KEY`, `BossPhase`, `DreadknightAttack` from `constants.js`.
- Never use magic strings (`'idle'`, `'attack'`, `'walk'`) directly in logic.

## Key Files

Some managers are a single file; others are a thin barrel (`x.js` → `export * from './x/index.js'`) plus a submodule directory, so the class can be split across focused files. Always import via the barrel path (`@battlefield/boss.js`), never reach into the submodule directly.

| File | Responsibility |
|---|---|
| `constants.js` | AnimState, AttackType, TextureKey, BusEvent, SCENE_KEY, BossPhase, DreadknightAttack enums |
| `config.js` → `config/` | Barrel over `bosses.js` (BOSS_TYPES), `fighters.js` (FIGHTER_TYPES), `companions.js` (BAT_CONFIG, NECROMANCER_CONFIG, MINION_TYPES — direct per-animation spritesheets, not the shared fighter atlas), `layouts.js` (LAYOUTS), `timings.js` (TIMINGS), `atlas-version.js` (ATLAS_VERSION — bump whenever the fighter atlas PNG/JSON changes, see `.ai/domain/battlefield.md` #8) |
| `layout.js` | Pure position helpers: computeFighterPositions, fighterDisplayConfig, damageScaleMultiplier, chargeFootY, rowsNeeded |
| `move-geometry.js` | Pure move-target geometry: isValidMoveTarget, bypassY, clampMoveTarget, snapToValidTarget, isInsideLeaderboardPanel, planRoute, moveOrigin — boss/HP-bar column, leaderboard, and Damage HUD exclusion zones (both fixed: leaderboard top-right, Damage HUD top-left, in both orientations); size-aware edge margins. `planRoute` is shared by `move-input.js` (mover's local animation) and `fighter/index.js`'s `handleFighterMoved` (remote echo) so every client renders the same detour. Both plan from `moveOrigin(sprite, entry.pos, ctx)`, never the raw sprite: a melee dash (blade) parks the sprite inside the boss column, and a move that kills that dash mid-flight would otherwise plan from a blocked origin and strand the fighter on the boss |
| `fighter-placement.js` | Pure entry placement: `resolveFighterPlacement(saved, gridPos, ctx)` — decides whether a fighter entering the scene (boot payload or `FighterJoined` echo) restores its persisted position or takes the default grid slot; snaps a no-longer-valid saved position instead of resetting it. Shared by `seedInitial` and `handleFighterJoined` so both entry paths place a fighter identically |
| `hud-position.js` | Pure `computeHudTop({navBottom, canvasTop, parentTop, scale})` — vertical offset for the HTML Damage HUD so it never collides with the top-left nav stack (Profile/Dashboard pills) in either orientation. Exposed on `window.__battlefield` by `index.js` because the HUD's Alpine code lives in a plain inline `<script>` in `battlefield.blade.php`, not a module |
| `resync.js` | Pure `driftedPositions(localFighters, serverPositions, epsilon)` — diffs the client's local fighter positions against the server-authoritative snapshot returned by `Battlefield::resync()`, used after an Echo reconnect to repair positions lost to Reverb's lack of event replay |
| `render-scale.js` | Pure `canvasSizeFor(availableCssWidth, dpr, layout)` — canvas pixel size + renderScale (camera zoom). Both `bootGame` and the orientation-flip `applyModeChange` in `index.js` must size through it, or the camera zoom and canvas size disagree and the world renders zoomed in |
| `format.js` | Pure format helpers: formatHp — exposed on `window.__battlefield` by `index.js` so the Damage HUD's inline `<script>` in `battlefield.blade.php` calls it directly instead of duplicating the logic |
| `snapshot.js` | Snapshot/restore scene state on orientation change |
| `bus.js` | Tiny event bus for cross-manager communication |
| `index.js` | Entry point: bootBattlefield, detectMode, Echo wiring |
| `scene.js` | Phaser scene coordinator (~250 lines); instantiates all managers |
| `attacks.js` → `attacks/` | `class Attacks` (`index.js`) — dispatch/play all attack types; one module per type (`arrow.js`, `blade.js`, `blast.js`, `shuriken.js`, `slash.js`) plus shared `fx.js` (trail/burst effects); `priest-heal.js` (pure `isHealRoll(damage)` — decides Priest's cosmetic heal-vs-burst flourish, see `.ai/domain/battlefield.md` Companions) |
| `leaderboard.js` → `leaderboard/` | `class Leaderboard` (`index.js`) — TOP DAMAGE panel; `static abbreviateDamage`, `static showMvpCard`; `doom-fire.js` (per-character DOOM fire effect), `mvp.js` (post-kill MVP card) |
| `charge.js` | `class Charge` — charge ring, trail, fire emitters |
| `bubble.js` | `class Bubble` — activity bubble + hover tooltip |
| `move-input.js` | `class MoveInput` — click-to-move routing (delegates geometry to `move-geometry.js`), chevron, ripple; clicking the TOP DAMAGE panel is a no-op (cursor + input both ignore it) |
| `projectile.js` | `class Projectile` — all projectile types (slash, blast, shuriken, arrow, blade) |
| `projectile-textures.js` | Canvas-drawn one-off projectile textures (e.g. `ensureSlashTexture`), registered into the scene's texture manager once and reused |
| `spark-texture.js` | Pure `ensureSparkTexture(scene)` — the shared particle-trail texture every projectile type uses |
| `impact.js` | `class Impact` — damage popup, hit flash effects, boss flinch. The flinch tracks its own running tween + rest scale and snaps back before re-flinching: baselining on the boss's current scale ratchets it flatter under overlapping hits |
| `boss.js` → `boss/` | `class Boss` (`index.js`) — boss patrol, react animations, HP bar; `dreadknight.js` (abyssal-dreadknight deterministic turn-based patrol); `stun.js` (visual-only stun effect); `bats.js` (`class BatSwarm` — the per-boss-fight bat minions); `bat-targeting.js` (pure `computeBatHitTarget` — cosmetic hit-routing + threshold-kill logic); `bat-wander.js` (pure `randomWanderPoint`, shared with `necromancer.js` and `minions.js`); `summon-queue.js` (pure `shouldSkipSummonFlourish` — burst-limit backstop for `necromancer.js`'s summon queue) |
| `necromancer.js` | `class Necromancer` — the permanent summon-fixture; `spawnSummonCircle(x, y, scale = 2.6)` also reused (smaller scale) by `minions.js`'s own spawn effect; see `.ai/domain/battlefield.md` Companions |
| `minions.js` | `class Minions` — per-fighter subagent-count minion swarm (`demon-a`/`blood-monster-a`), independent world-space sprites repositioned each frame from `scene.js`'s `update()`: idle home-slot wander (`minion-layout.js`, biased into near-fighter gathering zones via `minion-grouping.js` when another fighter is close, radius reaching toward the zone's own nearest-neighbor midpoint) or trail-follow (`minion-trail.js`) depending on the fighter's own sampled speed; resyncs from its real position on the moving→idle transition so arriving doesn't snap the swarm; every rendered position step (trail sample or idle target alike) is capped at the fighter's own real speed (`MINION_WALK_SPEED_PX_PER_S` floor) so movement onset, a zone appearing/dissolving, or a neighbor leaving all read as a normal walk, never a "vút" dash. Each minion also carries a small avatar badge (the owning fighter's own avatar texture) above its head, plus a pulsing cyan ring (`handleAgentToolUsed`, `minion.assignedAgentId`, hook v7's `FighterAgentToolUsed`) on whichever ONE minion is currently assigned to a busy `agent_id`. Cross-fighter clashes play a staggered multi-burst sequence from `MINION_CLASH_EFFECTS` (`config/companions.js`); see `.ai/domain/battlefield.md` Companions |
| `minion-trail.js` | Pure `sampleTrail`, `pushTrailSample` — leader-trail recording/replay math behind `minions.js`'s follow-the-leader minion chain (moving-fighter mode only) |
| `minion-layout.js` | Pure `homeSlotOffset`/`homeSlotAngle`/`shortestAngleDelta`/`isInFrontOfFighter` — the plain-fallback even circle division, its angle-only form, shortest-path angle delta (shared with `minion-grouping.js`), and the front/back depth-sort predicate |
| `minion-grouping.js` | Pure `isNear`, `clusterAngles`, `computeZones`, `zoneFanOffset` — near-fighter direction clustering behind `minions.js`'s gathering-zone idle behavior |
| `fighter.js` → `fighter/` | `class Fighter` (`index.js`) — fighter lifecycle; `avatar.js` (avatar texture loading + fallback generation); `animations.js` (`registerFighterAnimations` — registers one FIGHTER_TYPES entry's idle/walk/death/attackN/effectN anims against the shared atlas, idempotent); `preview.js` (pure `centeredScaleFit`/`drawFighterPreview` — fixed-scale, never-stretched sprite draw, shared with `character-preview/`); `flair.js` (pure flair state/ring-layout helpers); `flair-font.js` (the model ring's self-hosted webfont + its load gate — the only battlefield text not set in the browser default `monospace`) |
| `character-preview/` | Self-contained mini Phaser app (`game.js` + `scene.js`) powering the character-select loadout screen (`resources/views/livewire/character-select.blade.php`), separate from the main battlefield scene; `attack-labels.js` (per-`AttackType` skill-button labels), `moveset.js` (builds the real atlas frame list per skill, incl. a Summon derived by playing Death's frames in reverse), `skill-loop.js` (pure sticky skill-selection state machine — looping vs one-shot skill playback) |

## Test Files
| Test | What it covers |
|---|---|
| `tests/js/layout.test.js` | computeFighterPositions, fighterDisplayConfig, damageScaleMultiplier, chargeFootY, rowsNeeded |
| `tests/js/move-geometry.test.js` | isValidMoveTarget (edges, boss column, leaderboard, Damage HUD), bypassY, clampMoveTarget, snapToValidTarget, isInsideLeaderboardPanel, moveOrigin |
| `tests/js/fighter-placement.test.js` | resolveFighterPlacement — saved-position restore, snap-instead-of-reset, grid fallback |
| `tests/js/resync.test.js` | driftedPositions — drift detection tolerance, skips fighters mid-waypoint-move, skips fighters absent locally |
| `tests/js/hud-position.test.js` | computeHudTop — nav clearance, letterboxed-canvas alignment, offset-parent relativity, no-nav (IDE embed) fallback |
| `tests/js/config.test.js` | BOSS_TYPES, FIGHTER_TYPES config shape validation |
| `tests/js/minion-trail.test.js` | sampleTrail, pushTrailSample — leader-trail interpolation/clamping and history trimming |
| `tests/js/minion-layout.test.js` | homeSlotOffset/homeSlotAngle/shortestAngleDelta/isInFrontOfFighter — even angular distribution, shortest-path angle delta, front/back depth-sort predicate |
| `tests/js/minion-grouping.test.js` | isNear, clusterAngles, computeZones, zoneFanOffset — near-fighter distance/direction clustering and within-zone fan spread |
| `tests/js/snapshot.test.js` | snapshotState roundtrip |
| `tests/js/constants.test.js` | BusEvent, TextureKey, SCENE_KEY shape validation |
| `tests/js/leaderboard.test.js` | Legacy `makeMethods` damage-tracking factory (kept alongside the `Leaderboard` class; not currently wired into scene.js) |
| `tests/js/render-scale.test.js` | canvasSizeFor — scale from width×dpr, 1x floor, 2.5x cap, portrait layout |
| `tests/js/format.test.js` | formatHp — K/M/B tier formatting |
| `tests/js/pack-sprites.test.js` | Fighter atlas packing |
| `tests/js/flair.test.js` | Flair state/ring-layout pure helpers (buildRingChars, createFlairState, isFlairActive, hasFlairChanged, darkenHex...) |
| `tests/js/fighter-preview.test.js` | centeredScaleFit, drawFighterPreview (fixed-scale, never-stretched fit) |
| `tests/js/boss/bat-targeting.test.js` | computeBatHitTarget — cosmetic hit routing, threshold-crossing kill overrides |
| `tests/js/boss/bat-wander.test.js` | randomWanderPoint — uniform elliptical-area sampling |
| `tests/js/boss/summon-queue.test.js` | shouldSkipSummonFlourish — burst-limit threshold |
| `tests/js/attacks/priest-heal.test.js` | isHealRoll — damage-parity cosmetic flourish decision |
| `tests/js/character-preview/attack-labels.test.js` | getAttackLabel — per-AttackType/slot label resolution + fallback |
| `tests/js/character-preview/moveset.test.js` | buildMoveset — frame-name construction incl. reversed Summon-from-Death |
| `tests/js/character-preview/skill-loop.test.js` | createSkillLoop — looping vs one-shot sticky skill playback, token-based cancel |
| `tests/js/managers/Boss.test.js` | Boss pure helpers, dreadknight turn sequence |
| `tests/js/managers/Charge.test.js` | Charge pure helpers |
| `tests/js/managers/Fighter.test.js` | Fighter pure helpers; handleFighterMoved / handleHit (fake scene) — a move or second hit landing mid blade-dash never records the boss column as the fighter's home |
| `tests/js/managers/Impact.test.js` | Impact boss flinch (fake scene) — overlapping hits flinch around the rest scale, not a half-squashed one |
| `tests/js/managers/Leaderboard.test.js` | Leaderboard.abbreviateDamage formatting |
