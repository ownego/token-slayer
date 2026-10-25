import { describe, test, expect } from 'vitest';
import { pickAttackIndex } from '@battlefield/fighter/attack-pick.js';

describe('pickAttackIndex', () => {
  test('splits evenly when no attack has a weight, like the roster always did', () => {
    expect(pickAttackIndex([{}, {}, {}], 0)).toBe(0);
    expect(pickAttackIndex([{}, {}, {}], 0.5)).toBe(1);
    expect(pickAttackIndex([{}, {}, {}], 0.99)).toBe(2);
  });

  test('follows the weights: a hero lands its big moves on a fifth of hits', () => {
    const attacks = [{ weight: 0.8 }, { weight: 0.1 }, { weight: 0.1 }];
    expect(pickAttackIndex(attacks, 0.79)).toBe(0);
    expect(pickAttackIndex(attacks, 0.85)).toBe(1);
    expect(pickAttackIndex(attacks, 0.95)).toBe(2);
  });

  test('normalises weights that do not sum to one', () => {
    expect(pickAttackIndex([{ weight: 2 }, { weight: 2 }], 0.6)).toBe(1);
  });

  test('never runs past the last attack on a roll at the top of the range', () => {
    expect(pickAttackIndex([{ weight: 0.8 }, { weight: 0.2 }], 0.9999999)).toBe(1);
  });

  test('has no attack to pick from an empty or missing list', () => {
    expect(pickAttackIndex(null, 0.3)).toBe(-1);
    expect(pickAttackIndex([], 0.3)).toBe(-1);
  });
});
