import { describe, expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({
  default: { Textures: { FilterMode: { NEAREST: 1 } } },
}));

import { loadCustomFighter } from '@battlefield/fighter/custom-fighter-loader.js';
import { normalizeCustomCharacter } from '@battlefield/fighter/custom-fighter.js';

const SPEC = normalizeCustomCharacter({
  version: 'abc',
  url_base: '/storage/custom-characters/7/abc',
  animations: {
    idle: { frames: 2, rate: 8 },
    walk: { frames: 4, rate: 10 },
    attack: { frames: 3, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
});

/**
 * A scene whose texture manager and animation manager only record what they are told.
 *
 * @return {object}
 */
function fakeScene() {
  const textures = new Map();
  const anims = new Set();

  return {
    textures: {
      exists: key => textures.has(key),
      addCanvas: vi.fn((key, canvas) => {
        const texture = { key, canvas, frames: [], add(name, source, x, y, w, h) { this.frames.push({ name, x, y, w, h }); }, setFilter: vi.fn() };
        textures.set(key, texture);

        return texture;
      }),
      get: key => textures.get(key),
    },
    anims: {
      exists: key => anims.has(key),
      create: ({ key }) => anims.add(key),
      generateFrameNames: () => ({}),
    },
    stored: { textures, anims },
  };
}

/**
 * Stand-ins for the browser: images resolve at once, the canvas records its draws.
 *
 * @param {{failOn?: string}} [options]
 * @return {{loadImage: Function, makeCanvas: Function, drawn: Array}}
 */
function fakeBrowser({ failOn = null } = {}) {
  const drawn = [];

  return {
    drawn,
    loadImage: vi.fn(url => (failOn && url.endsWith(failOn) ? Promise.reject(new Error(`no ${url}`)) : Promise.resolve({ url }))),
    makeCanvas: (width, height) => ({
      width,
      height,
      getContext: () => ({ drawImage: (image, x, y) => drawn.push({ url: image.url, x, y }) }),
    }),
  };
}

describe('loadCustomFighter', () => {
  test('stacks the four strips into one texture of named 100px frames', async () => {
    const scene = fakeScene();
    const browser = fakeBrowser();

    const type = await loadCustomFighter(scene, 7, SPEC, browser);

    const texture = scene.stored.textures.get('custom-7-abc');
    expect(type.key).toBe('custom-7-abc');
    expect(texture.canvas).toMatchObject({ width: 400, height: 400 });
    expect(browser.drawn).toEqual([
      { url: '/storage/custom-characters/7/abc/idle.png', x: 0, y: 0 },
      { url: '/storage/custom-characters/7/abc/walk.png', x: 0, y: 100 },
      { url: '/storage/custom-characters/7/abc/attack.png', x: 0, y: 200 },
      { url: '/storage/custom-characters/7/abc/death.png', x: 0, y: 300 },
    ]);
    expect(texture.frames).toContainEqual({ name: 'custom-7-abc-walk-3', x: 300, y: 100, w: 100, h: 100 });
    expect(texture.frames).toHaveLength(2 + 4 + 3 + 3);
  });

  test('keeps the pixels crisp', async () => {
    const scene = fakeScene();

    await loadCustomFighter(scene, 7, SPEC, fakeBrowser());

    expect(scene.stored.textures.get('custom-7-abc').setFilter).toHaveBeenCalledWith(1);
  });

  test('registers the fighter\'s animations once its texture exists', async () => {
    const scene = fakeScene();

    await loadCustomFighter(scene, 7, SPEC, fakeBrowser());

    expect([...scene.stored.anims]).toEqual(expect.arrayContaining([
      'custom-7-abc-idle', 'custom-7-abc-walk', 'custom-7-abc-attack', 'custom-7-abc-death', 'custom-7-abc-summon',
    ]));
  });

  test('two requests for the same fighter at once load it once', async () => {
    const scene = fakeScene();
    const browser = fakeBrowser();

    const [first, second] = await Promise.all([
      loadCustomFighter(scene, 7, SPEC, browser),
      loadCustomFighter(scene, 7, SPEC, browser),
    ]);

    expect(browser.loadImage).toHaveBeenCalledTimes(4);
    expect(scene.textures.addCanvas).toHaveBeenCalledTimes(1);
    expect(second.key).toBe(first.key);
  });

  test('a fighter already loaded is not fetched again', async () => {
    const scene = fakeScene();
    const browser = fakeBrowser();

    await loadCustomFighter(scene, 7, SPEC, browser);
    browser.loadImage.mockClear();
    const type = await loadCustomFighter(scene, 7, SPEC, browser);

    expect(browser.loadImage).not.toHaveBeenCalled();
    expect(type.key).toBe('custom-7-abc');
  });

  test('a strip that will not load rejects, leaves no texture behind, and a retry can succeed', async () => {
    const scene = fakeScene();

    await expect(loadCustomFighter(scene, 7, SPEC, fakeBrowser({ failOn: 'walk.png' }))).rejects.toThrow('walk.png');
    expect(scene.stored.textures.has('custom-7-abc')).toBe(false);

    await expect(loadCustomFighter(scene, 7, SPEC, fakeBrowser())).resolves.toMatchObject({ key: 'custom-7-abc' });
  });
});
