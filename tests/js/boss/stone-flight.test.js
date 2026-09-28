import { describe, expect, test } from 'vitest';
import { arcPoints, worldToScreen } from '@battlefield/boss/scripts/stone-flight.js';

describe('worldToScreen', () => {
  test('maps a world point through the camera view onto the canvas box on the page', () => {
    const view = { x: 0, y: 0, width: 960, height: 540 };
    const canvas = { left: 100, top: 50, width: 1920, height: 1080 };

    expect(worldToScreen({ x: 480, y: 270 }, view, canvas)).toEqual({ x: 1060, y: 590 });
  });

  test('respects a camera that shows only part of the world (zoomed or panned)', () => {
    const view = { x: 240, y: 135, width: 480, height: 270 };
    const canvas = { left: 0, top: 0, width: 960, height: 540 };

    expect(worldToScreen({ x: 480, y: 270 }, view, canvas)).toEqual({ x: 480, y: 270 });
  });
});

describe('arcPoints', () => {
  const from = { x: 0, y: 300 };
  const to = { x: 200, y: 0 };

  test('starts at the boss and ends in the socket', () => {
    const points = arcPoints(from, to, 80, 8);

    expect(points[0]).toEqual(from);
    expect(points.at(-1)).toEqual(to);
    expect(points).toHaveLength(9);
  });

  test('bows above the straight line so the gem swoops rather than slides', () => {
    const points = arcPoints(from, to, 80, 8);
    const mid = points[4];

    expect(mid.y).toBeLessThan((from.y + to.y) / 2 - 40);
  });
});
