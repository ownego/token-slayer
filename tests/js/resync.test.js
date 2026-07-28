import { expect, test } from 'vitest';
import { driftedPositions } from '@battlefield/resync.js';

test('flags a fighter whose local position no longer matches the server value', () => {
  const local = [{ id: 1, x: 0.2, y: 0.3, waypointMoving: false }];
  const server = [{ user_id: 1, x: 0.8, y: 0.3 }];

  expect(driftedPositions(local, server)).toEqual([{ user_id: 1, x: 0.8, y: 0.3 }]);
});

test('ignores a fighter whose local position already matches the server value', () => {
  const local = [{ id: 1, x: 0.5, y: 0.5, waypointMoving: false }];
  const server = [{ user_id: 1, x: 0.5, y: 0.5 }];

  expect(driftedPositions(local, server)).toEqual([]);
});

test('tolerates float noise below the epsilon threshold', () => {
  const local = [{ id: 1, x: 0.5001, y: 0.4999, waypointMoving: false }];
  const server = [{ user_id: 1, x: 0.5, y: 0.5 }];

  expect(driftedPositions(local, server)).toEqual([]);
});

test('skips a fighter mid local waypoint animation, even if drifted', () => {
  const local = [{ id: 1, x: 0.2, y: 0.3, waypointMoving: true }];
  const server = [{ user_id: 1, x: 0.8, y: 0.3 }];

  expect(driftedPositions(local, server)).toEqual([]);
});

test('skips a server position for a fighter not currently in the scene', () => {
  const local = [];
  const server = [{ user_id: 1, x: 0.8, y: 0.3 }];

  expect(driftedPositions(local, server)).toEqual([]);
});

test('returns an empty array when there are no server positions to reconcile', () => {
  const local = [{ id: 1, x: 0.2, y: 0.3, waypointMoving: false }];

  expect(driftedPositions(local, [])).toEqual([]);
});
