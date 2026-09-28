import { describe, expect, test } from 'vitest';
import { mountainPixel, mountainProfile } from '@battlefield/environment/pixel-art.js';

const seeded = seed => { let s = seed; return () => (s = (s * 16807) % 2147483647) / 2147483647; };

test('the ridge is jagged at every scale: small notches ride on the big slopes', () => {
  const p = mountainProfile(600, 20, 120, seeded(21));
  let reversals = 0;
  for (let x = 2; x < p.length; x++) {
    const a = Math.sign(p[x - 1] - p[x - 2]);
    const b = Math.sign(p[x] - p[x - 1]);
    if (a && b && a !== b) { reversals++; }
  }
  expect(reversals).toBeGreaterThan(40); // plenty of little crags, not straight tent sides
});

describe('mountainPixel', () => {
  const sky = Array.from({ length: 200 }, (_, x) => 20 + Math.abs(100 - x)); // one peak at x=100, y=20
  const band = { lo: 20, hi: 120, base: 140 };

  test('snow caps only the high ground, with a ragged edge', () => {
    expect(mountainPixel(sky, 100, 21, band).snow).toBe(true);
    expect(mountainPixel(sky, 30, 91, band).snow).toBe(false);
    const edge = [96, 98, 100, 102, 104].map(x => [...Array(40).keys()].find(dy => !mountainPixel(sky, x, sky[x] + dy, band).snow));
    expect(new Set(edge).size).toBeGreaterThan(1);
  });

  test('the lit face is lighter than the shadowed one at the same height', () => {
    const lit = mountainPixel(sky, 80, 60, band).grey;
    const shadow = mountainPixel(sky, 120, 60, band).grey;
    expect(lit).toBeGreaterThan(shadow);
  });

  test('mist rises from the base: the lowest rows turn pale', () => {
    const mid = mountainPixel(sky, 60, 90, band).grey;
    const low = mountainPixel(sky, 60, 138, band).grey;
    expect(low).toBeGreaterThan(mid);
  });

  test('faces blend through a dither, not a hard vertical line', () => {
    const greys = new Set();
    for (let y = 50; y < 54; y++) { for (let x = 97; x <= 103; x++) { greys.add(mountainPixel(sky, x, y + 30, band).grey); } }
    expect(greys.size).toBeGreaterThanOrEqual(2);
  });
});

test('the ridge uses its whole band without being sliced flat at the top or bottom', () => {
  const p = mountainProfile(600, 20, 120, seeded(21));
  const atTop = p.filter(y => y === 20).length;
  const atBottom = p.filter(y => y === 120).length;
  expect(Math.min(...p)).toBeLessThanOrEqual(24); // real peaks reach the top of the band
  expect(atTop).toBeLessThan(600 * 0.03);
  expect(atBottom).toBeLessThan(600 * 0.03);
});
