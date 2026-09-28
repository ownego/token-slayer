// @vitest-environment jsdom
import { expect, test, vi } from 'vitest';
import { HEEL_RAISE, sparkField, sparkPoint } from '@battlefield/sheet/stage-sparks.js';

test('sparks leave from just above the back heel, not from under the feet', () => {
  // body box 40..60 wide, feet at y1 = 57; heel pixel at (42, 57); drawn at 5x
  const p = sparkPoint({ width: 400, height: 200, cxRatio: 0.5, floor: 26, box: [40, 40, 60, 57], heel: { x: 42, y: 57 }, scale: 5 });

  expect(p.x).toBe(400 * 0.5 + (42 - 50) * 5);
  expect(p.y).toBe(200 - 26 - HEEL_RAISE * 5);
  expect(HEEL_RAISE).toBeGreaterThan(0);
});

test('an unmeasured heel falls back to just behind the body centre, at the feet', () => {
  const p = sparkPoint({ width: 400, height: 200, cxRatio: 0.3, floor: 44, lift: 10, box: [40, 40, 60, 57], heel: null, scale: 4 });

  expect(p.x).toBe(400 * 0.3 - 3 * 4);
  expect(p.y).toBe(200 - 44 - 10 - HEEL_RAISE * 4);
});

test('a hidden stage stops the per-frame loop and only checks back twice a second', () => {
  vi.useFakeTimers();
  const raf = vi.fn(() => 1);
  vi.stubGlobal('requestAnimationFrame', raf);
  vi.stubGlobal('cancelAnimationFrame', () => {});
  const host = document.createElement('div'); // detached: offsetParent is null, i.e. not shown

  const stop = sparkField(host, () => ({ x: 0, y: 0 }), () => 3);
  const firstFrame = raf.mock.calls[0][0];
  firstFrame(performance.now());

  expect(raf).toHaveBeenCalledTimes(1); // no frame-by-frame spinning while hidden
  vi.advanceTimersByTime(499);
  expect(raf).toHaveBeenCalledTimes(1);
  vi.advanceTimersByTime(1);
  expect(raf).toHaveBeenCalledTimes(2); // looks again after half a second

  stop();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

test('the spark canvas is a small box around the heel, not the whole stage', () => {
  vi.stubGlobal('requestAnimationFrame', () => 1);
  vi.stubGlobal('cancelAnimationFrame', () => {});
  const host = document.createElement('div');

  const stop = sparkField(host, () => ({ x: 0, y: 0 }), () => 3);
  const cv = host.querySelector('canvas.spark-cv');

  expect(cv.width).toBeLessThanOrEqual(260);
  expect(cv.height).toBeLessThanOrEqual(180);

  stop();
  vi.unstubAllGlobals();
});
