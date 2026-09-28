import { expect, test } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const root = 'public/assets/battlefield/bosses';
const pngs = dir => readdirSync(dir).flatMap(f => { const p = join(dir, f); return statSync(p).isDirectory() ? pngs(p) : p.endsWith('.png') ? [p] : []; });
const size = p => { const b = readFileSync(p); return { w: b.readUInt32BE(16), h: b.readUInt32BE(20) }; };

test('every boss sheet fits a 4096px GPU texture', () => {
  for (const p of pngs(root)) {
    const { w, h } = size(p);
    expect({ p, fits: w <= 4096 && h <= 4096 }).toEqual({ p, fits: true });
  }
});
