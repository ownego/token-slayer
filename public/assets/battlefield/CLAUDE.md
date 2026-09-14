# Battlefield Assets

## Fighter Sprites

- All fighter frames are packed into a **single atlas**: `fighters/fighters-atlas.png` + `fighters-atlas.json`
- Atlas size: **4096×3000 px**, each frame is **100×100 px**
- Frame key format: `{type}-{animation}-{frameIndex}` e.g. `swordsman-attack2-0`
- Fighter types: `archer`, `armored`, `axeman`, `elite`, `greatsword`, `knight`, `knight-templar`, `lancer`, `orc`, `priest`, `skeleton`, `skeleton-archer`, `slime`, `soldier`, `swordsman`, `werebear`, `werewolf`, `wizard`
- **Do NOT upscale the atlas** — Real-ESRGAN produced 19200×3200 sheets incompatible with `frameWidth: 100`. The 100px frame size is fixed.
- Adding a new fighter type requires updating the atlas PNG+JSON and adding an entry to `FIGHTER_TYPES` in `config.js`

## Boss Sprites

Two formats exist:

**Simple sprite sheet** (legacy bosses): single PNG, uniform `frameWidth`/`frameHeight`, defined in `BOSS_TYPES` in `config.js`:
```
bosses/ghost.png          — 32×32 frames, scale 4
bosses/skeleton.png       — 32×32 frames, scale 4
bosses/slime.png          — 32×32 frames, scale 4
bosses/mini-demon.png     — 32×32 frames, scale 4
bosses/ghost-fury.png     — 32×32 frames, scale 4
bosses/minotaur-chierit.png   — 288×160 frames, scale 1
bosses/demon-slime-chierit.png — 288×160 frames, scale 1
```

**Multi-file animated boss** (modern bosses): one PNG per animation state, inside a named folder:
```
bosses/flying-demon-xzany/   — 81×71 frames, scale 2, states: idle/flying/attack/hurt/death
bosses/abyssal-dreadknight/  — states: idle/move/run/jump/slash-low/slam/thrust/spin/dash/hurt/getup
```

## Companion Sprites

Ambient actors, neither a fighter nor a boss — see `.ai/domain/battlefield.md` Companions. One PNG per animation state, direct spritesheets like the multi-file boss format (not the shared fighter atlas), defined in `COMPANIONS`/`BAT_CONFIG`/`NECROMANCER_CONFIG` in `config/companions.js`:
```
companions/bat/          — 100×100 frames, scale 1.4, states: flying/attack1/attack2/hurt/death
companions/necromancer/  — 100×100 frames, scale 2.6, states: idle/walk/summon/circle/death/appearBurst
```

## FX Sprites

```
fx/fireball.png        — 16×16 frames
fx/explosion.png       — 32×32 frames
fx/big-explosion.png   — sprite sheet, used for boss death
fx/player-shoot-hit.png
```

## Naming Convention

- Boss folders: `{name}-{source}` e.g. `flying-demon-xzany`, `abyssal-dreadknight`
- Simple boss PNGs: `{name}.png` or `{name}-{source}.png`
- Simple boss PNGs and FX sprites use a static `?v=100` cache-busting suffix in `config.js`. The fighter atlas instead uses `ATLAS_VERSION` (`config/atlas-version.js`), appended as `?v=${ATLAS_VERSION}` in `scene.js` — **bump this string whenever the atlas PNG/JSON changes**, or browsers keep serving the stale cached atlas (see `.ai/domain/battlefield.md` #8).
