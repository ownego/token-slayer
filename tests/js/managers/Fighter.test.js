import { describe, expect, test, vi } from 'vitest';

// Fighter imports Boss (for clamp/validate ctx) which imports Phaser (via leaderboard → bus). Provide minimal stubs.
vi.mock('phaser', () => ({
  default: {
    Events: {
      EventEmitter: class {
        on() {}
        off() {}
        emit() {}
        once() {}
      },
    },
    Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete', ANIMATION_REPEAT: 'animationrepeat' } },
  },
}));

import { Fighter } from '@battlefield/fighter.js';

describe('fighterRestScale', () => {
  test('returns 1.0 for base-size fighter with no damage scale', () => {
    const fighter = { displaySize: 48, baseSize: 48, damageScale: 1 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.0);
  });

  test('scales up when displaySize is larger than baseSize', () => {
    const fighter = { displaySize: 96, baseSize: 48, damageScale: 1 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(2.0);
  });

  test('applies damageScale as a multiplier', () => {
    const fighter = { displaySize: 48, baseSize: 48, damageScale: 1.5 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.5);
  });

  test('defaults damageScale to 1 when undefined', () => {
    const fighter = { displaySize: 48, baseSize: 48 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(1.0);
  });

  test('combines displaySize ratio and damageScale', () => {
    const fighter = { displaySize: 60, baseSize: 40, damageScale: 2 };
    expect(Fighter.fighterRestScale(fighter)).toBeCloseTo(3.0);
  });
});
