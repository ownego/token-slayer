import { describe, expect, test } from 'vitest';
import { computeBatHitTarget, BAT_HP_THRESHOLDS } from '@battlefield/boss/bat-targeting.js';

const ALL_ALIVE = [false, true, true, true, true, true]; // index 0 unused

describe('computeBatHitTarget', () => {
  test('routes to the bat matching the damage tail digit when it is alive', () => {
    const result = computeBatHitTarget({ damage: 123, hpBeforePct: 0.95, hpAfterPct: 0.94, aliveBats: ALL_ALIVE });
    expect(result).toEqual({ targetBat: 3, killedBats: [] });
  });

  test('falls back to the boss when the tail digit has no bat (0, 6-9)', () => {
    for (const damage of [10, 16, 17, 18, 19]) {
      expect(computeBatHitTarget({ damage, hpBeforePct: 0.9, hpAfterPct: 0.89, aliveBats: ALL_ALIVE }))
        .toEqual({ targetBat: null, killedBats: [] });
    }
  });

  test('falls back to the boss when the tail-matched bat is already dead', () => {
    const aliveBats = [false, true, true, false, true, true]; // bat 3 dead
    const result = computeBatHitTarget({ damage: 3, hpBeforePct: 0.9, hpAfterPct: 0.89, aliveBats });
    expect(result).toEqual({ targetBat: null, killedBats: [] });
  });

  test('crossing a single threshold kills that bat and targets it, ignoring the tail digit', () => {
    // damage tail is 7 (boss-bound), but this hit crosses 80% -> bat 1 dies and is targeted instead
    const result = computeBatHitTarget({ damage: 27, hpBeforePct: 0.82, hpAfterPct: 0.79, aliveBats: ALL_ALIVE });
    expect(result).toEqual({ targetBat: 1, killedBats: [1] });
  });

  test('one big hit crossing multiple thresholds kills every crossed bat and targets the lowest', () => {
    const result = computeBatHitTarget({ damage: 1, hpBeforePct: 0.95, hpAfterPct: 0.35, aliveBats: ALL_ALIVE });
    expect(result).toEqual({ targetBat: 5, killedBats: [1, 2, 3, 4, 5] });
  });

  test('once every bat is dead, every hit targets the boss', () => {
    const aliveBats = [false, false, false, false, false, false];
    const result = computeBatHitTarget({ damage: 4, hpBeforePct: 0.3, hpAfterPct: 0.25, aliveBats });
    expect(result).toEqual({ targetBat: null, killedBats: [] });
  });

  test('BAT_HP_THRESHOLDS is the five descending 10%-apart marks', () => {
    expect(BAT_HP_THRESHOLDS).toEqual([0.80, 0.70, 0.60, 0.50, 0.40]);
  });
});
