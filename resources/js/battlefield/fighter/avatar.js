import Phaser from 'phaser';

/**
 * Fixed canvas-texture size for avatars, deliberately much smaller than the
 * source images (Slack/Google avatars are commonly 512px+). Drawing the
 * full-res source down to this size lets the 2D context's own high-quality
 * resampling (configured below) do that big reduction once; the remaining
 * shrink to the on-screen avatar size (fighterDisplayConfig's avatarPx caps
 * out at 46px) is then only ~3x even at 3x device pixel ratio, which
 * Phaser's single-pass bilinear GPU sampler can do without visible blur.
 * Uploading the source at its native resolution and leaving the whole
 * 10-20x reduction to that one bilinear pass is what caused the blur.
 *
 * @type {number}
 */
const AVATAR_TEXTURE_SIZE = 160;

/**
 * Loads an avatar image as a circular canvas texture.
 *
 * @param {Phaser.Scene} scene
 * @param {number|string} fighterId
 * @param {string} avatarUrl
 * @return {Promise<string>}
 */
export function loadAvatarTexture(scene, fighterId, avatarUrl) {
  const key = `fighter-${fighterId}`;
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => {
      if (scene.isShuttingDown) {
        reject(new Error('scene destroyed before avatar load'));
        return;
      }
      if (scene.textures.exists(key)) {
        scene.textures.remove(key);
      }
      const size = AVATAR_TEXTURE_SIZE;
      const canvas = document.createElement('canvas');
      canvas.width = size;
      canvas.height = size;
      const ctx = canvas.getContext('2d');
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = 'high';
      ctx.beginPath();
      ctx.arc(size / 2, size / 2, size / 2, 0, Math.PI * 2);
      ctx.clip();
      ctx.drawImage(img, 0, 0, size, size);
      scene.textures.addCanvas(key, canvas);
      scene.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
      resolve(key);
    };
    img.onerror = () => reject(new Error(`avatar load failed: ${avatarUrl}`));
    img.src = avatarUrl;
  });
}

/**
 * Creates a fallback avatar texture using a colored circle with a generic
 * person silhouette (head + shoulders), instead of a text glyph — a photo
 * or a drawn icon reads as "an avatar" at a glance in the small minion
 * badge/fighter-head size this renders at; a bare letter reads as "no
 * avatar loaded yet" even when it's actually the final, permanent state
 * (e.g. an account with no avatar_url at all) — caught live 2026-09-24.
 *
 * @param {Phaser.Scene} scene
 * @param {{ id: number|string }} fighter
 * @return {string}
 */
export function makeFallbackAvatarTexture(scene, fighter) {
  const key = `fighter-${fighter.id}-fallback`;
  if (scene.textures.exists(key)) {
    return key;
  }
  const size = 128;
  const radius = size / 2;
  const palette = [0x6366f1, 0x10b981, 0xf59e0b, 0xec4899, 0x14b8a6, 0xf97316, 0x8b5cf6, 0x0ea5e9];
  const color = palette[Math.abs(Number(fighter.id) || 0) % palette.length];

  const rt = scene.add.renderTexture(0, 0, size, size).setVisible(false);
  const g = scene.add.graphics({ x: 0, y: 0 }).setVisible(false);
  g.fillStyle(color, 1);
  g.fillCircle(radius, radius, radius);

  // Generic silhouette: a head circle plus a shoulders arc, clipped to the
  // background circle so the shoulders never spill past its edge.
  const mask = scene.make.graphics({ x: 0, y: 0 }, false);
  mask.fillStyle(0xffffff, 1);
  mask.fillCircle(radius, radius, radius);
  g.setMask(mask.createGeometryMask());
  g.fillStyle(0xffffff, 0.92);
  g.fillCircle(radius, radius * 0.78, radius * 0.32);
  g.fillEllipse(radius, size * 1.02, radius * 1.35, radius * 1.1);
  g.clearMask();
  mask.destroy();

  rt.draw(g, 0, 0);
  rt.saveTexture(key);
  g.destroy();
  rt.destroy();
  scene.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
  return key;
}
