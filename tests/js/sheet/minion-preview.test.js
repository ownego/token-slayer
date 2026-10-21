import { describe, expect, test } from 'vitest';
import { MINION_SIZE_RATIO } from '@battlefield/config.js';
import { fieldSizeFighterScale, fieldSizeMinionScale, minionLooks } from '@battlefield/sheet/minion-preview.js';

describe('fieldSizeMinionScale', () => {
  test('a 22 px minion beside a fighter at field scale 2 is drawn a little under life size', () => {
    expect(fieldSizeMinionScale(2, 22)).toBeCloseTo(18 / 22, 3);
  });

  test('an older fighter copied in is 28 px tall and drawn larger, so it still stands half its fighter\'s height', () => {
    expect(fieldSizeMinionScale(4, 28)).toBeCloseTo(1.286, 3);
  });

  test('follows the field\'s own ratio of minion height to fighter height', () => {
    expect(MINION_SIZE_RATIO).toBe(0.5);
    expect(fieldSizeMinionScale(2, 18)).toBeCloseTo(2 * MINION_SIZE_RATIO, 5);
  });
});

describe('fieldSizeFighterScale', () => {
  test('puts the fighter\'s measured body at the height the field draws it', () => {
    expect(fieldSizeFighterScale(2, 36)).toBeCloseTo(1, 5);
    expect(fieldSizeFighterScale(2, 18)).toBeCloseTo(2, 5);
  });
});

describe('minionLooks', () => {
  const wire = [
    { version: 'aaaaaaaaaaaa', url_base: '/storage/custom-minions/7/aaaaaaaaaaaa', body_px: 22, animations: { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 16 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } } },
    { version: 'bbbbbbbbbbbb', url_base: '/storage/custom-minions/7/bbbbbbbbbbbb', body_px: 28, animations: { idle: { frames: 4, rate: 8 }, walk: { frames: 8, rate: 16 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } } },
  ];

  test('is the idle strip, frame count and rate of each minion in the set', () => {
    expect(minionLooks(wire)).toEqual([
      { url: '/storage/custom-minions/7/aaaaaaaaaaaa/idle.png', frames: 6, rate: 8 },
      { url: '/storage/custom-minions/7/bbbbbbbbbbbb/idle.png', frames: 4, rate: 8 },
    ]);
  });

  test('reads the camelCase shape the battlefield snapshot uses too', () => {
    expect(minionLooks([{ version: 'v1', urlBase: '/m/v1', bodyPx: 22, animations: wire[0].animations }])).toEqual([{ url: '/m/v1/idle.png', frames: 6, rate: 8 }]);
  });

  test('is empty for no information, an empty set and an incomplete minion', () => {
    expect(minionLooks(undefined)).toEqual([]);
    expect(minionLooks(null)).toEqual([]);
    expect(minionLooks([])).toEqual([]);
    expect(minionLooks([{ version: 'x' }])).toEqual([]);
  });
});
