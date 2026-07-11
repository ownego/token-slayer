import { describe, expect, test, vi } from 'vitest';

// Boss imports Phaser (via leaderboard → bus). Provide minimal stubs.
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

import { Boss } from '@battlefield/boss.js';
import { stunCooldownExpired } from '@battlefield/boss/stun.js';
import { getDreadknightTurnStep, isDreadknight } from '@battlefield/boss/dreadknight.js';

describe('hpBarColor', () => {
  test('returns green when hp is above 50%', () => {
    expect(Boss.hpBarColor(800, 1000)).toBe(0x22c55e);
  });

  test('returns green at exactly 51%', () => {
    expect(Boss.hpBarColor(510, 1000)).toBe(0x22c55e);
  });

  test('returns yellow when hp is between 25% and 50%', () => {
    expect(Boss.hpBarColor(400, 1000)).toBe(0xf59e0b);
  });

  test('returns yellow at exactly 26%', () => {
    expect(Boss.hpBarColor(260, 1000)).toBe(0xf59e0b);
  });

  test('returns red when hp is at or below 25%', () => {
    expect(Boss.hpBarColor(250, 1000)).toBe(0xef4444);
    expect(Boss.hpBarColor(0, 1000)).toBe(0xef4444);
  });
});

describe('bossLabel', () => {
  test('returns uppercased name when name is present', () => {
    expect(Boss.bossLabel({ name: 'abyssal dreadknight', number: 1 })).toBe('ABYSSAL DREADKNIGHT');
  });

  test('returns BOSS #N when name is absent', () => {
    expect(Boss.bossLabel({ number: 3 })).toBe('BOSS #3');
  });

  test('returns BOSS #? when number is also absent', () => {
    expect(Boss.bossLabel({})).toBe('BOSS #?');
  });

  test('handles null payload gracefully', () => {
    expect(Boss.bossLabel(null)).toBe('BOSS #?');
  });

  test('ignores empty string name', () => {
    expect(Boss.bossLabel({ name: '', number: 2 })).toBe('BOSS #2');
  });
});

describe('stunCooldownExpired', () => {
  test('returns true when entry has never been stunned', () => {
    expect(stunCooldownExpired({ lastStunAt: null }, 5000)).toBe(true);
  });

  test('returns false when last stun was less than 3000ms ago', () => {
    expect(stunCooldownExpired({ lastStunAt: 3000 }, 5999)).toBe(false);
  });

  test('returns true when exactly 3000ms have passed', () => {
    expect(stunCooldownExpired({ lastStunAt: 2000 }, 5000)).toBe(true);
  });

  test('returns true when more than 3000ms have passed', () => {
    expect(stunCooldownExpired({ lastStunAt: 1000 }, 5000)).toBe(true);
  });
});

describe('getDreadknightTurnStep', () => {
  test('turn 0 is walk with breath x4', () => {
    const step = getDreadknightTurnStep(0);
    expect(step.moveAnim).toBe('move');
    expect(step.attack).toBeNull();
    expect(step.breathAfter).toBe(4);
    expect(step.isSlash).toBe(false);
  });

  test('turn 1 is run with breath x6', () => {
    const step = getDreadknightTurnStep(1);
    expect(step.moveAnim).toBe('run');
    expect(step.attack).toBeNull();
    expect(step.breathAfter).toBe(6);
    expect(step.isSlash).toBe(false);
  });

  test('turn 2 is jump + downward slam, not slash', () => {
    const step = getDreadknightTurnStep(2);
    expect(step.moveAnim).toBe('jump');
    expect(step.attack).toBe('slam');
    expect(step.breathAfter).toBe(2);
    expect(step.isSlash).toBe(false);
  });

  test('turns 3, 4, 5 are dash attacks with slash and breath x2', () => {
    const attacks = ['slash-low', 'thrust', 'spin'];
    for (let i = 0; i < 3; i++) {
      const step = getDreadknightTurnStep(3 + i);
      expect(step.moveAnim).toBe('dash');
      expect(step.attack).toBe(attacks[i]);
      expect(step.breathAfter).toBe(2);
      expect(step.isSlash).toBe(true);
    }
  });

  test('wraps every 6 turns', () => {
    expect(getDreadknightTurnStep(6)).toEqual(getDreadknightTurnStep(0));
    expect(getDreadknightTurnStep(11)).toEqual(getDreadknightTurnStep(5));
  });
});

describe('isDreadknight', () => {
  test('returns true for abyssal-dreadknight key', () => {
    expect(isDreadknight('boss-abyssal-dreadknight')).toBe(true);
  });

  test('returns false for other boss keys', () => {
    expect(isDreadknight('boss-ghost')).toBe(false);
    expect(isDreadknight('boss-minotaur')).toBe(false);
  });
});

describe('bossTypeFor', () => {
  test('returns a boss type object for number 0', () => {
    const bt = Boss.bossTypeFor(0);
    expect(bt).toBeDefined();
    expect(bt.key).toBeDefined();
  });

  test('wraps around using modulo so any number returns a valid type', () => {
    const bt = Boss.bossTypeFor(9999);
    expect(bt).toBeDefined();
    expect(bt.key).toBeDefined();
  });
});
