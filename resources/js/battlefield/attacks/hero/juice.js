import Phaser from 'phaser';
import { HERO_FX } from '@battlefield/config/hero-fx.js';

/** Texture and animation key of a hero effect sheet. */
export const heroFxKey = k => `hero-fx-${k}`;

// Small hit feedback shared by the hero moves, from the preview harness. Every
// piece is local to the hero or the boss (never map-wide) and tracked by the
// scene's HeroEffects, so the kill ceremony can clear it.

/**
 * The animation of a hero effect sheet, created on first use.
 *
 * @param {Phaser.Scene} scene
 * @param {string} k A HERO_FX key.
 * @return {string} The animation key.
 */
export function fxAnim(scene, k) {
  const v = HERO_FX[k];
  const key = heroFxKey(k);
  if (!scene.anims.exists(key)) {
    scene.anims.create({
      key,
      frames: scene.anims.generateFrameNumbers(key, { start: 0, end: v.frames - 1 }),
      duration: v.frames * v.frameMs,
      repeat: v.loop ? -1 : 0,
    });
  }
  return key;
}

/**
 * Plays a hero effect sheet once at (x, y), placed by its pivot.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {string} k A HERO_FX key.
 * @param {number} x
 * @param {number} y
 * @param {number} [scale]
 * @param {{depth?: number, rotation?: number}} [opts]
 * @return {Phaser.GameObjects.Sprite}
 */
export function playFx({ scene, effects }, k, x, y, scale = 1, opts = {}) {
  const v = HERO_FX[k];
  const key = fxAnim(scene, k);
  const s = effects.track(scene.add.sprite(x, y, key)
    .setOrigin(v.pivot[0] / v.frameWidth, v.pivot[1] / v.frameHeight)
    .setScale(scale)
    .setDepth(opts.depth ?? 30));
  if (opts.rotation) s.setRotation(opts.rotation);
  s.play(key);
  if (!v.loop) s.once('animationcomplete', () => s.destroy());
  return s;
}

/**
 * An expanding ring that fades out.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} r0 Start radius.
 * @param {number} r1 End radius.
 * @param {number} color
 * @param {number} ms
 * @param {number} [width]
 * @param {number} [sy] Vertical squash, for a ring on the ground.
 * @return {void}
 */
export function fxRing({ scene, effects }, x, y, r0, r1, color, ms, width = 3, sy = 1) {
  const c = effects.track(scene.add.circle(x, y, r0).setStrokeStyle(width, color, 1).setDepth(31).setScale(1, sy));
  scene.tweens.add({ targets: c, radius: r1, alpha: 0, duration: ms, onUpdate: () => c.setStrokeStyle(width, color, c.alpha), onComplete: () => c.destroy() });
}

/**
 * A bright additive flash at the point of contact.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} r
 * @param {number} [color]
 * @return {void}
 */
export function fxFlash({ scene, effects }, x, y, r, color = 0xffffff) {
  const f = effects.track(scene.add.circle(x, y, r, color, 0.95).setDepth(33).setBlendMode(Phaser.BlendModes.ADD));
  scene.tweens.add({ targets: f, alpha: 0, scale: 1.6, duration: 170, onComplete: () => f.destroy() });
}

/**
 * Radial impact lines for one beat.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} [color]
 * @param {number} [n] Line count.
 * @param {number} [r] Inner radius.
 * @return {void}
 */
export function fxLines({ scene, effects }, x, y, color = 0xffffff, n = 12, r = 20) {
  const g = effects.track(scene.add.graphics().setDepth(32));
  for (let i = 0; i < n; i++) {
    const a = (i / n) * Math.PI * 2 + Math.random() * 0.25;
    const r0 = r + Math.random() * 8;
    const r1 = r0 + 16 + Math.random() * 16;
    g.lineStyle(i % 3 ? 2 : 3, color, 1).lineBetween(x + Math.cos(a) * r0, y + Math.sin(a) * r0, x + Math.cos(a) * r1, y + Math.sin(a) * r1);
  }
  scene.tweens.add({ targets: g, alpha: 0, delay: 90, duration: 220, onComplete: () => g.destroy() });
}

/**
 * A charge-up gleam: an additive glow and a turning white cross.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {{x: number, y: number}} p
 * @param {number} [k] Size.
 * @param {number} [color]
 * @return {void}
 */
export function fxFlare({ scene, effects }, p, k = 1, color = 0x7fe7ff) {
  const glow = effects.track(scene.add.circle(p.x, p.y, 4, color, 0.9).setDepth(31).setBlendMode(Phaser.BlendModes.ADD));
  scene.tweens.add({ targets: glow, radius: 10 * k + 4, alpha: 0, duration: 260, onComplete: () => glow.destroy() });
  const star = effects.track(scene.add.graphics({ x: p.x, y: p.y }).setDepth(32));
  star.fillStyle(0xffffff, 1);
  star.fillRect(-1, -8 * k, 2, 16 * k);
  star.fillRect(-8 * k, -1, 16 * k, 2);
  scene.tweens.add({ targets: star, angle: 90, scale: 0.1, duration: 280, onComplete: () => star.destroy() });
}

