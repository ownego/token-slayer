import { describe, expect, test } from 'vitest';
import { barHeights, compact, cooldownLabel, liveTokens, readout } from '@battlefield/sheet/hourly-bars.js';

const b = [{ hour: '14:00', damage: 0 }, { hour: '15:00', damage: 50 }, { hour: '16:00', damage: 100 }];

test('bars scale to the busiest hour with a visible floor', () => {
  expect(barHeights(b)).toEqual([5, 50, 100]);
  expect(barHeights([{ hour: '0', damage: 0 }])).toEqual([5]);
});

test('the heading shows the total until a bar is pinned', () => {
  expect(readout(b, null)).toEqual({ value: 150, label: 'in total' });
  expect(readout(b, 1)).toEqual({ value: 50, label: 'at 15:00' });
});

test('the refresh button stays disabled through the cooldown and re-enables at zero', () => {
  expect(cooldownLabel(42)).toEqual({ disabled: true, text: 'Refresh again in 42s' });
  expect(cooldownLabel(0)).toEqual({ disabled: false, text: '' });
});

test('only my own hits move my token ledger', () => {
  const s = { me: 1, output: 0, input: 0, cacheWritten: 0, cacheRead: 0 };
  const hit = { user_id: 1, damage: 10, input_tokens: 2, cache_creation_input_tokens: 30, cache_read_input_tokens: 400 };
  expect(liveTokens(s, hit)).toMatchObject({ output: 10, input: 2, cacheWritten: 30, cacheRead: 400 });
  expect(liveTokens(s, { ...hit, user_id: 2 })).toEqual(s);
});

describe('compact', () => {
  test('matches the mockup fmt() and the PHP CompactNumber', () => {
    expect(compact(949)).toBe('949');
    expect(compact(780400)).toBe('780K');
    expect(compact(1260000)).toBe('1.26M');
    expect(compact(7000000)).toBe('7M');
    expect(compact(2150000000)).toBe('2.15B');
    expect(compact(0)).toBe('0');
  });
});
