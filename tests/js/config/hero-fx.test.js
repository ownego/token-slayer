import { describe, test, expect } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { HERO_FX } from '@battlefield/config/hero-fx.js';

const FX_DIR = join(process.cwd(), 'public/assets/battlefield/heroes/fx');

/** Width and height of a PNG, read from its IHDR chunk. */
function pngSize(file) {
  const buf = readFileSync(file);
  return { w: buf.readUInt32BE(16), h: buf.readUInt32BE(20) };
}

describe('HERO_FX', () => {
  test.each(Object.entries(HERO_FX))('%s describes a sheet that exists and holds its frames', (key, fx) => {
    expect(Number.isInteger(fx.frameWidth) && fx.frameWidth > 0).toBe(true);
    expect(Number.isInteger(fx.frameHeight) && fx.frameHeight > 0).toBe(true);
    expect(Number.isInteger(fx.frames) && fx.frames > 0).toBe(true);
    expect(fx.frameMs).toBeGreaterThan(0);
    expect(fx.pivot[0]).toBeGreaterThanOrEqual(0);
    expect(fx.pivot[0]).toBeLessThanOrEqual(fx.frameWidth);
    expect(fx.pivot[1]).toBeGreaterThanOrEqual(0);
    expect(fx.pivot[1]).toBeLessThanOrEqual(fx.frameHeight);

    const file = join(FX_DIR, fx.file);
    expect(existsSync(file), fx.file).toBe(true);
    const { w, h } = pngSize(file);
    const cells = Math.floor(w / fx.frameWidth) * Math.floor(h / fx.frameHeight);
    expect(cells).toBeGreaterThanOrEqual(fx.frames);
  });

  test('carries the approved sets\' props and the Batman sheet\'s effects', () => {
    const frames = Object.fromEntries(Object.entries(HERO_FX).map(([k, fx]) => [k, fx.frames]));
    expect(frames).toMatchObject({
      'cap-shield-spin': 8, 'cap-mjolnir': 1, 'bat-batarang': 4, 'bat-bat': 3,
      'bat-spark': 1, 'bat-burst': 1, 'bat-explosion': 1, 'bat-cloud': 1, 'bat-sphere': 1, 'bat-smoke': 1,
    });
  });

  test('ships no Tiny Swords art (its licence forbids redistribution)', () => {
    expect(Object.keys(HERO_FX).filter(k => k.startsWith('ts-'))).toEqual([]);
  });
});
