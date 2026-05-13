import Phaser from 'phaser';
import { BOSS_ANCHOR, TIMINGS } from './config.js';

export function spawnProjectile(scene, fromX, fromY, onImpact) {
  const sprite = scene.add.sprite(fromX, fromY, 'fireball').setScale(2);

  if (!scene.anims.exists('fireball-loop')) {
    scene.anims.create({
      key: 'fireball-loop',
      frames: scene.anims.generateFrameNumbers('fireball', { start: 0, end: 3 }),
      frameRate: 16,
      repeat: -1,
    });
  }
  sprite.play('fireball-loop');

  const lift = 30;
  const toX = BOSS_ANCHOR.x;
  const toY = BOSS_ANCHOR.y;

  scene.tweens.add({
    targets: sprite,
    x: toX,
    y: toY,
    duration: TIMINGS.projectileArcMs,
    ease: 'Sine.easeIn',
    onUpdate: tween => {
      const t = tween.progress;
      sprite.y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      sprite.rotation += 0.2;
    },
    onComplete: () => {
      sprite.destroy();
      if (typeof onImpact === 'function') {
        onImpact();
      }
    },
  });

  return sprite;
}
