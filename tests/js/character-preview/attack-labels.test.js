import { describe, expect, test } from 'vitest';
import { AttackType } from '@battlefield/constants.js';
import { getAttackLabel } from '@battlefield/character-preview/attack-labels.js';

describe('getAttackLabel', () => {
  test.each([
    [AttackType.SLASH, 0, '⚔ Slash Combo'],
    [AttackType.SLASH, 1, '⚔ Spinning Slash'],
    [AttackType.SLASH, 2, '⚔ Slash Finisher'],
    [AttackType.BLADE, 0, '🗡️ Blade Strike'],
    [AttackType.BLADE, 1, '🗡️ Blade Flurry'],
    [AttackType.BLADE, 2, '🗡️ Blade Finisher'],
    [AttackType.SHURIKEN, 0, '✴ Shuriken Toss'],
    [AttackType.SHURIKEN, 1, '✴ Shuriken Storm'],
    [AttackType.ARROW, 0, '🏹 Quick Shot'],
    [AttackType.ARROW, 1, '🏹 Arrow Volley'],
    [AttackType.BLAST, 0, '🔥 Fireball'],
    [AttackType.BLAST, 1, '🔥 Blast Wave'],
    [AttackType.BLAST, 2, '🔥 Meteor'],
  ])('%s slot %i -> %s', (attackType, slotIndex, expected) => {
    expect(getAttackLabel(attackType, slotIndex)).toBe(expected);
  });

  test('falls back to a generic label for an out-of-range slot', () => {
    expect(getAttackLabel(AttackType.SHURIKEN, 2)).toBe('Attack 3');
  });

  test('falls back to a generic label for an unknown attack type', () => {
    expect(getAttackLabel('not-a-real-type', 0)).toBe('Attack 1');
  });
});
