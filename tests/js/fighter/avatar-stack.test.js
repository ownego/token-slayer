import { describe, expect, test } from 'vitest';
import { avatarCenterY, avatarSrc } from '@battlefield/fighter/avatar-stack.js';
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

describe('avatarSrc', () => {
  test('uses the versioned URL the server sent, so the browser can cache the image for a week', () => {
    expect(avatarSrc({ id: 5, avatarUrl: '/avatars/5?v=abc' })).toBe('/avatars/5?v=abc');
  });

  test('a fighter without one gets a stable URL, never a per-load cache buster', () => {
    expect(avatarSrc({ id: 5 })).toBe('/avatars/5');
    expect(avatarSrc({ id: 5 })).toBe(avatarSrc({ id: 5 }));
  });

  test('no id, no avatar', () => {
    expect(avatarSrc({})).toBeNull();
  });
});
