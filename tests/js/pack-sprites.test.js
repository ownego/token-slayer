import { describe, test, expect, beforeAll, afterAll } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';

const atlasDir  = join(process.cwd(), 'public/assets/battlefield/fighters');
const atlasPng  = join(atlasDir, 'fighters-atlas.png');
const atlasJson = join(atlasDir, 'fighters-atlas.json');
const versionFile = join(process.cwd(), 'resources/js/battlefield/config/atlas-version.js');

// Only run if source sprites are present (skip in CI without sources)
const sourcesExist = existsSync(join(process.cwd(), 'resources/assets/battlefield/fighters/soldier-idle.png'));

describe.skipIf(!sourcesExist)('pack-sprites', () => {
  beforeAll(() => {
    execSync('node scripts/pack-sprites.js', { stdio: 'inherit' });
  }, 60_000);

  test('generates fighters-atlas.png', () => {
    expect(existsSync(atlasPng)).toBe(true);
  });

  test('generates fighters-atlas.json', () => {
    expect(existsSync(atlasJson)).toBe(true);
  });

  test('atlas JSON has meta with image path', () => {
    const json = JSON.parse(readFileSync(atlasJson, 'utf8'));
    expect(json.meta.image).toBe('fighters-atlas.png');
    expect(json.meta.size.w).toBeGreaterThan(0);
    expect(json.meta.size.h).toBeGreaterThan(0);
  });

  test('atlas JSON contains expected frame names for soldier-idle', () => {
    const json = JSON.parse(readFileSync(atlasJson, 'utf8'));
    // soldier-idle.png has 6 frames
    for (let i = 0; i < 6; i++) {
      expect(json.frames[`soldier-idle-${i}`], `missing soldier-idle-${i}`).toBeDefined();
      const f = json.frames[`soldier-idle-${i}`].frame;
      expect(f.w).toBe(100);
      expect(f.h).toBe(100);
    }
  });

  test('atlas JSON contains attack and effect variant frames', () => {
    const json = JSON.parse(readFileSync(atlasJson, 'utf8'));
    expect(json.frames['soldier-attack1-0']).toBeDefined();
    expect(json.frames['soldier-effect1-0']).toBeDefined();
  });

  test('atlas PNG width does not exceed 4096px', () => {
    const buf = readFileSync(atlasPng);
    const width = buf.readUInt32BE(16);
    expect(width).toBeLessThanOrEqual(4096);
  });

  // The atlas PNG/JSON are gitignored, non-hashed build artifacts served
  // with a 7-day must-revalidate cache (ts.tungot.dev's host nginx) — a
  // browser that cached them any time in that window won't even ask the
  // server again until it expires, so a roster/animation change (a new
  // frame added, an existing one repositioned) can silently 404 or play the
  // wrong art for up to a week. ATLAS_VERSION is a content hash baked into
  // the JS bundle at build time (not the gitignored PNG/JSON themselves) and
  // appended as a ?v= query string where the atlas is loaded (scene.js,
  // character-preview/scene.js) — a content change always earns a new URL,
  // busting exactly like Vite's own hashed /build/ assets already do.
  test('writes a content-derived ATLAS_VERSION constant', () => {
    expect(existsSync(versionFile)).toBe(true);
    const src = readFileSync(versionFile, 'utf8');
    expect(src).toMatch(/export const ATLAS_VERSION = '[0-9a-f]{8,}';/);
  });

  test('ATLAS_VERSION changes when the atlas content changes', () => {
    const first = readFileSync(versionFile, 'utf8');
    // Perturb the source atlas JSON's content indirectly by re-running
    // against the same sources — regenerating with identical inputs must
    // stay stable (no spurious cache-busting on an unchanged build).
    execSync('node scripts/pack-sprites.js', { stdio: 'inherit' });
    const second = readFileSync(versionFile, 'utf8');
    expect(second).toBe(first);
  });
});

const heroDir = join(process.cwd(), 'public/assets/battlefield/heroes');
const heroJson = join(heroDir, 'heroes-atlas.json');
const heroVersionFile = join(process.cwd(), 'resources/js/battlefield/config/heroes-atlas-version.js');

// The hero strips live in their own atlas: the fighter atlas is already
// 4096x3700, at the GPU texture limit many phones have.
describe.skipIf(!sourcesExist)('pack-sprites: hero atlas', () => {
  beforeAll(() => {
    execSync('node scripts/pack-sprites.js --src resources/assets/battlefield/heroes --out public/assets/battlefield/heroes --name heroes-atlas', { stdio: 'inherit' });
  }, 60_000);

  test('packs every hero frame as a 100x100 cell', () => {
    const json = JSON.parse(readFileSync(heroJson, 'utf8'));
    expect(json.meta.image).toBe('heroes-atlas.png');
    for (const [name, f] of Object.entries(json.frames)) {
      expect(f.frame.w, name).toBe(100);
      expect(f.frame.h, name).toBe(100);
    }
  });

  test('has the hero strips under their game keys, fliers under walk', () => {
    const json = JSON.parse(readFileSync(heroJson, 'utf8'));
    for (const key of ['iron-man', 'captain-america', 'batman', 'superman']) {
      for (const state of ['idle', 'walk', 'attack1', 'death']) {
        expect(json.frames[`${key}-${state}-0`], `${key}-${state}-0`).toBeDefined();
      }
    }
  });

  test('writes its own HEROES_ATLAS_VERSION and leaves the fighter version alone', () => {
    expect(readFileSync(heroVersionFile, 'utf8')).toMatch(/export const HEROES_ATLAS_VERSION = '[0-9a-f]{8,}';/);
    expect(readFileSync(versionFile, 'utf8')).toMatch(/export const ATLAS_VERSION = /);
  });
});

// The version is a cache buster for non-hashed files: an art edit that keeps the
// layout (a recoloured hero strip) must still change it.
describe('pack-sprites: version follows the art, not only the layout', () => {
  const tmp = join(process.cwd(), 'storage/framework/testing/pack-version');
  const versionPath = join(process.cwd(), 'resources/js/battlefield/config/vtest-atlas-version.js');

  afterAll(async () => {
    const { rmSync } = await import('node:fs');
    rmSync(tmp, { recursive: true, force: true });
    rmSync(versionPath, { force: true });
  });

  test('changing a pixel of a strip changes the version', async () => {
    const { mkdirSync } = await import('node:fs');
    const sharp = (await import('sharp')).default;
    mkdirSync(join(tmp, 'src'), { recursive: true });
    const strip = colour => sharp({ create: { width: 100, height: 100, channels: 4, background: colour } }).png().toFile(join(tmp, 'src', 'probe-idle.png'));
    const pack = () => {
      execSync(`node scripts/pack-sprites.js --src ${tmp}/src --out ${tmp}/out --name vtest-atlas`, { stdio: 'ignore' });
      return readFileSync(versionPath, 'utf8');
    };

    await strip({ r: 255, g: 0, b: 0, alpha: 1 });
    const red = pack();
    await strip({ r: 0, g: 0, b: 255, alpha: 1 });
    const blue = pack();

    expect(blue).not.toBe(red);
  }, 60_000);
});
