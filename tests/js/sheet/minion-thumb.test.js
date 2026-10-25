import { describe, expect, test } from 'vitest';
import { MINION_TYPES } from '@battlefield/config.js';
import { CUSTOM_FRAME } from '@battlefield/fighter/custom-fighter.js';
import { lookOf, lookStyle, minionThumb, thumbScale, thumbStyle } from '@battlefield/sheet/minion-thumb.js';

const animations = { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } };
const custom = (bodyPx, extra = {}) => ({ kind: 'custom', version: 'abc123def456', url_base: '/storage/custom-minions/7/abc123def456', animations, body_px: bodyPx, ...extra });

describe('thumbScale', () => {
  test('gives a 22 px minion and a 52 px fighter copied in the same body height on screen', () => {
    const small = 22 * thumbScale(22, 40);
    const large = 52 * thumbScale(52, 40);

    expect(small).toBeCloseTo(40, 5);
    expect(large).toBeCloseTo(40, 5);
  });

  test('draws a body that is not a positive number unscaled', () => {
    expect(thumbScale(0, 40)).toBe(1);
    expect(thumbScale(-3, 40)).toBe(1);
    expect(thumbScale(Number.NaN, 40)).toBe(1);
    expect(thumbScale(22, 0)).toBe(1);
  });
});

describe('lookOf', () => {
  test('finds a default look by its key in the game\'s own minion types', () => {
    const type = MINION_TYPES.find(candidate => candidate.key === 'smw-bowser');
    const idle = type.animFiles.idle;

    expect(lookOf({ kind: 'default', key: 'smw-bowser' })).toEqual({ url: idle.file, frames: idle.count, rate: idle.rate, frame: idle.frameWidth, bodyPx: type.charHeight });
  });

  test('knows no default look that is not one of the game\'s', () => {
    expect(lookOf({ kind: 'default', key: 'evil' })).toBeNull();
  });

  test('reads a custom minion from where its strips are served, in snake_case or camelCase', () => {
    const expected = { url: '/storage/custom-minions/7/abc123def456/idle.png', frames: 6, rate: 8, frame: CUSTOM_FRAME, bodyPx: 22 };

    expect(lookOf(custom(22))).toEqual(expected);
    expect(lookOf({ kind: 'custom', urlBase: '/storage/custom-minions/7/abc123def456', animations, bodyPx: 22 })).toEqual(expected);
  });

  test.each([
    ['nothing', null],
    ['a string', 'demon-a'],
    ['an unknown kind', { kind: 'other', url_base: '/x', animations, body_px: 22 }],
    ['a custom minion with no idle strip', custom(22, { animations: { walk: animations.walk } })],
    ['a custom minion with no body height', custom(22, { body_px: undefined })],
    ['a custom minion with no place to be served from', custom(22, { url_base: undefined })],
  ])('draws %s as nothing', (_name, spec) => {
    expect(lookOf(spec)).toBeNull();
  });
});

describe('thumbStyle', () => {
  test('sets the strip, its stepping and a frame box that gives every body one height', () => {
    const small = thumbStyle(lookOf(custom(22)), 40);
    const large = thumbStyle(lookOf(custom(52)), 40);

    expect(small['--strip']).toBe('url("/storage/custom-minions/7/abc123def456/idle.png")');
    expect(small['--frames']).toBe(6);
    expect(small['--rate']).toBe(8);
    expect((parseFloat(small['--size']) / CUSTOM_FRAME) * 22).toBeCloseTo(40, 0);
    expect((parseFloat(large['--size']) / CUSTOM_FRAME) * 52).toBeCloseTo(40, 0);
  });

  test('hangs the frame box below the feet so the feet stand on the floor line', () => {
    const style = lookStyle(lookOf(custom(22)), 2);

    expect(parseFloat(style['--below'])).toBeCloseTo(CUSTOM_FRAME * 2 * 0.42, 0);
  });

  test('holds the first frame when asked to stand still', () => {
    expect(thumbStyle(lookOf(custom(22)), 40, true)['--play']).toBe('paused');
    expect(thumbStyle(lookOf(custom(22)), 40)['--play']).toBe('running');
  });
});

describe('minionThumb', () => {
  test('draws its spec when it starts, and nothing for a spec it cannot read', () => {
    const drawn = minionThumb({ kind: 'default', key: 'demon-a' }, 40);
    drawn.init();
    const nothing = minionThumb({ kind: 'default', key: 'evil' }, 40);
    nothing.init();

    expect(drawn.style['--strip']).toContain('demon-a');
    expect(nothing.style).toEqual({});
  });
});
