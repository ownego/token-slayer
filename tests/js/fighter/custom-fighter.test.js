import { describe, expect, test } from 'vitest';
import {
  CUSTOM_FRAME,
  customFighterKey,
  customFighterType,
  normalizeCustomCharacter,
  sheetLayout,
  stripUrls,
  textureOf,
} from '@battlefield/fighter/custom-fighter.js';
import { AttackType } from '@battlefield/constants.js';

const ANIMATIONS = {
  idle: { frames: 6, rate: 8 },
  walk: { frames: 8, rate: 10 },
  attack: { frames: 6, rate: 12 },
  death: { frames: 3, rate: 6 },
};

const WIRE = { version: 'abc123def456', url_base: '/storage/custom-characters/7/abc123def456', animations: ANIMATIONS };

describe('normalizeCustomCharacter', () => {
  test('reads the broadcast shape (snake_case)', () => {
    expect(normalizeCustomCharacter(WIRE)).toEqual({
      version: 'abc123def456',
      urlBase: '/storage/custom-characters/7/abc123def456',
      animations: ANIMATIONS,
    });
  });

  test('reads the boot payload shape (camelCase) to the same result', () => {
    const boot = { version: WIRE.version, urlBase: WIRE.url_base, animations: ANIMATIONS };

    expect(normalizeCustomCharacter(boot)).toEqual(normalizeCustomCharacter(WIRE));
  });

  test.each([
    ['nothing', null],
    ['undefined', undefined],
    ['no version', { ...WIRE, version: '' }],
    ['no url base', { ...WIRE, url_base: undefined }],
    ['no animations', { ...WIRE, animations: undefined }],
    ['a missing animation', { ...WIRE, animations: { idle: ANIMATIONS.idle } }],
    ['zero frames', { ...WIRE, animations: { ...ANIMATIONS, walk: { frames: 0, rate: 10 } } }],
    ['a frame rate that is not a number', { ...WIRE, animations: { ...ANIMATIONS, walk: { frames: 8, rate: 'x' } } }],
  ])('is null for %s', (_name, raw) => {
    expect(normalizeCustomCharacter(raw)).toBeNull();
  });
});

describe('customFighterKey', () => {
  test('is unique per user and version, and never a roster key', () => {
    expect(customFighterKey(7, 'abc')).toBe('custom-7-abc');
    expect(customFighterKey(7, 'abc')).not.toBe(customFighterKey(7, 'abd'));
    expect(customFighterKey(7, 'abc')).not.toBe(customFighterKey(8, 'abc'));
  });
});

describe('stripUrls', () => {
  test('points each animation at its png under the version folder', () => {
    expect(stripUrls(normalizeCustomCharacter(WIRE))).toEqual({
      idle: '/storage/custom-characters/7/abc123def456/idle.png',
      walk: '/storage/custom-characters/7/abc123def456/walk.png',
      attack: '/storage/custom-characters/7/abc123def456/attack.png',
      death: '/storage/custom-characters/7/abc123def456/death.png',
    });
  });
});

describe('sheetLayout', () => {
  const layout = sheetLayout(ANIMATIONS);

  test('stacks the four strips as rows, as wide as the longest', () => {
    expect(layout.width).toBe(8 * CUSTOM_FRAME);
    expect(layout.height).toBe(4 * CUSTOM_FRAME);
    expect(layout.rows).toEqual([
      { name: 'idle', y: 0 },
      { name: 'walk', y: 100 },
      { name: 'attack', y: 200 },
      { name: 'death', y: 300 },
    ]);
  });

  test('names every frame the way the roster atlas does, so the fighter code needs no special case', () => {
    expect(layout.frames).toHaveLength(6 + 8 + 6 + 3);
    expect(layout.frames[0]).toEqual({ name: 'idle-0', x: 0, y: 0 });
    expect(layout.frames.find(f => f.name === 'walk-7')).toEqual({ name: 'walk-7', x: 700, y: 100 });
    expect(layout.frames.find(f => f.name === 'death-2')).toEqual({ name: 'death-2', x: 200, y: 300 });
  });
});

describe('customFighterType', () => {
  const type = customFighterType(7, normalizeCustomCharacter(WIRE));

  test('is keyed by user and version and owns its texture', () => {
    expect(type.key).toBe('custom-7-abc123def456');
    expect(type.texture).toBe(type.key);
    expect(type.custom).toBe(true);
  });

  test('has the animation table the server sent and no per-attack art', () => {
    expect(type.animations).toEqual(ANIMATIONS);
    expect(type.attacks).toBeUndefined();
    expect(type.attackType).toBe(AttackType.SLASH);
  });

  test('is drawn smaller than its frame suggests, so it stands as tall as a roster fighter', () => {
    // the strips are built at 28px tall; the roster fighters are 18px tall in their frame
    expect(type.bodyScale).toBeCloseTo(18 / 28);
  });

  test('keeps its feet where a roster fighter\'s are once scaled down about the frame centre', () => {
    // feet at row 58, frame centre at row 50: scaling about the centre lifts them by this many frame px
    expect(type.bodyOffsetY).toBeCloseTo((58 - 50) * (1 - 18 / 28));
  });
});

describe('textureOf', () => {
  test('is the shared atlas for a roster fighter and its own texture for a custom one', () => {
    expect(textureOf({ key: 'knight' })).toBe('fighters');
    expect(textureOf(customFighterType(7, normalizeCustomCharacter(WIRE)))).toBe('custom-7-abc123def456');
  });
});

describe('customFighterType spec', () => {
  test('keeps the character it was built from, so a snapshot can hand it back on a reboot', () => {
    const spec = normalizeCustomCharacter(WIRE);

    expect(customFighterType(7, spec).spec).toBe(spec);
  });
});

describe('normalizeCustomCharacter limits', () => {
  test('refuses a frame count no strip of ours would have, rather than build a huge texture', () => {
    expect(normalizeCustomCharacter({ ...WIRE, animations: { ...ANIMATIONS, walk: { frames: 500, rate: 10 } } })).toBeNull();
  });
});
