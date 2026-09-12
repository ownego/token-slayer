import { describe, expect, test } from 'vitest';
import { shouldSkipSummonFlourish, SUMMON_QUEUE_BURST_LIMIT } from '@battlefield/boss/summon-queue.js';

describe('shouldSkipSummonFlourish', () => {
  test('does not skip while the queue is below the burst limit', () => {
    for (let n = 0; n < SUMMON_QUEUE_BURST_LIMIT; n++) {
      expect(shouldSkipSummonFlourish(n)).toBe(false);
    }
  });

  test('skips once the queue reaches the burst limit', () => {
    expect(shouldSkipSummonFlourish(SUMMON_QUEUE_BURST_LIMIT)).toBe(true);
  });

  test('keeps skipping for any depth beyond the burst limit', () => {
    expect(shouldSkipSummonFlourish(SUMMON_QUEUE_BURST_LIMIT + 5)).toBe(true);
  });
});
