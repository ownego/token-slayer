import { describe, expect, test } from 'vitest';
import { agoLabel, bestComboKey, coarseAgo, crewCounts, crewReducer, easeCount, probeLabel, rovingIndex } from '@battlefield/sheet/sheet-ui.js';

describe('probeLabel', () => {
  test('a moved reading shows before → after, marked changed', () => {
    expect(probeLabel({ status: 'changed', from: 42, to: 45 })).toEqual({ text: '42% → 45%', cls: 'changed' });
  });
  test('a failed probe says so, marked failed', () => {
    expect(probeLabel({ status: 'failed', error: 'token rejected' })).toEqual({ text: 'failed', cls: 'failed' });
  });
  test('an unchanged reading and a cooldown each read plainly', () => {
    expect(probeLabel({ status: 'unchanged', from: 42, to: 42 }).text).toBe('no change');
    expect(probeLabel({ status: 'cooldown', seconds: 41 }).text).toBe('wait 41s');
  });
});

test('easeCount eases out: most of the way there early, exactly there at the end', () => {
  expect(easeCount(0, 1000, 0)).toBe(0);
  expect(easeCount(0, 1000, 0.5)).toBe(875);
  expect(easeCount(0, 1000, 1)).toBe(1000);
});

test('agoLabel reads like the mockup\'s refresh stamp', () => {
  expect(agoLabel(20)).toBe('just now');
  expect(agoLabel(125)).toBe('2m ago');
  expect(agoLabel(3 * 3600 + 12 * 60)).toBe('3h 12m ago');
  expect(agoLabel(3 * 86400 + 10 * 3600)).toBe('3d 10h ago');
});

describe('crew', () => {
  test('busy is the agents in a tool call right now; the rest of the count is idle', () => {
    let s = { total: 3, busy: new Set() };
    s = crewReducer(s, { type: 'tool', agent_id: 'a', busy: true });
    s = crewReducer(s, { type: 'tool', agent_id: 'b', busy: true });
    s = crewReducer(s, { type: 'tool', agent_id: 'a', busy: false });

    expect(crewCounts(s)).toEqual({ busy: 1, idle: 2, total: 3 });
  });
  test('a count drop never leaves more busy than there are minions', () => {
    let s = { total: 2, busy: new Set(['a', 'b']) };
    s = crewReducer(s, { type: 'count', count: 1 });

    expect(crewCounts(s)).toEqual({ busy: 1, idle: 0, total: 1 });
  });
  test('no minions at all clears the busy set', () => {
    expect(crewReducer({ total: 2, busy: new Set(['a']) }, { type: 'count', count: 0 }).busy.size).toBe(0);
  });
});

test('the best combo is kept per local day', () => {
  expect(bestComboKey(new Date(2026, 8, 27, 23, 59))).toBe('ts:best-combo:2026-09-27');
  expect(bestComboKey(new Date(2026, 8, 28, 0, 1))).toBe('ts:best-combo:2026-09-28');
});

test('rovingIndex wraps arrow keys around a row and ignores other keys', () => {
  expect(rovingIndex(0, 'ArrowLeft', 6)).toBe(5);
  expect(rovingIndex(5, 'ArrowRight', 6)).toBe(0);
  expect(rovingIndex(2, 'ArrowDown', 6, { vertical: true })).toBe(3);
  expect(rovingIndex(2, 'Enter', 6)).toBeNull();
});

test('the last-hit stamp reads coarsely: just now, whole minutes, whole hours, whole days', () => {
  expect(coarseAgo(37)).toBe('just now');
  expect(coarseAgo(61)).toBe('1m ago');
  expect(coarseAgo(125)).toBe('2m ago');
  expect(coarseAgo(3600 + 59 * 60)).toBe('1h ago');
  expect(coarseAgo(2 * 3600)).toBe('2h ago');
  expect(coarseAgo(3 * 86400 + 5 * 3600)).toBe('3d ago');
});
