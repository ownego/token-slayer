import { describe, test, expect, beforeAll } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { execSync } from 'node:child_process';

const atlasDir  = join(process.cwd(), 'public/assets/battlefield/fighters');
const atlasPng  = join(atlasDir, 'fighters-atlas.png');
const atlasJson = join(atlasDir, 'fighters-atlas.json');

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
});
