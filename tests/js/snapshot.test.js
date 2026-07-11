import { expect, test } from 'vitest';
import { snapshotState } from '@battlefield/snapshot.js';

function fakeScene(overrides = {}) {
  return {
    bossState: { number: 2, name: 'GRUMPUS', currentHp: 500, maxHp: 1000 },
    leaderboard: { getRanked: () => [[7, 1200, 'alice']] },
    fighters: new Map([
      [7, { id: 7, handleText: 'alice', avatarUrl: '/avatars/7', ftype: { key: 'ninjagirl' } }],
    ]),
    charges: new Map(),
    damageTotals: new Map([[7, 1200]]),
    ...overrides,
  };
}

test('returns current state untouched when there is no scene', () => {
  const state = { boss: { number: 1 } };
  expect(snapshotState(state, null)).toBe(state);
});

test('captures boss, leaderboard, and fighters from the scene', () => {
  const next = snapshotState({}, fakeScene());

  expect(next.boss).toEqual({ number: 2, name: 'GRUMPUS', currentHp: 500, maxHp: 1000 });
  expect(next.leaderboard).toEqual([{ userId: 7, damage: 1200, handle: 'alice' }]);
  expect(next.fighters).toHaveLength(1);
  expect(next.fighters[0]).toMatchObject({ id: 7, handle: 'alice', avatarUrl: '/avatars/7' });
});

test('preserves each fighter character so a reboot does not re-roll it', () => {
  const next = snapshotState({}, fakeScene());

  expect(next.fighters[0].character).toBe('ninjagirl');
});

test('preserves damage totals so fighter sizes survive a reboot', () => {
  const next = snapshotState({}, fakeScene());

  expect(next.damageTotals).toEqual([[7, 1200]]);
});

test('preserves active charging state', () => {
  const scene = fakeScene({ charges: new Map([[7, { activity: '$ npm install' }]]) });

  const next = snapshotState({}, scene);

  expect(next.fighters[0].charging).toEqual({ activity: '$ npm install' });
});

test('captures currentUserId from the scene', () => {
  const next = snapshotState({ currentUserId: null }, fakeScene({ currentUserId: 42 }));

  expect(next.currentUserId).toBe(42);
});

test('falls back to currentState.currentUserId when scene has none', () => {
  const next = snapshotState({ currentUserId: 99 }, fakeScene({ currentUserId: undefined }));

  expect(next.currentUserId).toBe(99);
});

test('normalizes fighter pos to layout dimensions', () => {
  const scene = fakeScene({
    layout: { logicalWidth: 800, logicalHeight: 400 },
    fighters: new Map([
      [7, { id: 7, handleText: 'alice', avatarUrl: '/avatars/7', ftype: { key: 'ninjagirl' }, pos: { x: 400, y: 200 } }],
    ]),
  });

  const next = snapshotState({}, scene);

  expect(next.fighters[0].position).toEqual({ x: 0.5, y: 0.5 });
});

test('fighter position is null when pos is not set', () => {
  const scene = fakeScene({
    layout: { logicalWidth: 800, logicalHeight: 400 },
    fighters: new Map([
      [7, { id: 7, handleText: 'alice', avatarUrl: '/avatars/7', ftype: { key: 'ninjagirl' }, pos: null }],
    ]),
  });

  const next = snapshotState({}, scene);

  expect(next.fighters[0].position).toBeNull();
});
