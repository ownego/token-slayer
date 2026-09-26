# Battlefield Assets

Sprite formats and where each one is configured. Sources/licenses: `CREDITS.md`. Add-a-type recipes: `.claude/skills/battlefield/SKILL.md`. Companion behaviour: `.ai/domain/battlefield.md`.

All sprite art is loaded with NEAREST filtering (except bosses with `pixelArt: false`). Frame counts/sizes in config are validated against the PNGs by `tests/js/config.test.js`.

## Fighter sprites (shared atlas, generated)

- **Source of truth:** `resources/assets/battlefield/fighters/<key>-<state>.png` (committed, 176 strips for 20 types). Horizontal strips, every frame **100×100**. States: `idle`, `walk`, `attack`, `death`, `attack<N>`, `effect<N>`, plus optional `summon` (skeleton family's rise-in) and `heal` (priest).
- **Generated:** `scripts/pack-sprites.js` (run by `npm run build`/`npm run dev`) shelf-packs them into `public/assets/battlefield/fighters/fighters-atlas.png` + `.json` (4096 px wide; height grows with strip count) and writes `resources/js/battlefield/config/atlas-version.js`. All three are **gitignored** — don't edit or commit them; the `fighters/` directory doesn't exist in a fresh clone until you build.
- Atlas frame key: `<key>-<state>-<i>` (e.g. `swordsman-attack2-0`); Phaser anim key `<key>-<state>` (`fighter/animations.js`).
- Types (= `FIGHTER_TYPES` keys, in order): `soldier`, `knight`, `swordsman`, `axeman`, `orc`, `armored-orc`, `elite-orc`, `skeleton`, `armored-skeleton`, `slime`, `archer`, `werewolf`, `werebear`, `orc-rider`, `greatsword-skeleton`, `knight-templar`, `lancer`, `wizard`, `priest`, `skeleton-archer`.
- **Never upscale.** A Real-ESRGAN attempt produced 19200×3200 sheets incompatible with the fixed 100 px frame.

## Boss sprites (`bosses/`, `BOSS_TYPES` in `resources/js/battlefield/config/bosses.js`)

Two formats. `BOSS_TYPES` order (cycled by boss number): ghost, skeleton, abyssal-dreadknight, slime, flying-demon, minotaur, demon-slime.

**Single sheet** — one PNG, uniform frame, anims by frame range (`idleStart/idleEnd`, `moveStart/…`):
```
bosses/ghost.png              32×32,   scale 4   (?v=100)
bosses/skeleton.png           32×32,   scale 4   (?v=100)
bosses/slime.png              32×32,   scale 4   (?v=100)
bosses/minotaur-chierit.png   288×160, scale 1   idle/move/attack ranges
bosses/demon-slime-chierit.png 288×160, scale 1, pixelArt:false   idle/move/attack/hurt/death ranges
```
**Per-state folder** — one strip per anim (`animFiles`), texture key `<bossKey>-<anim>`:
```
bosses/flying-demon-xzany/    81×71, scale 2, float — idle, move(flying.png), attack, hurt, death
bosses/abyssal-dreadknight/   105 px tall, width varies per strip, scale 1.5 — idle, move(walk.png), run, jump,
                              slash-low, slam, thrust, spin, dash, hurt, getup, fx-slash, fx-slam (fx 120×77)
```
**Unused on disk:** `bosses/mini-demon.png` (48×48 ×4), `bosses/ghost-fury.png` (32×32 ×2) — not in `BOSS_TYPES`.

## Companion sprites (`companions/`, `resources/js/battlefield/config/companions.js`)

Direct per-animation spritesheets (like per-state bosses), texture key `<configKey>-<anim>`.
```
companions/bat/              BAT_CONFIG          100×100, scale 1.4 — flying, attack1, attack2, hurt, death
companions/necromancer/      NECROMANCER_CONFIG  100×100, scale 2.6 — idle, walk, summon, circle(summon-circle.png), death, appearBurst(appear-burst.png)
companions/demon-a/          MINION_TYPES        100×100, charHeight 20 — idle, walk, attack1, attack2, death
companions/blood-monster-a/  MINION_TYPES        100×100, charHeight 20 — idle, walk, attack1, attack2, death
companions/smw-goomba/       MINION_TYPES        110×110, charHeight 22 — idle, walk, attack-slam, attack-shellspin, attack-punch, attack-headbutt, attack-banana, hurt
companions/smw-babybowser/   MINION_TYPES        125×125, charHeight 25 — idle, walk, attack-fire, attack-brush, hurt
companions/smw-bowser/       MINION_TYPES        145×145, charHeight 29 — idle, walk, attack-fireball, attack-claw, attack-dash, hurt
companions/minion-clash/     MINION_CLASH_EFFECTS 100×100 — burst1 (10 frames), burst2 (7 frames)
```
Minion rules (test-locked, see the skill's "Add a minion type"):
- One frame size per type; `charHeight` = the character's ink height in its own frame. `minions.js` scales by it, so the smw types' larger frames still render at the same height as the 100 px pair — don't "normalize" them to 100×100.
- Every strip faces RIGHT. `attack-dash`/`attack-slam` stay in place; code moves the sprite (`travel`).
- smw strips were rebuilt 2026-09-26 with a palette-snapping shrink; attack/hurt strips are self-made from source frames. Tooling + native-resolution set live locally only in `docs/sprite-work/` (gitignored).
- `minion-clash/burst{1,2}.png` are crops of the atlas's `wizard-effect1`/`wizard-effect2` frames, saved standalone so a clash doesn't depend on the atlas.

## FX sprites (`fx/`, loaded in `scene.js` preload)
```
fx/fireball.png    16×16 × 4   TextureKey.FIREBALL
fx/explosion.png   32×32 × 4   TextureKey.EXPLOSION
```
**Unused on disk:** `fx/big-explosion.png`, `fx/player-shoot-hit.png`.

## Cache-busting

These are non-hashed URLs and the staging host's nginx caches them for 7 days (per the comments in `scripts/pack-sprites.js` and `companions.js`), so a changed PNG needs a new URL:
| Asset | How |
|---|---|
| Fighter atlas | automatic — `?v=${ATLAS_VERSION}` content hash |
| smw-* minions | hand-bumped `?v=<n>` in `companions.js` (currently `?v=5`) — bump on every edit |
| ghost/skeleton/slime bosses | static `?v=100` in `bosses.js` |
| everything else (other companions, per-state bosses, chierit sheets, FX) | no `?v=` — **add one** in the config when you edit the PNG |

## Naming
- Boss folders/files: `<name>-<source>` (e.g. `flying-demon-xzany`, `minotaur-chierit.png`) or `<name>` for original/in-house art.
- Companion folders: config `key`; strip files named after the anim key (kebab-case), except the few mapped in the table above.
