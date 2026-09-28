import { describe, expect, test } from 'vitest';
import { BIRD_FRAMES, castlePixels, cloudPixels, faceTone, flockLayout, hillProfile, mountainProfile } from '@battlefield/environment/pixel-art.js';

/** Seeded 0..1 generator, same shape as environment/index.js's makeRnd. */
const seeded = seed => { let s = seed; return () => (s = (s * 16807) % 2147483647) / 2147483647; };

describe('cloudPixels', () => {
  const grid = cloudPixels(48, 18, seeded(3));

  test('is rounded: the bounding box corners are empty, so no square block corners', () => {
    const h = grid.length;
    const w = grid[0].length;
    [[0, 0], [0, w - 1], [h - 1, 0], [h - 1, w - 1]].forEach(([y, x]) => expect(grid[y][x]).toBe(0));
  });

  test('is shaded: a lit top, a body and a shadowed base', () => {
    const tones = new Set(grid.flat().filter(Boolean));
    expect([...tones].sort()).toEqual([1, 2, 3]);
  });

  test('is billowy: the top row is narrower than the widest row', () => {
    const widths = grid.map(r => r.filter(Boolean).length);
    const firstRow = widths.find(w => w > 0);
    expect(firstRow).toBeLessThan(Math.max(...widths) * 0.7);
  });
});

describe('mountainProfile', () => {
  const p = mountainProfile(400, 20, 80, seeded(7));

  test('stays inside its band', () => {
    p.forEach(y => { expect(y).toBeGreaterThanOrEqual(20); expect(y).toBeLessThanOrEqual(80); });
  });

  test('reads as mountains, not buildings: slopes instead of long flat tops and sheer steps', () => {
    let flat = 0;
    let longestFlat = 0;
    for (let x = 1; x < p.length; x++) {
      expect(Math.abs(p[x] - p[x - 1])).toBeLessThanOrEqual(3);
      flat = p[x] === p[x - 1] ? flat + 1 : 0;
      longestFlat = Math.max(longestFlat, flat);
    }
    expect(longestFlat).toBeLessThan(10);
  });

  test('has more than one peak', () => {
    let peaks = 0;
    for (let x = 2; x < p.length - 2; x++) {
      if (p[x] < p[x - 2] && p[x] <= p[x + 2] && p[x] < p[x + 2] + 1 && p[x] <= Math.min(...p.slice(Math.max(0, x - 20), x + 20))) { peaks++; }
    }
    expect(peaks).toBeGreaterThan(1);
  });
});

test('hills roll gently and carry pine trees on their crest', () => {
  const { profile, trees } = hillProfile(400, 40, 70, seeded(5));
  for (let x = 1; x < profile.length; x++) { expect(Math.abs(profile[x] - profile[x - 1])).toBeLessThanOrEqual(2); }
  expect(trees.length).toBeGreaterThan(5);
  trees.forEach(t => expect(t.y).toBe(profile[t.x]));
});

test('the castle is a keep with towers taller than its curtain wall', () => {
  const c = castlePixels();
  const colHeight = x => c.length - c.findIndex(row => row[x] > 0);
  const w = c[0].length;
  expect(colHeight(Math.floor(w * 0.1))).toBeGreaterThan(colHeight(Math.floor(w * 0.3)));
  expect(new Set(c.flat().filter(Boolean)).size).toBeGreaterThanOrEqual(3); // lit, mid, dark faces
});

test('a bird flaps: two frames, wings up and down', () => {
  expect(BIRD_FRAMES).toHaveLength(2);
  expect(BIRD_FRAMES[0].join('|')).not.toBe(BIRD_FRAMES[1].join('|'));
});

test('a flock flies in a loose V behind its leader', () => {
  const f = flockLayout(6, seeded(9));
  expect(f).toHaveLength(6);
  expect(f[0]).toMatchObject({ dx: 0, dy: 0 });
  f.slice(1).forEach(b => expect(b.dx).toBeLessThan(0)); // trailing the leader
  expect(f.some(b => b.dy < 0) && f.some(b => b.dy > 0)).toBe(true); // both arms of the V
});

test('mountain faces are shaded by the slope around a column, so a one-pixel wobble does not stripe them', () => {
  const zigzag = Array.from({ length: 40 }, (_, x) => 50 + (x % 2)); // a flat stretch wobbling by 1px
  const tones = zigzag.map((_, x) => faceTone(zigzag, x));
  expect(new Set(tones)).toEqual(new Set([2])); // body tone throughout, no stripes

  const ramp = Array.from({ length: 40 }, (_, x) => 80 - x); // rising to the right: faces the light
  expect(faceTone(ramp, 20)).toBe(3);
  const fall = Array.from({ length: 40 }, (_, x) => 40 + x); // falling to the right: in shadow
  expect(faceTone(fall, 20)).toBe(1);
});
