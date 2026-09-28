import { describe, expect, test } from 'vitest';
import { loadoutLayout, fitScale, STACK_BELOW_PX } from '@battlefield/character-preview/modal-fit.js';

describe('loadoutLayout', () => {
  test('phones and narrow windows (e.g. DevTools docked right) stack the roster above the preview', () => {
    expect(loadoutLayout(390)).toBe('stacked');
    expect(loadoutLayout(STACK_BELOW_PX - 1)).toBe('stacked');
  });

  test('anything at least the breakpoint wide keeps the side-by-side split', () => {
    expect(loadoutLayout(STACK_BELOW_PX)).toBe('split');
    expect(loadoutLayout(1920)).toBe('split');
  });
});

describe('fitScale', () => {
  const card = { contentWidth: 1100, contentHeight: 700 };

  test('never enlarges past the authored size on a big screen', () => {
    expect(fitScale({ ...card, viewportWidth: 2400, viewportHeight: 1400 })).toBe(1);
  });

  test('a short viewport (DevTools docked at the bottom, 1920x500) shrinks to fit the height', () => {
    // (500 - 2*16) / 700
    expect(fitScale({ ...card, viewportWidth: 1920, viewportHeight: 500 })).toBeCloseTo(468 / 700);
  });

  test('a narrow viewport shrinks to fit the width, whichever axis is tighter wins', () => {
    // width: (1024 - 32) / 1100 = 0.902; height: (700 - 32) / 700 = 0.954
    expect(fitScale({ ...card, viewportWidth: 1024, viewportHeight: 700 })).toBeCloseTo(992 / 1100);
  });

  test('the same design at browser zoom 80% vs 100% differs only in scale, never in fit', () => {
    const at100 = fitScale({ ...card, viewportWidth: 1280, viewportHeight: 720 });
    const at80 = fitScale({ ...card, viewportWidth: 1600, viewportHeight: 900 });
    expect(1100 * at100).toBeLessThanOrEqual(1280 - 32);
    expect(700 * at80).toBeLessThanOrEqual(900 - 32);
  });

  test('an unmeasured card (0 size before first layout) leaves the scale at 1 instead of dividing by zero', () => {
    expect(fitScale({ contentWidth: 0, contentHeight: 0, viewportWidth: 800, viewportHeight: 600 })).toBe(1);
  });

  test('a custom margin is kept clear on every side', () => {
    expect(fitScale({ ...card, viewportWidth: 1100, viewportHeight: 2000, margin: 50 })).toBeCloseTo(1000 / 1100);
  });
});
