import { expect, test } from 'vitest';
import { boardEffects } from '@battlefield/hud/board-view.js';

const board = rows => ({ rows: rows.map(([id, rank]) => ({ id, rank, climbed: 0, passedIds: [] })), leader: rows[0]?.[0] ?? null });

test('a rank change rolls the digit up (green) for a climb and down (red) for a drop', () => {
  const fx = boardEffects(board([[1, 0], [2, 1]]), board([[2, 0], [1, 1]]));
  expect(fx.rolls).toEqual([{ id: 2, from: 2, to: 1, dir: 'up' }, { id: 1, from: 1, to: 2, dir: 'down' }]);
});

test('a new #1 is reported once', () => {
  expect(boardEffects(board([[1, 0]]), board([[2, 0], [1, 1]])).newLeader).toBe(2);
  expect(boardEffects(board([[2, 0]]), board([[2, 0]])).newLeader).toBeNull();
});

test('a climb reports who it passed', () => {
  // id 3 climbs from rank 2 (3rd) to rank 0 (1st), passing id 1 (rank 0->1) and id 2 (rank 1->2)
  const fx = boardEffects(board([[1, 0], [2, 1], [3, 2]]), board([[3, 0], [1, 1], [2, 2]]));
  expect(fx.climbs).toEqual([{ id: 3, by: 2 }]);
  expect(fx.passed.sort()).toEqual([1, 2]);
});

test('the collapsed sheet never rolls a row past the limit — it simply is not there', () => {
  // rankBoard already caps rows to `limit`; a row dropping out of the top-5
  // has no representation in `next.rows` at all, so it cannot appear in `rolls`.
  const prev = board([[1, 0], [2, 1], [3, 2], [4, 3], [5, 4], [6, 5]]).rows.slice(0, 5);
  const next = board([[2, 0], [1, 1], [3, 2], [4, 3], [5, 4]]).rows; // id 6 fell out of the top 5
  const fx = boardEffects({ rows: prev, leader: 1 }, { rows: next, leader: 2 });
  expect(fx.rolls.some(r => r.id === 6)).toBe(false);
});
