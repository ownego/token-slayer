import { expect, test } from 'vitest';
import {
  computeFighterPositions,
  damageScaleMultiplier,
  fighterDisplayConfig,
  rowsNeeded,
} from '@battlefield/layout.js';

test('damageScaleMultiplier grows linearly with damage and caps at +40%', () => {
  expect(damageScaleMultiplier(0, 1000)).toBe(1);
  expect(damageScaleMultiplier(500, 1000)).toBeCloseTo(1.2);
  expect(damageScaleMultiplier(1000, 1000)).toBeCloseTo(1.4);
  expect(damageScaleMultiplier(5000, 1000)).toBeCloseTo(1.4); // overkill still caps
  expect(damageScaleMultiplier(500, 0)).toBe(1); // no boss → no scaling
});

test('rowsNeeded always reserves at least one row and rounds up', () => {
  expect(rowsNeeded(0)).toBe(1);
  expect(rowsNeeded(14)).toBe(1);
  expect(rowsNeeded(15)).toBe(2);
  expect(rowsNeeded(29)).toBe(3);
});

test('computeFighterPositions returns empty for zero fighters', () => {
  expect(computeFighterPositions(0, [80, 880], 460)).toEqual([]);
});

test('computeFighterPositions places a lone fighter at 30% across the row', () => {
  const [pos] = computeFighterPositions(1, [80, 880], 460);
  expect(pos).toEqual({ x: 320, y: 460 }); // 80 + 800 * 0.3
});

test('computeFighterPositions spreads a full row edge to edge and wraps rows', () => {
  const positions = computeFighterPositions(15, [80, 880], 460, 14, 80);
  expect(positions[0]).toEqual({ x: 80, y: 460 });
  expect(positions[13]).toEqual({ x: 880, y: 460 });
  expect(positions[14]).toEqual({ x: 320, y: 540 }); // lone fighter on row 2
});

test('fighterDisplayConfig shows handles only for small rosters', () => {
  expect(fighterDisplayConfig(14, 'landscape').showHandle).toBe(true);
  expect(fighterDisplayConfig(15, 'landscape').showHandle).toBe(false);
  expect(fighterDisplayConfig(8, 'portrait').showHandle).toBe(true);
  expect(fighterDisplayConfig(9, 'portrait').showHandle).toBe(false);
});

test('fighterDisplayConfig shrinks fighters as the roster grows (landscape)', () => {
  const sizes = [14, 28, 29].map((n) => fighterDisplayConfig(n, 'landscape').displaySize);
  expect(sizes).toEqual([45, 36, 27]);
});
