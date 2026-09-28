import { describe, expect, test } from 'vitest';
import { focusPlan, restoreCenter } from '@battlefield/shared/camera-focus.js';

describe('focusPlan', () => {
  test('pushes in on the fighter the way the mockup does (2.2x), and holds long enough for the character swap to play', () => {
    const fighter = { pos: { x: 400, y: 300 } };
    const camera = { zoom: 1 };

    const plan = focusPlan(fighter, camera);

    expect(plan).toEqual({ x: 400, y: 300, zoom: 2.2, duration: 950, hold: 2600 });
  });

  test('returns null for a missing fighter so the caller can no-op', () => {
    expect(focusPlan(undefined, { zoom: 1 })).toBeNull();
  });
});

describe('restoreCenter', () => {
  test('returns the layout\'s own center point — the only place create() ever frames the camera at, since nothing else in the scene pans/scrolls it', () => {
    expect(restoreCenter({ logicalWidth: 1200, logicalHeight: 800 })).toEqual({ x: 600, y: 400 });
  });
});
