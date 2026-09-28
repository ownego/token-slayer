import { expect, test } from 'vitest';
import { meteorFrame, meteorPlan } from '@battlefield/environment/pixel-art.js';

const seeded = seed => { let s = seed; return () => (s = (s * 16807) % 2147483647) / 2147483647; };

test('a meteor falls across the upper sky at a slant, quickly', () => {
  for (let i = 0; i < 20; i++) {
    const p = meteorPlan(seeded(i + 1), 1920, 400);
    expect(p.y0).toBeLessThan(400 * 0.45);
    expect(p.angle).toBeGreaterThanOrEqual(18);
    expect(p.angle).toBeLessThanOrEqual(42);
    expect(p.duration).toBeGreaterThanOrEqual(500);
    expect(p.duration).toBeLessThanOrEqual(1200);
  }
});

test('its trail stretches out behind the head, then burns out', () => {
  const p = { x0: 1000, y0: 50, angle: 30, dir: -1, distance: 400, duration: 800, length: 160, bright: 1 };
  const start = meteorFrame(p, 0.02);
  const mid = meteorFrame(p, 0.5);
  const end = meteorFrame(p, 0.98);
  expect(mid.length).toBeGreaterThan(start.length);
  expect(mid.length).toBeGreaterThan(end.length);
  expect(mid.alpha).toBeGreaterThan(end.alpha);
  expect(start.alpha).toBeLessThan(mid.alpha); // flares in, not a hard pop
});

test('the head travels down and along its slant', () => {
  const p = { x0: 1000, y0: 50, angle: 30, dir: -1, distance: 400, duration: 800, length: 160, bright: 1 };
  const a = meteorFrame(p, 0.1);
  const b = meteorFrame(p, 0.9);
  expect(b.y).toBeGreaterThan(a.y);
  expect(b.x).toBeLessThan(a.x); // dir -1: heading left
});
