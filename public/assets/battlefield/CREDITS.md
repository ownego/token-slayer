# Battlefield Assets

## Background & Environment

Pixel art from **Warped: Super Grotto Escape Collection** by **Ansimuz**.

- Original page: https://ansimuz.itch.io/super-grotto-escape-pack
- License: **CC0 1.0 Universal** (public domain)
- Mirror used for download: https://github.com/thanhnld0912/Super-Grotto-Escape

## Fighter Characters

Sprites from **Tiny RPG Character Asset Pack v1.03** by **Zerie**:

- Page: https://zerie.itch.io/tiny-rpg-character-asset-pack
- License: **CC0 1.0 Universal** (public domain)
- All sheets: **100×100 px per frame**, `frameWidth: 100, frameHeight: 100`

### Fighter spritesheets (`fighters/`)

Each character has separate files per animation state. All frames are 100×100 px.

| Character           | idle | walk | attack | death | attack variants | effect variants |
|---------------------|------|------|--------|-------|-----------------|-----------------|
| soldier             |    6 |    8 |      6 |     4 |               3 |               3 |
| knight              |    6 |    8 |      7 |     4 |               3 |               3 |
| swordsman           |    6 |    8 |      7 |     4 |               3 |               3 |
| axeman              |    6 |    8 |      9 |     4 |               3 |               3 |
| orc                 |    6 |    8 |      6 |     4 |               2 |               2 |
| armored-orc         |    6 |    8 |      7 |     4 |               3 |               3 |
| elite-orc           |    6 |    8 |      7 |     4 |               3 |               3 |
| skeleton            |    6 |    8 |      6 |     4 |               2 |               2 |
| armored-skeleton    |    6 |    8 |      8 |     4 |               2 |               2 |
| slime               |    6 |    6 |      6 |     4 |               2 |               2 |
| archer              |    6 |    8 |      9 |     4 |               2 |               2 |
| werewolf            |    6 |    8 |      9 |     4 |               2 |               2 |
| werebear            |    6 |    8 |      9 |     4 |               3 |               3 |
| orc-rider           |    6 |    8 |      8 |     4 |               3 |               3 |
| greatsword-skeleton |    6 |    9 |      9 |     4 |               3 |               3 |
| knight-templar      |    6 |    8 |      7 |     4 |               3 |               0 |
| lancer              |    6 |    8 |      6 |     4 |               3 |               0 |
| wizard              |    6 |    8 |      6 |     4 |               2 |               2 |
| priest              |    6 |    8 |      9 |     4 |               1 |               1 |
| skeleton-archer     |    6 |    8 |      9 |     4 |               1 |               0 |

Naming convention: `{character}-{state}.png` for base animations, `{character}-attack{N}.png` and `{character}-effect{N}.png` for variants.

**These 5 (added later, `d9c60d3`) went straight into `fighters-atlas.png`/`.json` — no standalone per-state PNGs are checked into `fighters/` for them,** unlike the original 15. `knight-templar` and `lancer` (melee `BLADE` attacks) have no effect-strip variants at all. `priest` also has a dedicated 4-frame `heal` animation (`rate: 5`) played on the Necromancer, not on Priest itself — see `.ai/domain/battlefield.md` Companions. `skeleton-archer` also has the skeleton-family's 5-frame `summon` animation, like `skeleton`/`armored-skeleton`/`greatsword-skeleton`.

## Companion Spritesheets (`companions/`)

**Source/license not recorded when these were added (`4d0a01a`) — TODO: confirm and fill in before relying on this section for attribution.** All frames 100×100 px, loaded as direct per-animation spritesheets (like a multi-anim boss), not the shared fighter atlas.

| File | Frames | Notes |
|---|---|---|
| `bat/flying.png` | 6 | loops |
| `bat/attack1.png` | 6 | |
| `bat/attack2.png` | 7 | |
| `bat/hurt.png` | 4 | |
| `bat/death.png` | 4 | |
| `necromancer/idle.png` | 6 | loops |
| `necromancer/walk.png` | 6 | loops |
| `necromancer/summon.png` | 10 | cast gesture starts ~frame 5 |
| `necromancer/summon-circle.png` | 7 | played at the joining fighter's own spot, not on the Necromancer sprite |
| `necromancer/death.png` | 9 | also reused, forward/reversed, as the vanish/reappear teleport transition |
| `necromancer/appear-burst.png` | 6 | |

