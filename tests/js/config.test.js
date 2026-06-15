import { describe, expect, test } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { BOSS_TYPES, FIGHTER_TYPES } from '../../resources/js/battlefield/config.js';

const publicFile = (urlPath) => join(process.cwd(), 'public', urlPath);

// PNG dimensions live in the IHDR chunk: width at bytes 16-19, height at 20-23
function pngSize(path) {
  const buf = readFileSync(path);
  return { width: buf.readUInt32BE(16), height: buf.readUInt32BE(20) };
}

describe('FIGHTER_TYPES', () => {
  test('each entry has the unified multi-file schema shape', () => {
    for (const f of FIGHTER_TYPES) {
      expect(typeof f.key, f.key).toBe('string');
      expect(typeof f.attackType, f.key).toBe('string');
      expect(f.frameWidth, f.key).toBeGreaterThan(0);
      expect(f.frameHeight, f.key).toBeGreaterThan(0);
      for (const state of ['idle', 'walk', 'attack', 'death']) {
        const anim = f.animations?.[state];
        expect(anim, `${f.key}.animations.${state}`).toBeDefined();
        expect(typeof anim.file, `${f.key}.animations.${state}.file`).toBe('string');
        expect(anim.frames, `${f.key}.animations.${state}.frames`).toBeGreaterThan(0);
        expect(anim.rate, `${f.key}.animations.${state}.rate`).toBeGreaterThan(0);
      }
      expect(f.idleFile, `${f.key} must not have legacy idleFile`).toBeUndefined();
      expect(f.runFile, `${f.key} must not have legacy runFile`).toBeUndefined();
    }
  });

  test('matches the backend FighterCharacter enum keys in order', () => {
    expect(FIGHTER_TYPES.map((f) => f.key)).toEqual([
      'soldier', 'knight', 'swordsman', 'axeman', 'orc',
      'armored-orc', 'elite-orc', 'skeleton', 'armored-skeleton', 'slime',
      'archer', 'werewolf', 'werebear', 'orc-rider', 'greatsword-skeleton',
    ]);
  });

  test('every spritesheet file exists on disk', () => {
    for (const f of FIGHTER_TYPES) {
      for (const [state, anim] of Object.entries(f.animations)) {
        expect(existsSync(publicFile(anim.file)), `${f.key}.${state}: ${anim.file}`).toBe(true);
      }
    }
  });

  test('spritesheet dimensions match frame config', () => {
    for (const f of FIGHTER_TYPES) {
      for (const [state, anim] of Object.entries(f.animations)) {
        const { width, height } = pngSize(publicFile(anim.file));
        expect(width % f.frameWidth, `${f.key}.${state}: width not divisible by frameWidth`).toBe(0);
        expect(height, `${f.key}.${state}: height should equal frameHeight`).toBe(f.frameHeight);
        const cols = width / f.frameWidth;
        expect(anim.frames, `${f.key}.${state}: frames exceeds sheet columns`).toBeLessThanOrEqual(cols);
      }
    }
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
