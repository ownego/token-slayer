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

test('carries a boss script state through the round-trip without knowing its keys', () => {
  const scene = {
    bossState: {
      number: 7,
      name: 'ThaNode',
      currentHp: 500,
      maxHp: 1000,
      script: { stones: 2, stoneSchedule: [1_790_000_000_000] },
    },
    fighters: new Map(),
    charges: new Map(),
    layout: { logicalWidth: 960, logicalHeight: 540 },
  };
  expect(snapshotState({}, scene).boss).toEqual({
    number: 7,
    name: 'ThaNode',
    currentHp: 500,
    maxHp: 1000,
    script: { stones: 2, stoneSchedule: [1_790_000_000_000] },
  });
});

test('omits the script key for a boss that has none, keeping the boot payload shape', () => {
  const scene = {
    bossState: { number: 2, name: 'GRUMPUS', currentHp: 500, maxHp: 1000 },
    fighters: new Map(),
    charges: new Map(),
    layout: { logicalWidth: 960, logicalHeight: 540 },
  };
  expect(snapshotState({}, scene).boss).toEqual({ number: 2, name: 'GRUMPUS', currentHp: 500, maxHp: 1000 });
});

test('captures each fighter\'s current live minion count, so an orientation-change reboot does not silently drop a live subagent swarm back to 0', () => {
  const scene = fakeScene({
    minions: { byUser: new Map([[7, [{}, {}, {}]]]) },
  });

  const next = snapshotState({}, scene);

  expect(next.fighters[0].agentCount).toBe(3);
});

test('agentCount defaults to 0 for a fighter the minions manager has no entry for', () => {
  const scene = fakeScene({
    minions: { byUser: new Map() },
  });

  const next = snapshotState({}, scene);

  expect(next.fighters[0].agentCount).toBe(0);
});

test('agentCount defaults to 0 when the scene has no minions manager at all', () => {
  const next = snapshotState({}, fakeScene());

  expect(next.fighters[0].agentCount).toBe(0);
});
