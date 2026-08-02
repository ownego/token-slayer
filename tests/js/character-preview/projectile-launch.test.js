import { describe, expect, test } from 'vitest';
import { getProjectileLaunchDelay } from '@battlefield/character-preview/projectile-launch.js';

describe('getProjectileLaunchDelay', () => {
  test.each([
    [500, 200],
    [583, 233],
    [1250, 500],
  ])('%ims duration -> %ims delay (~40%%, rounded)', (durationMs, expected) => {
    expect(getProjectileLaunchDelay(durationMs)).toBe(expected);
  });
});
