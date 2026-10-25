import { readFileSync, writeFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join, basename, extname } from 'node:path';
import { createHash } from 'node:crypto';
import sharp from 'sharp';

// `--src <dir> --out <dir> --name <atlas>` packs another strip set (the heroes) into its own atlas; with no flags
// it packs the roster fighters as before. The version file and constant follow the name, e.g. `heroes-atlas` ->
// config/heroes-atlas-version.js exporting HEROES_ATLAS_VERSION.
const arg = (flag, fallback) => {
  const i = process.argv.indexOf(flag);
  return i >= 0 ? process.argv[i + 1] : fallback;
};
const SRC_DIR  = arg('--src', 'resources/assets/battlefield/fighters');
const OUT_DIR  = arg('--out', 'public/assets/battlefield/fighters');
const NAME     = arg('--name', null);
const ATLAS    = NAME ?? 'fighters-atlas';
const FRAME_H  = 100;
const FRAME_W  = 100;
const MAX_W    = 4096;
// The gitignored atlas PNG/JSON are non-hashed filenames served with a
// 7-day must-revalidate cache (ts.tungot.dev's host nginx) -- a browser
// that already cached them won't even ask the server again until that
// window expires, so a roster/animation change can silently show stale
// art for up to a week. This constant is a content hash baked into the JS
// BUNDLE at build time instead (see scene.js/character-preview/scene.js's
// `?v=` query string), which busts exactly like Vite's own hashed /build/
// assets already do.
const VERSION_FILE = NAME ? `resources/js/battlefield/config/${NAME}-version.js` : 'resources/js/battlefield/config/atlas-version.js';
const VERSION_CONST = NAME ? `${NAME.replace(/-/g, '_').toUpperCase()}_VERSION` : 'ATLAS_VERSION';

mkdirSync(OUT_DIR, { recursive: true });

// Collect all PNG strips with their metadata
const files = readdirSync(SRC_DIR)
  .filter(f => extname(f) === '.png')
  .sort();

const strips = await Promise.all(
  files.map(async (file) => {
    const srcPath = join(SRC_DIR, file);
    const meta = await sharp(srcPath).metadata();
    const stem  = basename(file, '.png');         // e.g. "soldier-idle"
    const frameCount = Math.round(meta.width / FRAME_W);
    return { file, srcPath, stem, width: meta.width, height: meta.height, frameCount };
  })
);

// Shelf bin-pack: sort wider strips first for better packing
strips.sort((a, b) => b.width - a.width);

const placements = [];
let shelfX = 0;
let shelfY = 0;

for (const strip of strips) {
  if (shelfX + strip.width > MAX_W) {
    // Start new shelf
    shelfX = 0;
    shelfY += FRAME_H;
  }
  placements.push({ ...strip, x: shelfX, y: shelfY });
  shelfX += strip.width;
}

const atlasH = shelfY + FRAME_H;

// Composite all strips onto blank canvas
const composites = placements.map(p => ({
  input: p.srcPath,
  left:  p.x,
  top:   p.y,
}));

await sharp({
  create: { width: MAX_W, height: atlasH, channels: 4, background: { r: 0, g: 0, b: 0, alpha: 0 } },
})
  .composite(composites)
  .png({ compressionLevel: 9 })
  .toFile(join(OUT_DIR, `${ATLAS}.png`));

// Build Phaser Hash atlas JSON
const frames = {};
for (const p of placements) {
  for (let i = 0; i < p.frameCount; i++) {
    const name = `${p.stem}-${i}`;
    frames[name] = {
      frame:           { x: p.x + i * FRAME_W, y: p.y, w: FRAME_W, h: FRAME_H },
      rotated:         false,
      trimmed:         false,
      spriteSourceSize:{ x: 0, y: 0, w: FRAME_W, h: FRAME_H },
      sourceSize:      { w: FRAME_W, h: FRAME_H },
    };
  }
}

const atlasJson = {
  frames,
  meta: {
    app:    'pack-sprites',
    image:  `${ATLAS}.png`,
    format: 'RGBA8888',
    size:   { w: MAX_W, h: atlasH },
    scale:  '1',
  },
};

const atlasJsonText = JSON.stringify(atlasJson);
writeFileSync(join(OUT_DIR, `${ATLAS}.json`), atlasJsonText);

// Derived from the JSON (frame layout) rather than the PNG bytes: sharp's
// PNG encoder isn't guaranteed byte-identical across otherwise-identical
// runs (compression timing/metadata), which would bust the cache on every
// build even when nothing actually changed. The JSON already captures
// every frame's name, position, and size, so it changes if and only if the
// visible content does.
// The source strips are hashed too: an art edit that keeps every frame's place
// (a recoloured strip) changes no JSON, and must still bust the cache. Their
// committed bytes are stable, unlike the encoder's output.
const versionHash = createHash('sha256').update(atlasJsonText);
for (const file of files) versionHash.update(readFileSync(join(SRC_DIR, file)));
const version = versionHash.digest('hex').slice(0, 10);
writeFileSync(VERSION_FILE, `export const ${VERSION_CONST} = '${version}';\n`);

console.log(`[pack-sprites] ${placements.length} strips → ${ATLAS}.png (${MAX_W}×${atlasH})`);
