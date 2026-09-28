import { expect, test } from 'vitest';
import { domToWorld, footprint, resolveSpacing } from '@battlefield/shared/hud-zones.js';

const sizes = { halfW: 34, top: -90, bottom: 30 };
const overlap = (a, b) => a.r > b.l && a.l < b.r && a.b > b.t && a.t < b.b;

test('DOM rects convert to world coordinates through the canvas scale', () => {
  const canvas = { left: 100, top: 50, width: 480 };
  expect(domToWorld({ left: 100, right: 340, top: 50, bottom: 150 }, canvas, 960, 0)).toEqual({ l: 0, r: 480, t: 0, b: 200 });
});

test('two stacked fighters are pushed apart until their footprints no longer overlap', () => {
  const F = [{ x: 300, y: 400 }, { x: 305, y: 430 }];
  resolveSpacing(F, [], sizes, { iterations: 8 });
  const [a, b] = F.map(f => footprint({ x: f.nx, y: f.ny }, sizes));
  expect(overlap(a, b)).toBe(false);
});

test('a fighter under a HUD panel is pushed out of it', () => {
  const F = [{ x: 100, y: 120 }];
  const zone = { l: 0, r: 300, t: 0, b: 150 };
  resolveSpacing(F, [zone], sizes, { iterations: 4 });
  expect(overlap(footprint({ x: F[0].nx, y: F[0].ny }, sizes), zone)).toBe(false);
});

test('with room, a fighter stays on its anchor', () => {
  const F = [{ x: 200, y: 400 }];
  resolveSpacing(F, [], sizes);
  expect([F[0].nx, F[0].ny]).toEqual([200, 400]);
});
