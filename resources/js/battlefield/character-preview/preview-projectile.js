import Phaser from 'phaser';
import { AttackType, TextureKey } from '@battlefield/constants.js';
import {
  ensureSlashTexture,
  ensureShurikenTexture,
  ensureArrowTexture,
  ensureBladeTexture,
} from '@battlefield/projectile-textures.js';

const ARC_TYPES = {
  [AttackType.SLASH]:    { texture: 'proj-slash',    ensure: ensureSlashTexture,    scale: 2.0, lift: 60, durationMs: 420, spin: false },
  [AttackType.ARROW]:    { texture: 'proj-arrow',    ensure: ensureArrowTexture,    scale: 2.0, lift: 80, durationMs: 390, spin: false },
  [AttackType.BLADE]:    { texture: 'proj-blade',    ensure: ensureBladeTexture,    scale: 1.4, lift: 36, durationMs: 390, spin: false },
  [AttackType.SHURIKEN]: { texture: 'proj-shuriken', ensure: ensureShurikenTexture, scale: 1.8, lift: 40, durationMs: 360, spin: true  },
};

/**
 * Spawns a projectile flying from (fromX, fromY) to a fixed local point
 * (toX, toY) within the preview scene — the preview-only counterpart to
 * Projectile.spawn(), which always targets scene.layout.boss.anchor.
 *
 * @param {Phaser.Scene} scene
 * @param {{fromX: number, fromY: number, toX: number, toY: number, attackType: string, onComplete?: Function}} opts
 * @return {void}
 */
export function spawnPreviewProjectile(scene, { fromX, fromY, toX, toY, attackType, onComplete }) {
  if (attackType === AttackType.BLAST) {
    spawnPreviewBlast(scene, fromX, fromY, toX, toY, onComplete);
    return;
  }

  const cfg = ARC_TYPES[attackType] ?? ARC_TYPES[AttackType.SLASH];
  cfg.ensure(scene);
  const flyLeft = fromX > toX;
  const proj = scene.add.image(fromX, fromY, cfg.texture).setScale(cfg.scale).setDepth(10);
  if (flyLeft) {
    proj.setFlipX(true);
  }
  const state = { t: 0 };
  scene.tweens.add({
    targets: state,
    t: 1,
    duration: cfg.durationMs,
    ease: 'Power2.easeIn',
    onUpdate: () => {
      const t = state.t;
      const x = fromX + (toX - fromX) * t;
      const y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * cfg.lift;
      proj.setPosition(x, y);
      if (cfg.spin) {
        proj.rotation += 0.18;
      } else {
        const dy = (toY - fromY) - Math.cos(t * Math.PI) * cfg.lift * Math.PI;
        proj.rotation = Math.atan2(dy, Math.abs(toX - fromX)) * (flyLeft ? -1 : 1);
      }
    },
    onComplete: () => {
      proj.destroy();
      onComplete?.();
    },
  });
}

function spawnPreviewBlast(scene, fromX, fromY, toX, toY, onComplete) {
  const sprite = scene.add.sprite(fromX, fromY, TextureKey.FIREBALL).setScale(3).setTint(0xc026d3).setDepth(10);
  if (!scene.anims.exists('fireball-loop')) {
    scene.anims.create({
      key: 'fireball-loop',
      frames: scene.anims.generateFrameNumbers(TextureKey.FIREBALL, { start: 0, end: 3 }),
      frameRate: 16,
      repeat: -1,
    });
  }
  sprite.play('fireball-loop');
  const lift = 55;
  scene.tweens.add({
    targets: sprite,
    x: toX,
    y: toY,
    duration: 420,
    ease: 'Sine.easeIn',
    onUpdate: tween => {
      const t = tween.progress;
      sprite.y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      sprite.rotation += 0.2;
    },
    onComplete: () => {
      sprite.destroy();
      onComplete?.();
    },
  });
}
