import { describe, expect, test } from 'vitest';
import { hudBand, hudBox } from '@battlefield/hud/sync.js';

test('the HUD box matches the FIT canvas inside the mount (letterboxed)', () => {
  expect(hudBox({ left: 174, top: 0, width: 1784, height: 1003 }, { left: 0, top: 0 }))
    .toEqual({ left: 174, top: 0, width: 1784, height: 1003 });
  expect(hudBox({ left: 10, top: 60, width: 390, height: 693 }, { left: 10, top: 20 }))
    .toEqual({ left: 0, top: 40, width: 390, height: 693 });
});

describe('hudBand', () => {
  test('is the lowest top-band panel bottom below the HUD top, in logical px', () => {
    const hud = { top: 0, width: 390 };
    const panels = [{ bottom: 40 }, { bottom: 120 }, { bottom: 262 }];

    // 262 CSS px on a 390px-wide HUD over a 540-wide world
    expect(hudBand(panels, hud, 540)).toBe(Math.round(262 * 540 / 390));
  });

  test('measures from the HUD top, not the page top', () => {
    expect(hudBand([{ bottom: 300 }], { top: 100, width: 540 }, 540)).toBe(200);
  });

  test('a hidden HUD (no panels, or zero width) reports no band', () => {
    expect(hudBand([], { top: 0, width: 390 }, 540)).toBe(0);
    expect(hudBand([{ bottom: 50 }], { top: 0, width: 0 }, 540)).toBe(0);
  });
});
