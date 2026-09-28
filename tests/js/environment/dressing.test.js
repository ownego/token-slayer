import { expect, test } from 'vitest';
import { dressingLayout } from '@battlefield/environment/dressing.js';

test('placement comes from the world box, in landscape and portrait', () => {
  const land = dressingLayout({ width: 960, height: 540, horizonY: 186 });
  expect(land.braziers.map(b => b.x)).toEqual([53, 907]);
  expect(land.braziers[0].y).toBe(257);
  const port = dressingLayout({ width: 540, height: 960, horizonY: 267 });
  expect(port.braziers.map(b => b.x)).toEqual([30, 510]);
  expect(port.count.motes).toBe(14);
});

test('the castle is drawn a little bigger than the rest of the dressing, at a whole-number scale so it stays crisp', () => {
  for (const width of [960, 1440, 1920]) {
    const { keep } = dressingLayout({ width, height: 540, horizonY: 186 });
    expect(Number.isInteger(keep.castleUnit)).toBe(true);
    expect(keep.castleUnit).toBeGreaterThan(keep.unit);
    expect(keep.castleUnit).toBeLessThanOrEqual(Math.ceil(keep.unit * 1.4));
  }
});
