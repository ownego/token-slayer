import { describe, expect, test } from 'vitest';
import { formatHp, formatTeamStat } from '@battlefield/format.js';

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

describe('formatTeamStat', () => {
  test('Today reads to one decimal', () => {
    expect(formatTeamStat(6_190_000, 'one')).toBe('6.2M');
    expect(formatTeamStat(427_340, 'one')).toBe('427.3K');
    expect(formatTeamStat(6_000_000, 'one')).toBe('6M');
  });

  test('Month and All-time read as the whole part only, below a billion', () => {
    expect(formatTeamStat(347_310_000, 'whole')).toBe('347M');
    expect(formatTeamStat(643_800_000, 'whole')).toBe('643M');
    expect(formatTeamStat(40_990, 'whole')).toBe('40K');
  });

  test('past a billion both keep one decimal', () => {
    expect(formatTeamStat(1_234_000_000, 'whole')).toBe('1.2B');
    expect(formatTeamStat(2_150_000_000, 'one')).toBe('2.2B');
  });
});
