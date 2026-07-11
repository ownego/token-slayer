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

Naming convention: `{character}-{state}.png` for base animations, `{character}-attack{N}.png` and `{character}-effect{N}.png` for variants.

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
