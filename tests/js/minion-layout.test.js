import { describe, expect, test } from 'vitest';
import {
  homeSlotOffset,
  homeSlotAngle,
  shortestAngleDelta,
  isInFrontOfFighter,
  minionBadgeCenterY,
  toolRingRadius,
  toolRingClearance,
  TOOL_RING_MARGIN_RATIO,
  TOOL_RING_MIN_MARGIN_PX,
  TOOL_RING_STROKE_PX,
} from '@battlefield/minion-layout.js';
import { MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FOOT_ROW, CUSTOM_FRAME } from '@battlefield/fighter/custom-fighter.js';

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

describe('minionBadgeCenterY', () => {
  const SIZE_RATIO = 0.55;
  const OFFSET_RATIO = 0.65;
  const POINT_Y = 300;

  /**
   * @param {object} type a minion type
   * @param {number} scale the sprite scale
   * @return {number}
   */
  const badgeY = (type, scale) => minionBadgeCenterY({
    pointY: POINT_Y, scale, type, charHeight: type.charHeight, sizeRatio: SIZE_RATIO, offsetRatio: OFFSET_RATIO,
  });

  test.each([1, 40, CUSTOM_FOOT_ROW])('a custom minion of bodyPx %i wears its badge above its head, clear of the tool ring', (bodyPx) => {
    const scale = 0.8;
    const badgePx = scale * bodyPx * SIZE_RATIO;
    const headTopY = POINT_Y + (CUSTOM_FOOT_ROW - bodyPx - CUSTOM_FRAME / 2) * scale;
    const centerY = badgeY({ custom: true, charHeight: bodyPx }, scale);
    const ringOuterBottomY = centerY + toolRingRadius(badgePx) + TOOL_RING_STROKE_PX / 2;
    const gap = headTopY - (centerY + badgePx / 2);

    expect(ringOuterBottomY).toBeCloseTo(headTopY, 9);
    expect(gap).toBeCloseTo(Math.max(TOOL_RING_MIN_MARGIN_PX, badgePx * TOOL_RING_MARGIN_RATIO) + TOOL_RING_STROKE_PX / 2, 9);
  });

  test('a Naruto-sized custom minion (bodyPx 54, scale 0.417) leaves about 3 world px between badge and head', () => {
    const scale = 0.417;
    const bodyPx = 54;
    const badgePx = scale * bodyPx * SIZE_RATIO;
    const headTopY = POINT_Y + (CUSTOM_FOOT_ROW - bodyPx - CUSTOM_FRAME / 2) * scale;
    const gap = headTopY - (badgeY({ custom: true, charHeight: bodyPx }, scale) + badgePx / 2);

    expect(gap).toBeCloseTo(3, 1);
  });

  test.each(MINION_TYPES.map(type => [type.key, type]))('the default %s keeps the old offset from its sprite centre', (_, type) => {
    const scale = 1.3;

    expect(badgeY(type, scale)).toBeCloseTo(POINT_Y - scale * type.charHeight * OFFSET_RATIO);
  });

  test('a minion with no type yet falls back to the default formula', () => {
    const y = minionBadgeCenterY({ pointY: POINT_Y, scale: 2, type: null, charHeight: 20, sizeRatio: SIZE_RATIO, offsetRatio: OFFSET_RATIO });

    expect(y).toBeCloseTo(POINT_Y - 2 * 20 * OFFSET_RATIO);
  });
});

describe('tool ring', () => {
  test('its radius is the badge radius plus the larger of the minimum margin and the margin ratio', () => {
    expect(toolRingRadius(10)).toBeCloseTo(5 + TOOL_RING_MIN_MARGIN_PX);
    expect(toolRingRadius(40)).toBeCloseTo(20 + 40 * TOOL_RING_MARGIN_RATIO);
  });

  test('it clears the badge edge by the margin plus half the stroke', () => {
    expect(toolRingClearance(10)).toBeCloseTo(TOOL_RING_MIN_MARGIN_PX + TOOL_RING_STROKE_PX / 2);
    expect(toolRingClearance(40)).toBeCloseTo(40 * TOOL_RING_MARGIN_RATIO + TOOL_RING_STROKE_PX / 2);
  });
});
