import { describe, expect, test } from 'vitest';
import { cloudBank, cloudPixels, faceTone, forestLayout, mountainProfile, nextSkyEvent, treePixels } from '@battlefield/environment/pixel-art.js';

const seeded = seed => { let s = seed; return () => (s = (s * 16807) % 2147483647) / 2147483647; };

/** The top edge of a tone grid: first inked row per column (-1 where empty). */
const topEdge = grid => grid[0].map((_, x) => grid.findIndex(r => r[x] > 0));

describe('clouds', () => {
  test('a cloud\'s top billows: several scallops along its top edge, not one smooth dome', () => {
    const edge = topEdge(cloudPixels(60, 20, seeded(3))).filter(y => y >= 0);
    let dips = 0;
    for (let x = 2; x < edge.length - 2; x++) {
      if (edge[x] > edge[x - 2] && edge[x] > edge[x + 2]) { dips++; }
    }
    expect(dips).toBeGreaterThanOrEqual(3);
  });

  test('a cloud bank groups several clouds into one wider cluster', () => {
    const bank = cloudBank(140, 30, seeded(8));
    const edge = topEdge(bank);
    const inked = edge.filter(y => y >= 0).length;
    expect(inked).toBeGreaterThan(140 * 0.7);
    // lobes: the bank's crest rises and falls more than once across its width
    let crests = 0;
    for (let x = 6; x < edge.length - 6; x++) {
      if (edge[x] >= 0 && edge[x] < edge[x - 6] && edge[x] <= edge[x + 6]) { crests++; }
    }
    expect(crests).toBeGreaterThan(1);
  });
});

test('mountain peaks vary in height and spacing, so the range doesn\'t look repeated', () => {
  const p = mountainProfile(800, 20, 120, seeded(7));
  const peaks = [];
  for (let x = 1; x < p.length - 1; x++) {
    if (p[x] < p[x - 1] && p[x] <= p[x + 1] && p[x] === Math.min(...p.slice(Math.max(0, x - 30), x + 30))) { peaks.push(x); }
  }
  expect(peaks.length).toBeGreaterThanOrEqual(3);
  const heights = peaks.map(x => p[x]);
  expect(Math.max(...heights) - Math.min(...heights)).toBeGreaterThan((120 - 20) * 0.15);
  const gaps = peaks.slice(1).map((x, i) => x - peaks[i]);
  const mean = gaps.reduce((a, b) => a + b, 0) / gaps.length;
  const spread = Math.sqrt(gaps.reduce((a, g) => a + (g - mean) ** 2, 0) / gaps.length);
  expect(spread / mean).toBeGreaterThan(0.15);
});

describe('forest', () => {
  const profile = Array.from({ length: 600 }, (_, x) => 60 + Math.round(Math.sin(x / 50) * 6));
  const trees = forestLayout(profile, seeded(4));

  test('mixes tree kinds and sizes', () => {
    expect(new Set(trees.map(t => t.kind)).size).toBeGreaterThanOrEqual(3);
    const hs = trees.map(t => t.h);
    expect(Math.max(...hs) - Math.min(...hs)).toBeGreaterThanOrEqual(5);
  });

  test('grows in clumps with clearings between them, not an even row', () => {
    const gaps = trees.slice(1).map((t, i) => t.x - trees[i].x).sort((a, b) => a - b);
    const median = gaps[Math.floor(gaps.length / 2)];
    expect(gaps[gaps.length - 1]).toBeGreaterThan(median * 3);
  });

  test('each tree stands on the hill line', () => {
    trees.forEach(t => expect(t.y).toBe(profile[t.x]));
  });

  test('every kind draws as its own shape', () => {
    const shapes = ['pine', 'round', 'tall', 'bush'].map(k => JSON.stringify(treePixels(k, 12)));
    expect(new Set(shapes).size).toBe(4);
  });
});

test('sky events (a flock, a shooting star) come soon after load, then every so often', () => {
  const rnd = seeded(2);
  const first = nextSkyEvent(true, rnd);
  const later = nextSkyEvent(false, rnd);
  expect(first).toBeGreaterThanOrEqual(4000);
  expect(first).toBeLessThanOrEqual(12000);
  expect(later).toBeGreaterThanOrEqual(15000);
  expect(later).toBeLessThanOrEqual(45000);
});

test('a rocky skyline (±2px roughness) still shades in broad faces, not column stripes', () => {
  // the same bounced random walk mountainProfile adds to its skyline
  const rnd = seeded(12);
  const sky = [];
  for (let x = 0, v = 0; x < 240; x++) {
    if (x % 3 === 0) {
      const step = rnd() < 0.5 ? -1 : 1;
      v = Math.abs(v + step) > 2 ? v - step : v + step;
    }
    sky.push(60 + v);
  }
  const tones = sky.map((_, x) => faceTone(sky, x));
  const changes = tones.slice(1).filter((t, i) => t !== tones[i]).length;
  expect(changes).toBeLessThanOrEqual(4);
});
