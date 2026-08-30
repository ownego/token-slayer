import { describe, expect, test } from 'vitest';
import { formatHp } from '@battlefield/format.js';

describe('formatHp', () => {
  test('returns the raw number as string for values under 1000', () => {
    expect(formatHp(0)).toBe('0');
    expect(formatHp(999)).toBe('999');
  });

  test('uses one decimal place for K values', () => {
    expect(formatHp(1500)).toBe('1.5K');
    expect(formatHp(999_499)).toBe('999.5K');
  });

  test('uses up to two decimal places for M values', () => {
    expect(formatHp(1_000_000)).toBe('1M');
    expect(formatHp(2_500_000)).toBe('2.5M');
  });

  test('uses up to two decimal places for B values', () => {
    expect(formatHp(1_000_000_000)).toBe('1B');
    expect(formatHp(1_164_930_000)).toBe('1.16B');
  });
});
