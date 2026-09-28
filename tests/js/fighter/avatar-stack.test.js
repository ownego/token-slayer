import { expect, test } from 'vitest';
import { avatarCenterY } from '@battlefield/fighter/avatar-stack.js';
import { BOX } from '@battlefield/sheet/sheet-roster.js';

const scale = 48 / 18; // a 48px fighter
const headTop = key => -(50 - BOX[key][1]) * scale; // container-local y of the body's own top

test('the avatar rides each fighter\'s own head, so tall and short fighters don\'t share one height', () => {
  expect(avatarCenterY('knight', scale)).not.toBe(avatarCenterY('slime', scale));
});

test('the avatar clears the head with a gap proportional to the body, never touching it', () => {
  const avatarSize = 48 * 0.85;
  for (const key of Object.keys(BOX)) {
    const bottom = avatarCenterY(key, scale) + avatarSize / 2;
    expect(bottom).toBeLessThan(headTop(key) - 4);
    expect(headTop(key) - bottom).toBeLessThan(24); // close above, not floating away
  }
});
