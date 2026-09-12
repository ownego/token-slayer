import { describe, expect, test } from 'vitest';
import { isHealRoll } from '@battlefield/attacks/priest-heal.js';

describe('isHealRoll', () => {
  test('even damage is not a heal roll', () => {
    for (const damage of [2, 4, 100, 0]) {
      expect(isHealRoll(damage)).toBe(false);
    }
  });

  test('odd damage is a heal roll', () => {
    for (const damage of [1, 3, 99, 12345]) {
      expect(isHealRoll(damage)).toBe(true);
    }
  });
});