/**
 * Square sparks flying out from a point.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number[]} colors
 * @param {number} [n]
 * @param {number} [dist]
 * @return {void}
 */
export function fxSparks({ scene, effects }, x, y, colors, n = 8, dist = 32) {
  for (let i = 0; i < n; i++) {
    const a = (i / n) * Math.PI * 2 + Math.random() * 0.5;
    const d = dist * (0.6 + Math.random() * 0.6);
    const sp = effects.track(scene.add.rectangle(x, y, 3, 3, colors[i % colors.length]).setDepth(31));
    scene.tweens.add({ targets: sp, x: x + Math.cos(a) * d, y: y + Math.sin(a) * d, alpha: 0, duration: 340, onComplete: () => sp.destroy() });
  }
}

/**
 * Thin light streaks bursting out of a hit.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} [n] Streak count.
 * @param {number} [len] Streak length at the start.
 * @param {number} [color]
 * @return {void}
 */
export function fxStreaks({ scene, effects }, x, y, n = 10, len = 26, color = 0xfffbe6) {
  const g = effects.track(scene.add.graphics().setDepth(32));
  const angles = Array.from({ length: n }, (_, i) => (i / n) * Math.PI * 2 + Math.random() * 0.3);
  const o = { t: 0 };
  scene.tweens.add({ targets: o, t: 1, duration: 260, onUpdate: () => {
    g.clear();
    const r0 = 6 + 26 * o.t;
    const r1 = r0 + len * (1 - o.t);
    g.lineStyle(1.5, color, 1 - o.t);
    for (const a of angles) g.lineBetween(x + Math.cos(a) * r0, y + Math.sin(a) * r0, x + Math.cos(a) * r1, y + Math.sin(a) * r1);
  }, onComplete: () => g.destroy() });
}

/**
 * Three curved swish lines where something is thrown.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} dir 1 when the throw goes right, -1 left.
 * @return {void}
 */
export function fxWhoosh({ scene, effects }, x, y, dir) {
  const g = effects.track(scene.add.graphics({ x, y }).setDepth(31).setScale(dir, 1));
  for (let k = 0; k < 3; k++) {
    g.lineStyle(1.5, 0xf4f1f4, 1);
    g.beginPath();
    g.arc(-8, 3, 14 + k * 6, -Math.PI * 0.7, -Math.PI * 0.1);
    g.strokePath();
  }
  scene.tweens.add({ targets: g, alpha: 0, duration: 200, onComplete: () => g.destroy() });
}

/**
 * A crackling bolt along a list of points (a ricochet's path, lightning on the ground).
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {Array<{x: number, y: number}>} pts
 * @param {number} ms
 * @param {number} [color]
 * @return {void}
 */
export function fxEnergy({ scene, effects }, pts, ms, color = 0xa9dcff) {
  const g = effects.track(scene.add.graphics().setDepth(30).setBlendMode(Phaser.BlendModes.ADD));
  for (const [w, c] of [[4, color], [1.5, 0xffffff]]) {
    g.lineStyle(w, c, 0.9);
    g.beginPath();
    g.moveTo(pts[0].x, pts[0].y);
    for (const p of pts.slice(1)) g.lineTo(p.x, p.y);
    g.strokePath();
  }
  scene.tweens.add({ targets: g, alpha: 0, duration: ms, onComplete: () => g.destroy() });
}

/**
 * One still effect image (the Batman sheet's sparks, blasts and smoke) that grows a little and fades.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {string} k A single-frame HERO_FX key.
 * @param {number} x
 * @param {number} y
 * @param {number} ms
 * @param {number} [scale]
 * @return {void}
 */
export function fxPop({ scene, effects }, k, x, y, ms, scale = 1) {
  const v = HERO_FX[k];
  const s = effects.track(scene.add.image(x, y, heroFxKey(k), 0)
    .setOrigin(v.pivot[0] / v.frameWidth, v.pivot[1] / v.frameHeight).setScale(scale * 0.85).setDepth(31));
  scene.tweens.add({ targets: s, scale: scale * 1.15, duration: ms });
  scene.tweens.add({ targets: s, alpha: 0, delay: ms * 0.4, duration: ms * 0.6, onComplete: () => s.destroy() });
}

/**
 * Specks drawn in toward a point: a charge-up, a deep breath.
 *
 * @param {{scene: Phaser.Scene, effects: object}} ctx
 * @param {{x: number, y: number}} p
 * @param {number} n
 * @param {number} r How far out they start.
 * @param {number} ms
 * @param {number} color
 * @return {void}
 */
export function fxGather({ scene, effects }, p, n, r, ms, color) {
  for (let i = 0; i < n; i++) {
    const a = Math.random() * Math.PI * 2;
    const d = r * (0.6 + Math.random() * 0.6);
    const sp = effects.track(scene.add.rectangle(p.x + Math.cos(a) * d, p.y + Math.sin(a) * d, 2, 2, color).setDepth(32).setAlpha(0.4));
    scene.tweens.add({ targets: sp, x: p.x, y: p.y, alpha: 1, duration: ms * (0.6 + Math.random() * 0.4), onComplete: () => sp.destroy() });
  }
}
