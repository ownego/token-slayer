import { describe, expect, test } from 'vitest';
import { registerFighterAnimations } from '@battlefield/fighter/animations.js';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { customFighterType, normalizeCustomCharacter } from '@battlefield/fighter/custom-fighter.js';

/**
 * A scene that records the animations it is asked to create and which texture
 * each one's frames come from.
 *
 * @return {{anims: object, created: Map<string, {texture: string, names: string[]|undefined, frames: number[]|undefined}>}}
 */
function fakeScene() {
  const created = new Map();

  return {
    created,
    anims: {
      exists: key => created.has(key),
      generateFrameNames: (texture, { prefix, start, end, frames }) => ({
        texture,
        names: frames ? undefined : Array.from({ length: end - start + 1 }, (_, i) => `${prefix}${start + i}`),
        frames,
        prefix,
      }),
      create: config => created.set(config.key, {
        texture: config.frames.texture,
        names: config.frames.names,
        frames: config.frames.frames,
        prefix: config.frames.prefix,
        repeat: config.repeat,
        frameRate: config.frameRate,
      }),
    },
  };
}

const CUSTOM = customFighterType(7, normalizeCustomCharacter({
  version: 'abc',
  url_base: '/storage/custom-characters/7/abc',
  animations: {
    idle: { frames: 6, rate: 8 },
    walk: { frames: 8, rate: 10 },
    attack: { frames: 6, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
}));

describe('registerFighterAnimations', () => {
  test('a roster fighter\'s animations come from the shared atlas', () => {
    const scene = fakeScene();

    registerFighterAnimations(scene, FIGHTER_TYPES[0]);

    expect(scene.created.get('soldier-idle').texture).toBe('fighters');
  });

  test('a custom fighter\'s animations come from its own texture', () => {
    const scene = fakeScene();

    registerFighterAnimations(scene, CUSTOM);

    expect(scene.created.get('custom-7-abc-idle').texture).toBe('custom-7-abc');
    expect(scene.created.get('custom-7-abc-walk').names).toHaveLength(8);
    expect(scene.created.get('custom-7-abc-attack').repeat).toBe(0);
    expect(scene.created.get('custom-7-abc-idle').repeat).toBe(-1);
  });

  test('a custom fighter has a single attack animation and no per-attack art', () => {
    const scene = fakeScene();

    registerFighterAnimations(scene, CUSTOM);

    expect([...scene.created.keys()].filter(key => key.includes('attack'))).toEqual(['custom-7-abc-attack']);
    expect([...scene.created.keys()].some(key => key.includes('effect'))).toBe(false);
  });

  test('its summon is its own death played backwards, from its own texture', () => {
    const scene = fakeScene();

    registerFighterAnimations(scene, CUSTOM);

    const summon = scene.created.get('custom-7-abc-summon');
    expect(summon.texture).toBe('custom-7-abc');
    expect(summon.frames).toEqual([2, 1, 0]);
    expect(summon.prefix).toBe('custom-7-abc-death-');
  });

  test('registering twice creates nothing new', () => {
    const scene = fakeScene();

    registerFighterAnimations(scene, CUSTOM);
    const count = scene.created.size;
    registerFighterAnimations(scene, CUSTOM);

    expect(scene.created.size).toBe(count);
  });
});

describe('registerFighterAnimations: per-frame timing', () => {
  test('a hero move with frameMs plays each frame for its own time, totalling the move', () => {
    const created = new Map();
    const scene = {
      anims: {
        exists: key => created.has(key),
        generateFrameNames: (texture, { prefix, start, end, frames }) =>
          (frames ?? Array.from({ length: end - start + 1 }, (_, i) => start + i)).map(i => ({ key: texture, frame: `${prefix}${i}` })),
        create: config => created.set(config.key, config),
      },
    };
    const hero = {
      key: 'test-hero', texture: 'heroes',
      animations: { idle: { frames: 1, rate: 8 }, walk: { frames: 1, rate: 8 }, attack: { frames: 3, rate: 12 }, death: { frames: 1, rate: 8 } },
      attacks: [{ frames: 3, rate: 12, frameMs: [80, 900, 120] }],
    };

    registerFighterAnimations(scene, hero);

    // Phaser plays a frame for its own duration when it has one, else 1000 / frameRate.
    const move = created.get('test-hero-attack1');
    const total = move.frames.reduce((sum, f) => sum + (f.duration || 1000 / move.frameRate), 0);
    expect(total).toBe(1100);
    expect(move.frames.map(f => f.frame)).toEqual(['test-hero-attack1-0', 'test-hero-attack1-1', 'test-hero-attack1-2']);
  });
});
