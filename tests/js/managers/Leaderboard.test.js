import { describe, expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({ default: {} }));
vi.mock('../../../resources/js/battlefield/bus.js', () => ({ bus: { emit: () => {} } }));

const { Leaderboard } = await import('@battlefield/leaderboard.js');

describe('Leaderboard.abbreviateDamage', () => {
  test('returns the raw number as string for values under 1000', () => {
    expect(Leaderboard.abbreviateDamage(0)).toBe('0');
    expect(Leaderboard.abbreviateDamage(999)).toBe('999');
  });

  test('rounds to nearest K for values 1000–999999', () => {
    expect(Leaderboard.abbreviateDamage(1000)).toBe('1K');
    expect(Leaderboard.abbreviateDamage(1500)).toBe('2K');
    expect(Leaderboard.abbreviateDamage(999499)).toBe('999K');
  });

  test('uses one decimal place for M values under 10M', () => {
    expect(Leaderboard.abbreviateDamage(1_000_000)).toBe('1.0M');
    expect(Leaderboard.abbreviateDamage(9_500_000)).toBe('9.5M');
  });

  test('rounds to nearest integer for M values 10M and above', () => {
    expect(Leaderboard.abbreviateDamage(10_000_000)).toBe('10M');
    expect(Leaderboard.abbreviateDamage(12_600_000)).toBe('13M');
  });
});
