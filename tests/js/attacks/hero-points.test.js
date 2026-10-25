import { describe, test, expect } from 'vitest';
import { FIGHTER_TYPES } from '@battlefield/config.js';
import { HERO_CELL } from '@battlefield/config/heroes.js';
import { cellToWorld } from '@battlefield/attacks/hero/points.js';

const batman = FIGHTER_TYPES.find(ft => ft.key === 'batman');
const scale = 45 / 18;
const frame = flip => ({
  x: 300, y: 400, scaleX: 1, scaleY: 1,
  bodyScale: scale * batman.bodyScale, bodyY: batman.bodyOffsetY * scale, flip,
});

describe('cellToWorld', () => {
  test('a point on the preview cell\'s feet lands on the fighter\'s feet', () => {
    // Batman's working cell is 80 px wide, his feet (their shadow) on row 79, centred on column 43.
    const foot = cellToWorld({ x: 43, y: 79 }, HERO_CELL.batman, frame(false));
    expect(foot.x).toBeCloseTo(300, 6);
    expect(foot.y).toBeCloseTo(400 + 6 * scale, 6);
  });

  test('a flipped fighter mirrors the point around its own x', () => {
    const hand = { x: 64.7, y: 57.7 };
    const right = cellToWorld(hand, HERO_CELL.batman, frame(false));
    const left = cellToWorld(hand, HERO_CELL.batman, frame(true));
    expect(left.x - 300).toBeCloseTo(300 - right.x, 6);
    expect(left.y).toBeCloseTo(right.y, 6);
  });

  test('the 80 px heroes map their cell with the same pad as their strips', () => {
    expect(HERO_CELL['iron-man']).toEqual({ x: 10, y: -23, scale: 1, rowShift: 0 });
  });
});
