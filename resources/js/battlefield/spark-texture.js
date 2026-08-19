import { TextureKey } from './constants.js';

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
