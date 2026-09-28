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
    expect(BusEvent.POSITIONS_RESYNCED).toBe('positions-resynced');
    expect(BusEvent.FIGHTER_CHARGE_CLEARED).toBe('fighter-charge-cleared');
    expect(BusEvent.CHARACTER_CHANGED).toBe('character-changed');
    expect(BusEvent.FIGHTER_AGENT_COUNT_CHANGED).toBe('fighter-agent-count-changed');
    expect(BusEvent.BOSS_HP_TICK).toBe('boss-hp-tick');
  });
});

describe('TextureKey', () => {
  test('contains all expected texture key strings', () => {
    expect(TextureKey.FIGHTERS).toBe('fighters');
    expect(TextureKey.SPARK).toBe('spark');
    expect(TextureKey.SPARK_STREAK).toBe('spark-streak');
    expect(TextureKey.PUFF).toBe('puff');
    expect(TextureKey.SOFTGLOW).toBe('softglow');
    expect(TextureKey.CLAWD_DEFAULT).toBe('clawd-default');
    expect(TextureKey.CLAWD_CROUCH).toBe('clawd-crouch');
    expect(TextureKey.CLAWD_ARMS).toBe('clawd-arms');
    expect(TextureKey.MOTE_SOFT).toBe('mote-soft');
    expect(TextureKey.FIREBALL).toBe('fireball');
    expect(TextureKey.EXPLOSION).toBe('explosion');
  });
});

describe('SCENE_KEY', () => {
  test('equals the battlefield scene identifier', () => {
    expect(SCENE_KEY).toBe('battlefield');
  });
});
