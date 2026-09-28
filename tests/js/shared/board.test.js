import { expect, test } from 'vitest';
import { rankBoard } from '@battlefield/shared/board.js';

const m = o => new Map(Object.entries(o).map(([k, v]) => [Number(k), v]));

test('rows are ranked by damage with shares of the total', () => {
  const b = rankBoard(new Map(), m({ 1: 300, 2: 100, 3: 600 }));
  expect(b.rows.map(r => r.id)).toEqual([3, 1, 2]);
  expect(b.rows[0].share).toBeCloseTo(0.6);
  expect(b.leader).toBe(3);
});

test('a climb reports how many places and whom it passed', () => {
  const prev = new Map([[1, 0], [2, 1], [3, 2]]);
  const b = rankBoard(prev, m({ 1: 100, 2: 90, 3: 500 }));
  const r3 = b.rows.find(r => r.id === 3);
  expect(r3.climbed).toBe(2);
  expect(r3.passedIds.sort()).toEqual([1, 2]);
});

test('zero-damage fighters are not listed; overflow counted in more', () => {
  const b = rankBoard(new Map(), m({ 1: 0, 2: 5, 3: 4, 4: 3, 5: 2, 6: 1, 7: 1 }), 5);
  expect(b.rows.map(r => r.id)).not.toContain(1);
  expect(b.more).toBe(1);
});

test('an empty board has no leader and no NaN shares', () => {
  const b = rankBoard(new Map(), new Map());
  expect(b.rows).toEqual([]);
  expect(b.leader).toBeNull();
});
