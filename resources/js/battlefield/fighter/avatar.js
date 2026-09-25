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
        // Never destroy an already-populated texture under this key: a
        // charging fighter's avatar "breathing" tween (Charge.handleCharging,
        // charge.js) reads/writes fighter.head's displayWidth/displayHeight
        // every tick, which touches the CURRENT Frame's sourceSize — and a
        // minion badge can independently be showing this same key too (see
        // minions.js's _positionBadge). Removing the texture destroys that
        // Frame (nulls its data) while those live GameObjects still hold a
        // direct reference to it; Phaser doesn't repoint them just because a
        // new Texture gets registered under the same key string, so the next
        // read/write on that stale Frame throws — caught live 2026-09-25 as
        // "can't access property sourceSize, this.data is null". A second
        // load resolving for a key that's already populated is redundant
        // anyway (this fighter already has a real avatar showing), so just
        // hand back what's already there instead of replacing it.
        resolve(key);
        return;
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
 * Creates a fallback avatar texture using a colored circle with an initial
 * letter — used while a real avatar is still loading (loadAvatarTexture's
 * promise hasn't resolved either way yet). Deliberately distinct from
 * makePermanentFallbackAvatarTexture: this state is normally brief, so a
 * plain letter is fine here, but must never be the one left showing forever
 * once the load genuinely fails (see that function's own docblock).
 *
 * @param {Phaser.Scene} scene
 * @param {{ id: number|string, handle?: string }} fighter
 * @return {string}
 */
export function makeFallbackAvatarTexture(scene, fighter) {
  const key = `fighter-${fighter.id}-fallback`;
  if (scene.textures.exists(key)) {
    return key;
  }
  const size = 128;
  const radius = size / 2;
  const color = fallbackColor(fighter.id);
  const initial = (fighter.handle ?? '').trim().charAt(0).toUpperCase() || '?';

  const rt = scene.add.renderTexture(0, 0, size, size).setVisible(false);
  const circle = scene.add.graphics({ x: 0, y: 0 }).setVisible(false);
  circle.fillStyle(color, 1);
  circle.fillCircle(radius, radius, radius);
  rt.draw(circle, 0, 0);
  const label = scene.add.text(0, 0, initial, {
    fontFamily: 'monospace',
    fontSize: '72px',
    color: '#ffffff',
  }).setOrigin(0.5).setVisible(false);
  rt.draw(label, radius, radius);
  rt.saveTexture(key);
  circle.destroy();
  label.destroy();
  rt.destroy();
  scene.textures.get(key).setFilter(Phaser.Textures.FilterMode.LINEAR);
  return key;
}

/**
 * Creates the fallback avatar texture used once a real avatar load has
 * genuinely FAILED (loadAvatarTexture's promise rejected — no avatar_url at
 * all, a 404, a network error), as opposed to makeFallbackAvatarTexture's
 * merely-still-loading state: a colored circle with a generic person
 * silhouette (head + shoulders) instead of a text glyph. A photo or a drawn
 * icon reads as "an avatar" at a glance in the small minion badge/
 * fighter-head size this renders at; a bare letter reads as "no avatar
 * loaded yet" even when it's actually permanent — caught live 2026-09-24.
 *
 * @param {Phaser.Scene} scene
 * @param {{ id: number|string }} fighter
 * @return {string}
 */
export function makePermanentFallbackAvatarTexture(scene, fighter) {
  const key = `fighter-${fighter.id}-fallback-permanent`;
  if (scene.textures.exists(key)) {
    return key;
  }
  const size = 128;
  const radius = size / 2;
  const color = fallbackColor(fighter.id);

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

/**
 * Deterministic background color for a fighter's fallback avatar (loading
 * or permanent), picked from a small palette by id so the same fighter
 * always gets the same color across both fallback textures.
 *
 * @param {number|string} fighterId
 * @return {number}
 */
function fallbackColor(fighterId) {
  const palette = [0x6366f1, 0x10b981, 0xf59e0b, 0xec4899, 0x14b8a6, 0xf97316, 0x8b5cf6, 0x0ea5e9];

  return palette[Math.abs(Number(fighterId) || 0) % palette.length];
}
