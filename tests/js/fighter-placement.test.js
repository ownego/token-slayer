import { expect, test } from 'vitest';
import { LAYOUTS } from '@battlefield/config.js';
import { resolveFighterPlacement } from '@battlefield/fighter-placement.js';

const BOSS_TYPE = { frameWidth: 32, frameHeight: 32, scale: 4 };
const ctx = { layout: LAYOUTS.landscape, bossType: BOSS_TYPE, fsize: 45 };
const GRID = { x: 100, y: 490 };

test('falls back to the grid slot when the fighter has no saved position', () => {
  expect(resolveFighterPlacement(null, GRID, ctx)).toEqual({ pos: GRID, isCustom: false });
  expect(resolveFighterPlacement(undefined, GRID, ctx)).toEqual({ pos: GRID, isCustom: false });
});

test('restores a saved position by denormalizing it against the current layout', () => {
  const saved = { x: 0.5, y: 0.87 };

  const { pos, isCustom } = resolveFighterPlacement(saved, GRID, ctx);

  expect(isCustom).toBe(true);
  expect(pos.x).toBeCloseTo(0.5 * LAYOUTS.landscape.logicalWidth);
  expect(pos.y).toBeCloseTo(0.87 * LAYOUTS.landscape.logicalHeight);
});

test('snaps a saved position that is no longer valid instead of resetting to the grid', () => {
  // Dead centre of the boss/HP-bar column — always an invalid target.
  const saved = { x: 0.5, y: 0.37 };

  const { pos, isCustom } = resolveFighterPlacement(saved, GRID, ctx);

  expect(isCustom).toBe(true);
  expect(pos).not.toEqual(GRID);
});

test('keeps a saved position custom even when a bigger fighter widens the margins', () => {
  // Same saved spot, resolved at the largest damage-grown size.
  const saved = { x: 0.04, y: 0.9 };
  const grownCtx = { ...ctx, fsize: 63 };

  const { pos, isCustom } = resolveFighterPlacement(saved, GRID, grownCtx);

  expect(isCustom).toBe(true);
  expect(pos).not.toEqual(GRID);
});

test('ignores a malformed saved position rather than placing the fighter at NaN', () => {
  expect(resolveFighterPlacement({ x: 'nope', y: 0.5 }, GRID, ctx)).toEqual({ pos: GRID, isCustom: false });
  expect(resolveFighterPlacement({}, GRID, ctx)).toEqual({ pos: GRID, isCustom: false });
});
