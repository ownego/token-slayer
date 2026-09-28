import { expect, test } from 'vitest';
import { nextBurst, sparkAngle, SPARK_EMITTER } from '@battlefield/shared/sparks.js';

test('sparks fly back and up from the heel, mirrored by facing', () => {
  expect(sparkAngle(1, () => 0)).toBe(192);
  expect(sparkAngle(1, () => 0.999)).toBeCloseTo(228, 0);
  expect(sparkAngle(-1, () => 0)).toBe(312);
});

test('no burst until the wait runs out; then 2-4 sparks standing, 2-6 moving', () => {
  expect(nextBurst({ wait: 0.1, moving: false }, 0.05, () => 0.5).count).toBe(0);
  const standing = nextBurst({ wait: 0.01, moving: false }, 0.05, () => 0);
  expect(standing.count).toBe(2);
  expect(standing.wait).toBeGreaterThanOrEqual(0.05);
  expect(nextBurst({ wait: 0, moving: true }, 0.05, () => 0.999).count).toBe(6);
});

test('the emitter matches the approved look', () => {
  expect(SPARK_EMITTER.gravityY).toBe(1100);
  expect(SPARK_EMITTER.lifespan).toEqual({ min: 180, max: 380 });
});
