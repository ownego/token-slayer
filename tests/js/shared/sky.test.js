import { expect, test } from 'vitest';
import { ambientAt, mixHex, moonPhase, skyColorsAt, skyXY, sunPosition } from '@battlefield/shared/sky.js';

const HANOI = [21.03, 105.85];
const at = (iso) => new Date(iso);

test('the sun is well up at local noon and below the horizon at local midnight (Hanoi, equinox)', () => {
  expect(sunPosition(at('2026-03-20T05:05:00Z'), ...HANOI).el).toBeGreaterThan(60);   // 12:05 local
  expect(sunPosition(at('2026-03-20T17:00:00Z'), ...HANOI).el).toBeLessThan(-60);      // 00:00 local
});

test('sunrise on the March equinox in Hanoi falls between 05:50 and 06:20 local', () => {
  expect(sunPosition(at('2026-03-19T22:50:00Z'), ...HANOI).el).toBeLessThan(0);        // 05:50 local
  expect(sunPosition(at('2026-03-19T23:20:00Z'), ...HANOI).el).toBeGreaterThan(0);     // 06:20 local
});

test('the sun rises in the east and sets in the west', () => {
  expect(sunPosition(at('2026-03-20T00:00:00Z'), ...HANOI).az).toBeGreaterThan(60);
  expect(sunPosition(at('2026-03-20T00:00:00Z'), ...HANOI).az).toBeLessThan(120);
  expect(sunPosition(at('2026-03-20T11:00:00Z'), ...HANOI).az).toBeGreaterThan(240);
});

test('moon phase: near full on the 2026-03-03 total lunar eclipse, near new on the 2026-02-17 annular eclipse', () => {
  expect(moonPhase(at('2026-03-03T11:33:00Z'))).toBeCloseTo(0.5, 1);
  const p = moonPhase(at('2026-02-17T12:01:00Z'));
  expect(Math.min(p, 1 - p)).toBeLessThan(0.04);
});

test('sky colours move from night to day with the sun', () => {
  expect(skyColorsAt(-30)[0]).toBe('#02040c');
  expect(skyColorsAt(80)[0]).toBe('#2f6fd0');
  expect(skyColorsAt(-1)[2]).toBe('#f08a5d');   // warm horizon at sunset
  expect(ambientAt(-30)).toBe('#2a3252');
});

test('mixHex interpolates per channel', () => {
  expect(mixHex('#000000', '#ffffff', 0.5)).toBe('#808080');
});

test('skyXY: rise → left edge, transit → middle, set → right edge; horizon → horizonY, zenith → near the top', () => {
  expect(skyXY({ ha: -1.6, ha0: 1.6, el: 0 }, 960, 186)).toEqual({ x: 0, y: 186 });
  expect(skyXY({ ha: 0, ha0: 1.6, el: 90 }, 960, 186)).toEqual({ x: 480, y: 16 });
  expect(skyXY({ ha: 1.6, ha0: 1.6, el: 0 }, 960, 186).x).toBe(960);
});

test('June solstice in Hanoi (sun culminates north of the zenith): noon sits mid-screen and x only moves left→right', () => {
  const xs = [7, 9, 11, 12, 13, 15, 17].map(h => skyXY(sunPosition(new Date(Date.UTC(2026, 5, 21, h - 7)), ...HANOI), 960, 186).x);
  expect(xs[3]).toBeGreaterThan(400);
  expect(xs[3]).toBeLessThan(560);
  xs.slice(1).forEach((x, i) => expect(x).toBeGreaterThan(xs[i]));
});

test('December solstice: same monotonic arc', () => {
  const xs = [8, 10, 12, 14, 16].map(h => skyXY(sunPosition(new Date(Date.UTC(2026, 11, 21, h - 7)), ...HANOI), 960, 186).x);
  xs.slice(1).forEach((x, i) => expect(x).toBeGreaterThan(xs[i]));
});
