import { describe, expect, test } from 'vitest';
import { advanceStones, STONE_COLORS, STONE_MAX } from '@battlefield/boss/scripts/thanos-stones.js';

// The server hands the client {stones, stoneSchedule}: the count plus every tick
// still to come. The client only counts how many of those instants have passed.
const T1 = Date.parse('2026-09-22T02:30:00Z'); // 09:30 Asia/Ho_Chi_Minh
const T2 = Date.parse('2026-09-22T07:00:00Z'); // 14:00
const T3 = Date.parse('2026-09-22T10:50:00Z'); // 17:50

describe('advanceStones', () => {
  test('leaves the count alone before the next tick', () => {
    expect(advanceStones({ stones: 3, stoneSchedule: [T1, T2, T3] }, T1 - 1))
      .toEqual({ stones: 3, stoneSchedule: [T1, T2, T3] });
  });

  test('adds one stone and drops the tick once it passes', () => {
    expect(advanceStones({ stones: 3, stoneSchedule: [T1, T2, T3] }, T1))
      .toEqual({ stones: 4, stoneSchedule: [T2, T3] });
  });

  test('catches up several ticks at once after a sleeping tab', () => {
    expect(advanceStones({ stones: 3, stoneSchedule: [T1, T2, T3] }, T3 + 5))
      .toEqual({ stones: 6, stoneSchedule: [] });
  });

  test('never goes past the cap even if the schedule runs long', () => {
    expect(advanceStones({ stones: STONE_MAX - 1, stoneSchedule: [T1, T2] }, T3))
      .toEqual({ stones: STONE_MAX, stoneSchedule: [] });
  });

  test('reads the comma-joined UTC string from the boot payload and broadcast', () => {
    expect(advanceStones({ stones: 3, stoneSchedule: '2026-09-22T02:30:00Z,2026-09-22T07:00:00Z' }, T1))
      .toEqual({ stones: 4, stoneSchedule: [T2] });
  });

  test('an empty schedule string means the gauntlet is complete', () => {
    expect(advanceStones({ stones: STONE_MAX, stoneSchedule: '' }, T3)).toEqual({ stones: STONE_MAX, stoneSchedule: [] });
  });

  test('does not mutate its input', () => {
    const state = { stones: 3, stoneSchedule: [T1, T2] };
    advanceStones(state, T2);
    expect(state).toEqual({ stones: 3, stoneSchedule: [T1, T2] });
  });
});

test('paints exactly one colour per stone up to the cap', () => {
  expect(STONE_COLORS).toHaveLength(STONE_MAX);
});
