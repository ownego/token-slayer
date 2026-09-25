import { describe, expect, test } from 'vitest';
import { sampleTrail, pushTrailSample } from '@battlefield/minion-trail.js';

describe('sampleTrail', () => {
  test('returns null for an empty history', () => {
    expect(sampleTrail([], 1000)).toBeNull();
  });

  test('clamps to the oldest sample when atTime is before the history starts', () => {
    const history = [{ x: 10, y: 20, t: 500 }, { x: 30, y: 40, t: 600 }];
    expect(sampleTrail(history, 100)).toEqual({ x: 10, y: 20 });
  });

  test('clamps to the newest sample when atTime is after the history ends', () => {
    const history = [{ x: 10, y: 20, t: 500 }, { x: 30, y: 40, t: 600 }];
    expect(sampleTrail(history, 9999)).toEqual({ x: 30, y: 40 });
  });

  test('returns the exact sample when atTime matches a recorded timestamp', () => {
    const history = [{ x: 0, y: 0, t: 0 }, { x: 100, y: 200, t: 1000 }];
    expect(sampleTrail(history, 1000)).toEqual({ x: 100, y: 200 });
  });

  test('linearly interpolates between the two bracketing samples', () => {
    const history = [{ x: 0, y: 0, t: 0 }, { x: 100, y: 200, t: 1000 }];
    expect(sampleTrail(history, 250)).toEqual({ x: 25, y: 50 });
  });

  test('interpolates within the correct bracketing pair among several samples', () => {
    const history = [
      { x: 0, y: 0, t: 0 },
      { x: 10, y: 0, t: 100 },
      { x: 10, y: 10, t: 200 },
      { x: 20, y: 10, t: 300 },
    ];
    expect(sampleTrail(history, 150)).toEqual({ x: 10, y: 5 });
  });

  test('a single-sample history always returns that sample', () => {
    const history = [{ x: 5, y: 7, t: 42 }];
    expect(sampleTrail(history, 0)).toEqual({ x: 5, y: 7 });
    expect(sampleTrail(history, 9999)).toEqual({ x: 5, y: 7 });
  });
});

describe('pushTrailSample', () => {
  test('appends a new sample to the end', () => {
    const history = [{ x: 0, y: 0, t: 0 }];
    const next = pushTrailSample(history, { x: 1, y: 2, t: 100 }, 10000);
    expect(next).toEqual([{ x: 0, y: 0, t: 0 }, { x: 1, y: 2, t: 100 }]);
  });

  test('trims samples older than maxAgeMs relative to the newly pushed sample', () => {
    const history = [
      { x: 0, y: 0, t: 0 },
      { x: 1, y: 1, t: 400 },
      { x: 2, y: 2, t: 900 },
    ];
    const next = pushTrailSample(history, { x: 3, y: 3, t: 1000 }, 500);
    // t=0 (age 1000) and t=400 (age 600) are both older than maxAgeMs=500; t=900 (age 100) survives.
    expect(next).toEqual([{ x: 2, y: 2, t: 900 }, { x: 3, y: 3, t: 1000 }]);
  });

  test('does not mutate the original array', () => {
    const history = [{ x: 0, y: 0, t: 0 }];
    pushTrailSample(history, { x: 1, y: 1, t: 100 }, 10000);
    expect(history).toEqual([{ x: 0, y: 0, t: 0 }]);
  });
});
