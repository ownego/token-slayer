import { describe, expect, test } from 'vitest';
import { homeSlotOffset, homeSlotAngle, shortestAngleDelta, isInFrontOfFighter } from '@battlefield/minion-layout.js';

describe('homeSlotOffset', () => {
  test('places the only slot (count=1) directly to the right at the given radius', () => {
    const p = homeSlotOffset(0, 1, 10);
    expect(p.x).toBeCloseTo(10);
    expect(p.y).toBeCloseTo(0);
  });

  test('splits two slots into opposite points on the circle', () => {
    const a = homeSlotOffset(0, 2, 10);
    const b = homeSlotOffset(1, 2, 10);
    expect(a.x).toBeCloseTo(10);
    expect(a.y).toBeCloseTo(0);
    expect(b.x).toBeCloseTo(-10);
    expect(b.y).toBeCloseTo(0, 5);
  });

  test('every slot sits exactly `radius` away from the center regardless of index/count', () => {
    for (const count of [1, 2, 3, 5, 6]) {
      for (let i = 0; i < count; i++) {
        const p = homeSlotOffset(i, count, 7);
        expect(Math.hypot(p.x, p.y)).toBeCloseTo(7);
      }
    }
  });

  test('slot i sits at angle 2π*i/count around the circle', () => {
    const count = 4;
    const radius = 5;
    for (let i = 0; i < count; i++) {
      const p = homeSlotOffset(i, count, radius);
      const expectedAngle = (2 * Math.PI * i) / count;
      expect(p.x).toBeCloseTo(radius * Math.cos(expectedAngle));
      expect(p.y).toBeCloseTo(radius * Math.sin(expectedAngle));
    }
  });

  test('a zero radius collapses every slot to the center', () => {
    const p = homeSlotOffset(2, 5, 0);
    expect(p.x).toBeCloseTo(0);
    expect(p.y).toBeCloseTo(0);
  });
});

describe('homeSlotAngle', () => {
  test('matches the angle homeSlotOffset derives its point from', () => {
    for (const count of [1, 2, 3, 5, 6]) {
      for (let i = 0; i < count; i++) {
        const angle = homeSlotAngle(i, count);
        const p = homeSlotOffset(i, count, 9);
        expect(p.x).toBeCloseTo(9 * Math.cos(angle));
        expect(p.y).toBeCloseTo(9 * Math.sin(angle));
      }
    }
  });

  test('index 0 is always angle 0 regardless of count', () => {
    expect(homeSlotAngle(0, 1)).toBeCloseTo(0);
    expect(homeSlotAngle(0, 6)).toBeCloseTo(0);
  });
});

describe('shortestAngleDelta', () => {
  test('a quarter turn forward is a positive delta of that size', () => {
    expect(shortestAngleDelta(0, Math.PI / 2)).toBeCloseTo(Math.PI / 2);
  });

  test('a quarter turn backward is a negative delta of that size', () => {
    expect(shortestAngleDelta(Math.PI / 2, 0)).toBeCloseTo(-Math.PI / 2);
  });

  test('crossing the 0/2π wraparound takes the short way, not the long way', () => {
    // from 350° to 10° is 20° forward the short way, not -340° the long way.
    const from = (350 * Math.PI) / 180;
    const to = (10 * Math.PI) / 180;
    const delta = shortestAngleDelta(from, to);
    expect(delta).toBeCloseTo((20 * Math.PI) / 180);
  });

  test('the delta is always within (-π, π]', () => {
    for (let fromDeg = 0; fromDeg < 360; fromDeg += 37) {
      for (let toDeg = 0; toDeg < 360; toDeg += 41) {
        const delta = shortestAngleDelta((fromDeg * Math.PI) / 180, (toDeg * Math.PI) / 180);
        expect(delta).toBeGreaterThan(-Math.PI - 1e-9);
        expect(delta).toBeLessThanOrEqual(Math.PI + 1e-9);
      }
    }
  });

  test('applying the delta to `from` reaches `to` (mod 2π)', () => {
    const from = 1.2;
    const to = 5.9;
    const delta = shortestAngleDelta(from, to);
    const reached = ((from + delta) % (2 * Math.PI) + 2 * Math.PI) % (2 * Math.PI);
    const expected = ((to % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI);
    expect(reached).toBeCloseTo(expected);
  });
});

describe('isInFrontOfFighter', () => {
  test('at or below the foot anchor (larger or equal Y) is in front', () => {
    expect(isInFrontOfFighter(100, 100)).toBe(true);
    expect(isInFrontOfFighter(140, 100)).toBe(true);
  });

  test('above the foot anchor (smaller Y) is behind', () => {
    expect(isInFrontOfFighter(60, 100)).toBe(false);
  });
});
