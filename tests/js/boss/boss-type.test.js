import { describe, expect, test } from 'vitest';
import { BOSS_ROTATION, bossTypeForNumber, bossTypeOf } from '@battlefield/boss/boss-type.js';
import { BOSS_TYPES } from '@battlefield/config.js';

describe('boss sprite resolution', () => {
  test('generic monsters rotate by number through every entry without a fixed name', () => {
    expect(BOSS_ROTATION.map((b) => b.key)).toEqual([
      'boss-ghost', 'boss-skeleton', 'boss-abyssal-dreadknight', 'boss-slime',
      'boss-flying-demon', 'boss-minotaur', 'boss-demon-slime',
    ]);
    expect(bossTypeForNumber(55).key).toBe(BOSS_ROTATION[55 % 7].key);
  });

  test('a recognizable character wears its own sprite whatever its number', () => {
    expect(bossTypeOf({ number: 3, name: 'ThaNode' }).key).toBe('boss-thanos');
  });

  test('a pool-named boss in the old thanos slot keeps its rotation sprite', () => {
    expect(bossTypeOf({ number: 55, name: 'Smaug' }).key).toBe(bossTypeForNumber(55).key);
  });

  test('missing state falls back to the first rotation sprite', () => {
    expect(bossTypeOf(undefined).key).toBe(BOSS_ROTATION[0].key);
  });

  test('every fixed name is unique', () => {
    const names = BOSS_TYPES.map((b) => b.fixedName).filter(Boolean);
    expect(new Set(names).size).toBe(names.length);
  });
});
