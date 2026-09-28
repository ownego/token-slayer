import { expect, test } from 'vitest';
import { teamReducer, youRow, msUntilLocalMidnight } from '@battlefield/hud/index.js';

const start = { today: 100, month: 1000, allTime: 5000, board: new Map([[1, 50], [2, 30]]) };

test('a hit adds to every team window and to the hitter on this boss', () => {
  const s = teamReducer(start, { user_id: 2, damage: 40 });
  expect([s.today, s.month, s.allTime]).toEqual([140, 1040, 5040]);
  expect(s.board.get(2)).toBe(70);
});

test('your row: damage on this boss, share and ordinal rank', () => {
  const s = teamReducer(start, { user_id: 2, damage: 40 });   // 2 → 70, 1 → 50
  expect(youRow(s, 2)).toEqual({ damage: 70, share: 70 / 120, rankLabel: '1st' });
});

test('a brand-new user with no damage gets zeros and a dash, never NaN', () => {
  expect(youRow({ ...start, board: new Map() }, 9)).toEqual({ damage: 0, share: 0, rankLabel: '–' });
});

test('msUntilLocalMidnight counts down to the next Asia/Ho_Chi_Minh midnight', () => {
  // 23:59:30 local time -> 30s until midnight
  const now = new Date('2026-09-27T16:59:30.000Z'); // 23:59:30 UTC+7
  expect(msUntilLocalMidnight(now, 'Asia/Ho_Chi_Minh')).toBe(30000);
});
