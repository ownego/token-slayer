import { describe, test, expect } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { ROSTER_COUNT, resolveFighterType, snapshotCharacter } from '@battlefield/fighter/resolve-type.js';

const HERO_KEYS = ['iron-man', 'captain-america', 'batman', 'superman'];

describe('resolveFighterType', () => {
  test('draws a hero when its atlas loaded', () => {
    expect(resolveFighterType('batman', 7, { heroesLoaded: true }).key).toBe('batman');
  });

  test('falls back to a roster fighter by user id when the hero atlas is missing', () => {
    expect(resolveFighterType('batman', 7, { heroesLoaded: false }).key).toBe(FIGHTER_TYPES[7].key);
  });

  test('keeps roster fighters whether or not the hero atlas loaded', () => {
    expect(resolveFighterType('werebear', 3, { heroesLoaded: false }).key).toBe('werebear');
  });

  test('an unknown key (a newer server than this bundle) falls back by user id', () => {
    expect(resolveFighterType('unknown', 41, { heroesLoaded: true }).key).toBe(FIGHTER_TYPES[1].key);
  });

  test('the fallback is never a hero, whatever the user id', () => {
    expect(ROSTER_COUNT).toBe(20);
    for (const id of [0, 19, 20, 23, 24, -5, Number.NaN]) {
      expect(HERO_KEYS).not.toContain(resolveFighterType(null, id, { heroesLoaded: true }).key);
    }
  });

  test('the roster count is the fighters before the heroes, so a fighter added later still stands in', () => {
    expect(ROSTER_COUNT).toBe(FIGHTER_TYPES.filter(ft => !HERO_KEYS.includes(ft.key)).length);
  });
});

describe('snapshotCharacter', () => {
  test('keeps the server\'s key even when this client drew a stand-in, so a rotate can draw the hero once its atlas loads', () => {
    expect(snapshotCharacter('batman', FIGHTER_TYPES[7])).toBe('batman');
  });

  test('uses the drawn type when the server sent no key', () => {
    expect(snapshotCharacter(null, FIGHTER_TYPES[7])).toBe(FIGHTER_TYPES[7].key);
  });
});
