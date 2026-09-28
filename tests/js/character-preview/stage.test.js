import { expect, test, vi } from 'vitest';
vi.mock('phaser', () => ({ default: { Scene: class {} } }));
import { avatarFor, stageScale } from '@battlefield/character-preview/scene.js';

test('the fighter is sized by the stage\'s height and width, so a phone stage gets a smaller one', () => {
  expect(stageScale(614, 534, 26)).toBe(3);
  expect(stageScale(334, 357, 26)).toBe(2);
});

test('the avatar keeps battlefield proportions: 0.85 of the body, centred 38/48 of a body above the head', () => {
  expect(avatarFor(3)).toEqual({ size: 46, centreAbove: 42.75 });
});
