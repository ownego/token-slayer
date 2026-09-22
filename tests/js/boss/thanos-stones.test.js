import { describe, expect, test } from 'vitest';
import { advanceStones, STONE_MAX } from '@battlefield/boss/scripts/thanos-stones.js';

// The server hands the client {stones, nextStoneAt}; the client only has to notice
// when nextStoneAt has passed and step forward in 24h increments (no DST in Vietnam).
const T0 = Date.parse('2026-09-22T02:30:00Z'); // a 09:30 Asia/Ho_Chi_Minh instant
const DAY = 24 * 60 * 60 * 1000;

describe('advanceStones', () => {
  test('leaves the state alone before the next instant', () => {
    const state = { stones: 1, nextStoneAt: T0 };
    expect(advanceStones(state, T0 - 1)).toEqual(state);
  });

  test('adds one stone and rolls nextStoneAt forward a day when the instant passes', () => {
    expect(advanceStones({ stones: 1, nextStoneAt: T0 }, T0)).toEqual({ stones: 2, nextStoneAt: T0 + DAY });
  });

  test('catches up several days at once after a sleeping tab', () => {
    expect(advanceStones({ stones: 1, nextStoneAt: T0 }, T0 + 2 * DAY + 5)).toEqual({ stones: 4, nextStoneAt: T0 + 3 * DAY });
  });

  test('stops at the cap and clears nextStoneAt', () => {
    expect(advanceStones({ stones: STONE_MAX - 1, nextStoneAt: T0 }, T0 + 10 * DAY)).toEqual({ stones: STONE_MAX, nextStoneAt: null });
  });

  test('a capped state stays put', () => {
    const capped = { stones: STONE_MAX, nextStoneAt: null };
    expect(advanceStones(capped, T0 + 99 * DAY)).toEqual(capped);
  });

  test('accepts nextStoneAt as an ISO string and returns a number', () => {
    expect(advanceStones({ stones: 0, nextStoneAt: '2026-09-22T02:30:00Z' }, T0)).toEqual({ stones: 1, nextStoneAt: T0 + DAY });
  });

  test('does not mutate its input', () => {
    const state = { stones: 0, nextStoneAt: T0 };
    advanceStones(state, T0);
    expect(state).toEqual({ stones: 0, nextStoneAt: T0 });
  });
});
