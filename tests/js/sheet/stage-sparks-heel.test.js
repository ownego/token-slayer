// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';

vi.mock('@battlefield/shared/heel.js', () => ({
  findHeel: data => ({ x: data[0], y: data[1] }),
}));

import { BOX } from '@battlefield/sheet/sheet-roster.js';
import { BY_KEY } from '@battlefield/sheet/sprite-strip.js';
import { CUSTOM_KEY, registerCustomRoster } from '@battlefield/sheet/custom-roster.js';
import { forgetHeel, heelOf } from '@battlefield/sheet/stage-sparks.js';

const WIRE = {
  version: 'one',
  url_base: '/storage/custom-characters/7/one',
  animations: { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } },
};

let images;

beforeEach(() => {
  images = [];
  vi.stubGlobal('Image', class {
    constructor() {
      images.push(this);
    }
  });
  vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(() => ({
    drawImage() {},
    getImageData: () => ({ data: [images.indexOf(images.find(i => i.loaded)) + 100, 7] }),
  }));
  registerCustomRoster(BY_KEY, BOX, WIRE);
});

afterEach(() => {
  forgetHeel(CUSTOM_KEY);
  registerCustomRoster(BY_KEY, BOX, null);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

const land = image => {
  image.loaded = true;
  image.onload();
};

test('the heel is measured once and kept', () => {
  expect(heelOf(CUSTOM_KEY)).toBeNull();
  land(images[0]);

  expect(heelOf(CUSTOM_KEY)).toEqual({ x: 100, y: 7 });
  expect(images).toHaveLength(1);
});

test('a measurement of the fighter that was replaced cannot overwrite the newer one', () => {
  heelOf(CUSTOM_KEY);
  const stale = images[0];
  forgetHeel(CUSTOM_KEY);
  heelOf(CUSTOM_KEY);
  const fresh = images[1];

  land(fresh);
  const measured = heelOf(CUSTOM_KEY);
  land(stale);

  expect(heelOf(CUSTOM_KEY)).toEqual(measured);
  expect(measured).not.toBeNull();
});
