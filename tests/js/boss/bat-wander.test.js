import { describe, expect, test } from 'vitest';
import { randomWanderPoint } from '@battlefield/boss/bat-wander.js';

const ZONE = { centerX: 480, centerY: 140, radiusX: 220, radiusY: 45 };

describe('randomWanderPoint', () => {
  test('rng returning 0 places the point exactly at the zone center', () => {
    const point = randomWanderPoint(ZONE, () => 0);
    expect(point.x).toBeCloseTo(480);
    expect(point.y).toBeCloseTo(140);
  });

  test('rng returning near-1 places the point near the zone edge, never past it', () => {
    const point = randomWanderPoint(ZONE, () => 0.999999);
    const dx = (point.x - ZONE.centerX) / ZONE.radiusX;
    const dy = (point.y - ZONE.centerY) / ZONE.radiusY;
    expect(Math.sqrt(dx * dx + dy * dy)).toBeLessThanOrEqual(1.0001);
  });

  test('every generated point stays within the elliptical bounds', () => {
    let calls = 0;
    const rng = () => { calls++; return (calls % 97) / 97; }; // deterministic pseudo-spread
    for (let i = 0; i < 200; i++) {
      const point = randomWanderPoint(ZONE, rng);
      const dx = (point.x - ZONE.centerX) / ZONE.radiusX;
      const dy = (point.y - ZONE.centerY) / ZONE.radiusY;
      expect(Math.sqrt(dx * dx + dy * dy)).toBeLessThanOrEqual(1.0001);
    }
  });
});
