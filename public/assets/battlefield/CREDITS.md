# Battlefield Assets

Pixel art from **Warped: Super Grotto Escape Collection** by **Ansimuz**.

- Original page: https://ansimuz.itch.io/super-grotto-escape-pack
- License: **CC0 1.0 Universal** (public domain)
- Mirror used for download: https://github.com/thanhnld0912/Super-Grotto-Escape

Fighter character sprites from **Tiny RPG Character Asset Pack v1.03** by **Zerie**:

- Page: https://zerie.itch.io/tiny-rpg-character-asset-pack
- License: **CC0 1.0 Universal** (public domain)
- Characters used: Soldier, Knight, Swordsman, Axeman, Orc, Armored Orc, Elite Orc, Skeleton, Armored Skeleton, Slime, Archer, Werewolf, Werebear, Orc Rider, Greatsword Skeleton
- All sheets are 100×100 px per frame with separate files per animation state (idle/walk/attack/death)

The `shadow.png` and `gnu.png` bosses are CC0 sprite sheets whose source
pages were not recorded at download time.

## Files

### Boss spritesheets (`bosses/`)

| File | Dimensions | Frames | Frame size |
|---|---|---|---|
| `ghost.png` | 128×32 | 4 | 32×32 |
| `ghost-fury.png` | 64×32 | 2 | 32×32 |
| `skeleton.png` | 128×32 | 4 | 32×32 |
| `slime.png` | 160×32 | 5 | 32×32 |
| `mini-demon.png` | 192×48 | 4 | 48×48 |
| `shadow.png` | 320×350 | 4 used (idle) | 80×70 |
| `gnu.png` | 600×400 | 5 used (idle) | 120×100 |

### Fighter spritesheets (`fighters/`)

Idle/run sheet pairs, all 256px-tall frames:

| Character | Files | Idle frame | Run frame | Frames (idle/run) |
|---|---|---|---|---|
| knight | `knight-idle.png`, `knight-run.png` | 212×256 | 212×256 | 10 / 10 |
| redhat | `redhat-idle.png`, `redhat-run.png` | 300×256 | 300×256 | 10 / 8 |
| ninjagirl | `ninjagirl-idle.png`, `ninjagirl-run.png` | 148×256 | 185×256 | 10 / 10 |
| adventurer | `adventurer-idle.png`, `adventurer-run.png` | 302×256 | 302×256 | 10 / 8 |
| shinobi | `ninja-idle.png`, `ninja-run.png` | 135×256 | 202×256 | 10 / 10 |

### FX (`fx/`)

| File | Dimensions | Frames | Frame size | Purpose |
|---|---|---|---|---|
| `fireball.png` | 64×16 | 4 | 16×16 | projectile |
| `explosion.png` | 128×32 | 4 | 32×32 | impact burst |
| `big-explosion.png` | 576×64 | 9 | 64×64 | boss-killed flash |
| `player-shoot-hit.png` | 64×16 | 4 | 16×16 | charge ring (additive blend) |

The charge ring may also be rendered procedurally via Phaser's `Graphics`
at runtime; the `player-shoot-hit` sheet is included as a fallback.

Text in the scene (boss name, HP, fighter handles) uses Phaser's
`add.text` with a monospace fallback rather than a bitmap font — keeps
the asset footprint smaller and is sharp enough at 480×270.
