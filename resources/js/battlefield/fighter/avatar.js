import Phaser from 'phaser';

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
      const size = img.naturalWidth || 512;
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
 * Creates a fallback avatar texture using a colored circle with an initial letter.
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
  const palette = [0x6366f1, 0x10b981, 0xf59e0b, 0xec4899, 0x14b8a6, 0xf97316, 0x8b5cf6, 0x0ea5e9];
  const color = palette[Math.abs(Number(fighter.id) || 0) % palette.length];
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
