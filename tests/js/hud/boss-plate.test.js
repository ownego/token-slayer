import { expect, test } from 'vitest';
import { crackedSegments, plateState } from '@battlefield/hud/boss-plate.js';

test('crossing 10% marks cracks exactly the segments passed, top first', () => {
  expect(crackedSegments(1000, 950, 1000)).toEqual([]);
  expect(crackedSegments(1000, 780, 1000)).toEqual([9, 8]);
  expect(crackedSegments(310, 0, 1000)).toEqual([3, 2, 1, 0]);
});

test('50% turns orange, 10% (but not 0) shows FINISH IT', () => {
  expect(plateState(600, 1000)).toBe('normal');
  expect(plateState(500, 1000)).toBe('enraged');
  expect(plateState(90, 1000)).toBe('finish');
  expect(plateState(0, 1000)).toBe('enraged');
});
