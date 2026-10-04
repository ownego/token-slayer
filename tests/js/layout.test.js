import { expect, test } from 'vitest';
import {
  computeFighterPositions,
  damageScaleMultiplier,
  fighterDisplayConfig,
  rowsNeeded,
} from '@battlefield/layout.js';
import { layoutFor } from '@battlefield/config.js';

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

test('fighterDisplayConfig moves the portrait fighter rows down with the boss block', () => {
  const shifted = layoutFor({ width: 390, height: 844, hudBand: 360 });

  expect(fighterDisplayConfig(3, 'portrait', shifted).topY)
    .toBe(fighterDisplayConfig(3, 'portrait').topY + shifted.fighterShiftY);
});

test('fighterDisplayConfig fits as many landscape fighters per row as the wider world holds', () => {
  const wide = layoutFor({ width: 844, height: 390 });

  expect(fighterDisplayConfig(3, 'landscape', wide).perRow).toBe(wide.fighters.perRowMax);
  expect(fighterDisplayConfig(3, 'landscape').perRow).toBe(14);
});

test('fighterDisplayConfig moves the landscape fighter rows with the world (a 4:3 tablet centres it)', () => {
  const tablet = layoutFor({ width: 1024, height: 768 });

  expect(fighterDisplayConfig(3, 'landscape', tablet).topY)
    .toBe(fighterDisplayConfig(3, 'landscape').topY + tablet.shiftY);
});

test('fighterDisplayConfig fits more portrait fighters per row in a wider tablet world', () => {
  const tablet = layoutFor({ width: 768, height: 1024 });

  expect(fighterDisplayConfig(3, 'portrait', tablet).perRow).toBeGreaterThan(8);
  expect(fighterDisplayConfig(3, 'portrait', layoutFor({ width: 390, height: 844 })).perRow).toBe(8);
});

test('fighterDisplayConfig keeps landscape rows on the floor when the boss drops below a short screen\'s plate', () => {
  const phone = layoutFor({ width: 844, height: 390, hudBand: 113 });

  expect(phone.shiftY).toBeGreaterThan(0);
  expect(fighterDisplayConfig(3, 'landscape', phone).topY).toBe(fighterDisplayConfig(3, 'landscape').topY);
});
