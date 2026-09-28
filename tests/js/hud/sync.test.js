import { expect, test } from 'vitest';
import { hudBox } from '@battlefield/hud/sync.js';

test('the HUD box matches the FIT canvas inside the mount (letterboxed)', () => {
  expect(hudBox({ left: 174, top: 0, width: 1784, height: 1003 }, { left: 0, top: 0 }))
    .toEqual({ left: 174, top: 0, width: 1784, height: 1003 });
  expect(hudBox({ left: 10, top: 60, width: 390, height: 693 }, { left: 10, top: 20 }))
    .toEqual({ left: 0, top: 40, width: 390, height: 693 });
});
