import { expect, test } from 'vitest';
import { bubbleCenterY } from '@battlefield/bubble-y.js';

// A fighter at y=500 whose avatar (40px) sits 60px above the sprite centre.
const fighter = { spriteY: 500, headY: -60, headH: 40 };
const BUBBLE_H = 26;

test('the activity bubble clears the avatar with a gap at normal size', () => {
  const y = bubbleCenterY({ ...fighter, scale: 1, bubbleH: BUBBLE_H });
  const avatarTop = 500 + (-60 - 20);

  expect(y + BUBBLE_H / 2).toBeLessThan(avatarTop);
});

test('a fighter grown by damage still keeps its bubble off the avatar (the overlap bug)', () => {
  const scale = 1.4;
  const y = bubbleCenterY({ ...fighter, scale, bubbleH: BUBBLE_H });
  const avatarTop = 500 + (-60 - 20) * scale;

  expect(y + BUBBLE_H / 2).toBeLessThan(avatarTop);
  expect(avatarTop - (y + BUBBLE_H / 2)).toBeLessThan(12); // close above it, not floating away
});
