import { TextureKey } from './constants.js';
import { poseRects } from './shared/clawd.js';
import { createGlyphCache } from './shared/flair-glyphs.js';
import { darkenHex } from './fighter/flair.js';

/**
 * Ensures the shared spark-particle texture (a thin white triangle) exists
 * in the given scene's texture manager — used as the particle-trail texture
 * by every projectile type.
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensureSparkTexture(scene) {
  if (scene.textures.exists(TextureKey.SPARK)) {
    return;
  }
  const g = scene.make.graphics({ add: false });
  g.fillStyle(0xffffff, 1);
  g.fillTriangle(24, 3, 0, 0, 0, 6);
  g.generateTexture(TextureKey.SPARK, 24, 6);
  g.destroy();
}

/**
 * Ensures the shared grinding-spark texture exists: a 14×3 canvas, a
 * transparent tail fading into a 3px white head — tinted per-emit (see
 * charge.js's `emitFor`) so one texture serves every fighter's own charge
 * palette instead of a fixed hard-coded colour.
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensureSparkStreakTexture(scene) {
  if (scene.textures.exists(TextureKey.SPARK_STREAK)) {
    return;
  }
  const canvas = scene.textures.createCanvas(TextureKey.SPARK_STREAK, 14, 3);
  const ctx = canvas.context;
  const gradient = ctx.createLinearGradient(0, 0, 14, 0);
  gradient.addColorStop(0, 'rgba(255,255,255,0)');
  gradient.addColorStop(0.6, 'rgba(255,255,255,0.7)');
  gradient.addColorStop(1, 'rgba(255,255,255,1)');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, 14, 3);
  ctx.fillStyle = '#ffffff';
  ctx.fillRect(11, 0, 3, 3);
  canvas.refresh();
}

/**
 * Ensures the shared soft-puff texture exists (a filled white circle,
 * tinted per-emit) — the burst that finishes a Clawd poof.
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensurePuffTexture(scene) {
  if (scene.textures.exists(TextureKey.PUFF)) {
    return;
  }
  const g = scene.make.graphics({ add: false });
  g.fillStyle(0xffffff, 1);
  g.fillCircle(8, 8, 8);
  g.generateTexture(TextureKey.PUFF, 16, 16);
  g.destroy();
}

/**
 * Ensures the shared soft-glow texture exists: a 64×64 radial gradient,
 * white at the centre fading to transparent — tinted per-use (the Fable
 * flair burst's glow).
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensureSoftGlowTexture(scene) {
  if (scene.textures.exists(TextureKey.SOFTGLOW)) {
    return;
  }
  const canvas = scene.textures.createCanvas(TextureKey.SOFTGLOW, 64, 64);
  const ctx = canvas.context;
  const gradient = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
  gradient.addColorStop(0, 'rgba(255,255,255,1)');
  gradient.addColorStop(0.35, 'rgba(255,255,255,0.45)');
  gradient.addColorStop(1, 'rgba(255,255,255,0)');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, 0, 64, 64);
  canvas.refresh();
}

/**
 * Ensures the shared mote texture exists: a tiny plus-shaped 4×4 canvas,
 * tinted per-emit — the brazier fire's particles and the arena's floating
 * dust/fireflies.
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensureMoteSoftTexture(scene) {
  if (scene.textures.exists(TextureKey.MOTE_SOFT)) {
    return;
  }
  const canvas = scene.textures.createCanvas(TextureKey.MOTE_SOFT, 4, 4);
  const ctx = canvas.context;
  ctx.fillStyle = '#fff';
  ctx.fillRect(1, 0, 2, 4);
  ctx.fillRect(0, 1, 4, 2);
  canvas.refresh();
}

/**
 * Bakes one Clawd pose into a canvas texture from `shared/clawd.js`'s
 * `poseRects` (2px per quadrant cell, body `#d77757`, eyes `#000`).
 *
 * @param {Phaser.Scene} scene
 * @param {string} key
 * @param {string} pose
 * @param {number} [rowOffset] extra quadrant-rows (each 2px) shifted down —
 *   used for the crouch texture, which is the default pose one row lower
 * @return {void}
 */
function bakeClawdPose(scene, key, pose, rowOffset = 0) {
  if (scene.textures.exists(key)) {
    return;
  }
  const rects = poseRects(pose);
  const shiftPx = rowOffset * 2;
  const maxX = Math.max(...rects.map(r => r.x));
  const maxY = Math.max(...rects.map(r => r.y)) + shiftPx;
  const canvas = scene.textures.createCanvas(key, (maxX + 1) * 2, (maxY + 1) * 2);
  const ctx = canvas.context;
  for (const r of rects) {
    ctx.fillStyle = r.on ? '#d77757' : '#000000';
    ctx.fillRect(r.x * 2, (r.y + shiftPx) * 2, 2, 2);
  }
  canvas.refresh();
}

/**
 * Ensures the Clawd pose textures exist: `clawd-default`, `clawd-crouch`
 * (the default pose baked one row lower), `clawd-arms` (arms-up) for the
 * minion poof, and `clawd-look-left`/`clawd-look-right` for the sky's
 * Clawd cameos (environment/clawd-cameo.js).
 *
 * @param {Phaser.Scene} scene
 * @return {void}
 */
export function ensureClawdTextures(scene) {
  bakeClawdPose(scene, TextureKey.CLAWD_DEFAULT, 'default');
  bakeClawdPose(scene, TextureKey.CLAWD_CROUCH, 'default', 1);
  bakeClawdPose(scene, TextureKey.CLAWD_ARMS, 'arms-up');
  bakeClawdPose(scene, TextureKey.CLAWD_LOOK_LEFT, 'look-left');
  bakeClawdPose(scene, TextureKey.CLAWD_LOOK_RIGHT, 'look-right');
}

/**
 * Creates the scene's Fable-flair glyph cache (`shared/flair-glyphs.js`'s
 * `createGlyphCache`, wired to Phaser): each unique (char, color, size) is
 * baked once into a small canvas texture — "Pixelify Sans" fill, a 3px
 * `#0b0716` stroke, a 2px hard drop shadow in the color darkened 55% —
 * instead of building a fresh Phaser Text per glyph per flair (the
 * ~20-Text-object burst that used to freeze the frame for 125-155ms).
 *
 * @param {Phaser.Scene} scene
 * @return {{get: function(string, string, number): string, prewarm: function(string, string, number): void}}
 */
export function createFlairGlyphCache(scene) {
  return createGlyphCache({
    exists: key => scene.textures.exists(key),
    create: (key, ch, color, px) => {
      const pad = 4;
      const w = Math.ceil(px * 0.8) + pad * 2;
      const h = px + pad * 2;
      const canvas = scene.textures.createCanvas(key, w, h);
      const ctx = canvas.context;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = `700 ${px}px "Pixelify Sans"`;
      const cx = w / 2;
      const cy = h / 2;
      ctx.fillStyle = darkenHex(color, 0.55);
      ctx.fillText(ch, cx + 2, cy + 2);
      ctx.lineWidth = 3;
      ctx.strokeStyle = '#0b0716';
      ctx.strokeText(ch, cx, cy);
      ctx.fillStyle = color;
      ctx.fillText(ch, cx, cy);
      canvas.refresh();
    },
  });
}