From "Tiny RPG Character Asset Pack 02 v1.01-Free Demon_A&Blood Monster_A" — same source/author (**Zerie**) and license (**CC0 1.0 Universal**) as the Fighter Characters pack above. Idle/Walk/Attack01/Attack02 were extracted (Attack01/Attack02 used purely as a cosmetic idle fidget, never for damage); the pack also ships Hurt/Death, unused since minions never fight (see `.ai/domain/battlefield.md` Companions).

| File | Frames | Notes |
|---|---|---|
| `demon-a/idle.png` | 6 | loops |
| `demon-a/walk.png` | 8 | loops |
| `demon-a/attack1.png` | 7 | cosmetic idle fidget |
| `demon-a/attack2.png` | 7 | cosmetic idle fidget |
| `blood-monster-a/idle.png` | 6 | loops |
| `blood-monster-a/walk.png` | 8 | loops |
| `blood-monster-a/attack1.png` | 8 | cosmetic idle fidget |
| `blood-monster-a/attack2.png` | 8 | cosmetic idle fidget |

## Boss Spritesheets (`bosses/`)

| File | Dimensions | Frames | Frame size |
|---|---|---|---|
| `ghost.png` | 128×32 | 4 | 32×32 |
| `ghost-fury.png` | 64×32 | 2 | 32×32 |
| `skeleton.png` | 128×32 | 4 | 32×32 |
| `slime.png` | 160×32 | 5 | 32×32 |
| `mini-demon.png` | 192×48 | 4 | 48×48 |

### Flying Demon by **xzany**

- Page: https://xzany.itch.io/flying-demon-2d-pixel-art

| File (`flying-demon-xzany/`) | Frame size | Frames |
|---|---|---|
| `idle.png` | 81×71 | 4 |
| `flying.png` | 81×71 | 4 |
| `attack.png` | 81×71 | 8 |
| `hurt.png` | 81×71 | 4 |
| `death.png` | 81×71 | 7 |

### Minotaur & Demon Slime by **chierit**

- Minotaur: https://chierit.itch.io/boss-minotaur
- Demon Slime: https://chierit.itch.io/boss-demon-slime

| File | Frame size | Notes |
|---|---|---|
| `minotaur-chierit.png` | 288×160 | idle (0–15), move (16–27), attack (32–47) |
| `demon-slime-chierit.png` | 288×160 | idle (0–5), move (22–33), attack (44–58), hurt (66–70), death (88–109) |

### Abyssal Dreadknight

Original artwork, no external license.

| File (`abyssal-dreadknight/`) | Frame size | Frames | Notes |
|---|---|---|---|
| `idle.png` | 118×105 | 6 | loops |
| `walk.png` | 118×105 | 6 | loops |
| `run.png` | 118×105 | 5 | loops |
| `jump.png` | 118×105 | 6 | jump + landing |
| `slash-low.png` | 172×105 | 5 | attack |
| `slam.png` | 162×105 | 6 | attack (jump slam) |
| `thrust.png` | 168×105 | 5 | attack |
| `spin.png` | 182×105 | 4 | attack |
| `dash.png` | 188×105 | 3 | dash-in movement before slash-low/thrust/spin |
| `hurt.png` | 118×105 | 4 | react |
| `getup.png` | 140×105 | 6 | reversed for fall react, forward for death |
| `fx-slash.png` | 120×77 | 3 | slash trail effect |
| `fx-slam.png` | 120×77 | 4 | slam impact effect |

## FX (`fx/`)

| File | Dimensions | Frames | Frame size | Purpose |
|---|---|---|---|---|
| `fireball.png` | 64×16 | 4 | 16×16 | projectile |
| `explosion.png` | 128×32 | 4 | 32×32 | impact burst |
| `big-explosion.png` | 576×64 | 9 | 64×64 | boss-killed flash |
| `player-shoot-hit.png` | 64×16 | 4 | 16×16 | charge ring fallback |
