import { describe, expect, test } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { BOSS_TYPES, FIGHTER_TYPES } from '../../resources/js/battlefield/config.js';

const publicFile = (urlPath) => join(process.cwd(), 'public', urlPath.split('?')[0]);

// PNG dimensions live in the IHDR chunk: width at bytes 16-19, height at 20-23
function pngSize(path) {
  const buf = readFileSync(path);
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

const atlasJsonPath = join(process.cwd(), 'public/assets/battlefield/fighters/fighters-atlas.json');
const atlasExists   = existsSync(atlasJsonPath);
const atlasFrames   = atlasExists ? JSON.parse(readFileSync(atlasJsonPath, 'utf8')).frames : {};

describe('FIGHTER_TYPES', () => {
  test('each entry has the atlas-compatible schema shape', () => {
    for (const f of FIGHTER_TYPES) {
      expect(typeof f.key, f.key).toBe('string');
      expect(typeof f.attackType, f.key).toBe('string');
      expect(f.frameWidth,  `${f.key} must not have frameWidth`).toBeUndefined();
      expect(f.frameHeight, `${f.key} must not have frameHeight`).toBeUndefined();
      for (const state of ['idle', 'walk', 'attack', 'death']) {
        const anim = f.animations?.[state];
        expect(anim,          `${f.key}.animations.${state}`).toBeDefined();
        expect(anim.file,     `${f.key}.animations.${state} must not have file`).toBeUndefined();
        expect(anim.frames,   `${f.key}.animations.${state}.frames`).toBeGreaterThan(0);
        expect(anim.rate,     `${f.key}.animations.${state}.rate`).toBeGreaterThan(0);
      }
      expect(f.idleFile, `${f.key} must not have legacy idleFile`).toBeUndefined();
      expect(f.runFile,  `${f.key} must not have legacy runFile`).toBeUndefined();
    }
  });

  test('matches the backend FighterCharacter enum keys in order', () => {
    expect(FIGHTER_TYPES.map((f) => f.key)).toEqual([
      'soldier', 'knight', 'swordsman', 'axeman', 'orc',
      'armored-orc', 'elite-orc', 'skeleton', 'armored-skeleton', 'slime',
      'archer', 'werewolf', 'werebear', 'orc-rider', 'greatsword-skeleton',
    ]);
  });

  test.skipIf(!atlasExists)('atlas JSON contains all expected animation frames', () => {
    for (const f of FIGHTER_TYPES) {
      for (const [state, anim] of Object.entries(f.animations)) {
        for (let i = 0; i < anim.frames; i++) {
          const name = `${f.key}-${state}-${i}`;
          expect(atlasFrames[name], `missing atlas frame: ${name}`).toBeDefined();
        }
      }
      for (let n = 0; n < (f.attacks?.length ?? 0); n++) {
        const atk = f.attacks[n];
        for (let i = 0; i < atk.frames; i++) {
          const name = `${f.key}-attack${n + 1}-${i}`;
          expect(atlasFrames[name], `missing atlas frame: ${name}`).toBeDefined();
        }
        for (let i = 0; i < (atk.effectFrames ?? 0); i++) {
          const name = `${f.key}-effect${n + 1}-${i}`;
          expect(atlasFrames[name], `missing atlas frame: ${name}`).toBeDefined();
        }
      }
    }
  });

  test.skipIf(!atlasExists)('fighters-atlas.png exists in public', () => {
    expect(existsSync(join(process.cwd(), 'public/assets/battlefield/fighters/fighters-atlas.png'))).toBe(true);
  });
});

describe('BOSS_TYPES', () => {
  test('keys are unique', () => {
    const keys = BOSS_TYPES.map((b) => b.key);
    expect(new Set(keys).size).toBe(keys.length);
  });

  test('every boss spritesheet exists on disk', () => {
    for (const b of BOSS_TYPES) {
      expect(existsSync(publicFile(b.file)), `${b.key}: ${b.file}`).toBe(true);
    }
  });

  test('frame configs are sane', () => {
    for (const b of BOSS_TYPES) {
      expect(b.frameWidth, b.key).toBeGreaterThan(0);
      expect(b.frameHeight, b.key).toBeGreaterThan(0);
      expect(b.idleEnd, b.key).toBeGreaterThanOrEqual(b.idleStart);
      expect(b.scale, b.key).toBeGreaterThan(0);
    }
  });

  test('frame grid matches the actual spritesheet dimensions and idle frames exist', () => {
    for (const b of BOSS_TYPES) {
      const { width, height } = pngSize(publicFile(b.file));
      expect(width % b.frameWidth, `${b.key}: sheet width ${width} not divisible by frameWidth ${b.frameWidth}`).toBe(0);
      expect(height % b.frameHeight, `${b.key}: sheet height ${height} not divisible by frameHeight ${b.frameHeight}`).toBe(0);
      const totalFrames = (width / b.frameWidth) * (height / b.frameHeight);
      expect(b.idleEnd, `${b.key}: idleEnd ${b.idleEnd} outside sheet (${totalFrames} frames)`).toBeLessThan(totalFrames);
    }
  });
});
