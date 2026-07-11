import { describe, expect, test } from 'vitest';
import { BusEvent, TextureKey, SCENE_KEY } from '@battlefield/constants.js';

describe('BusEvent', () => {
  test('contains all expected bus event string values', () => {
    expect(BusEvent.HIT).toBe('hit');
    expect(BusEvent.BOSS_SPAWNED).toBe('boss-spawned');
    expect(BusEvent.BOSS_KILLED).toBe('boss-killed');
    expect(BusEvent.FIGHTER_JOINED).toBe('fighter-joined');
    expect(BusEvent.FIGHTER_CHARGING).toBe('fighter-charging');
    expect(BusEvent.FIGHTER_IDLED).toBe('fighter-idled');
    expect(BusEvent.FIGHTER_MOVED).toBe('fighter-moved');
  });
});

describe('TextureKey', () => {
  test('contains all expected texture key strings', () => {
    expect(TextureKey.FIGHTERS).toBe('fighters');
    expect(TextureKey.SPARK).toBe('spark');
    expect(TextureKey.FIREBALL).toBe('fireball');
    expect(TextureKey.EXPLOSION).toBe('explosion');
  });
});

describe('SCENE_KEY', () => {
  test('equals the battlefield scene identifier', () => {
    expect(SCENE_KEY).toBe('battlefield');
  });
});
