import Phaser from 'phaser';
import { AttackType, TextureKey } from '@battlefield/constants.js';
import { restScale, slashBurst } from './fx.js';

/**
 * Ninjagirl — teleport dash + shuriken fan.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ isKillShot: boolean, damage: number, maxHp: number, onImpact: Function|null, onEffect: Function|null }} opts
 * @return {void}
 */
export function shuriken(scene, fighter, { isKillShot, damage, maxHp, onImpact, onEffect }) {
  const sc         = fighter.sprite.scaleX;
  const fx         = fighter.pos.x;
  const fy         = fighter.pos.y;
  const towardBoss = fx <= scene.layout.boss.anchor.x ? 1 : -1;
  const ds         = fighter.displaySize;
  const dashDist   = ds * (isKillShot ? 1.1 : 0.75);
  const dashX      = fx + towardBoss * dashDist;
  const dashDur    = isKillShot ? 72 : 52;
  const returnDur  = isKillShot ? 235 : 165;
  const impactY    = fy - ds * 0.2;

  const numGhosts = isKillShot ? 3 : 2;
  for (let i = 0; i < numGhosts; i++) {
    const t      = (i + 1) / (numGhosts + 1);
    const ghostX = fx + towardBoss * dashDist * t;
    const frame  = fighter.body?.anims?.currentFrame?.textureFrame ?? `${fighter.ftype.key}-idle-0`;
    scene.time.delayedCall(i * 16, () => {
      const ghost = scene.add.sprite(ghostX, fy, TextureKey.FIGHTERS, frame)
        .setScale(fighter.sprite.scaleX, fighter.sprite.scaleY)
        .setFlipX((towardBoss < 0) !== (fighter.ftype.baseFlipX ?? false))
        .setTint(0xe879f9)
        .setAlpha(isKillShot ? 0.52 - i * 0.1 : 0.42 - i * 0.08)
        .setBlendMode(Phaser.BlendModes.ADD)
        .setDepth(1.5);
      scene.tweens.add({
        targets: ghost, alpha: 0, duration: 260, ease: 'Power2.easeIn',
        onComplete: () => ghost.destroy(),
      });
    });
  }

  scene.tweens.add({
    targets: fighter.sprite, x: dashX,
    scaleX: sc * 1.08, scaleY: sc * 1.08,
    duration: dashDur, ease: 'Power3.easeIn',
    onComplete: () => {
      const toX        = scene.layout.boss.anchor.x;
      const toY        = scene.layout.boss.anchor.y;
      const baseDeg    = Phaser.Math.RadToDeg(Math.atan2(toY - impactY, toX - dashX));
      const fanAngles  = isKillShot ? [-28, -14, 0, 14, 28] : [-20, 0, 20];
      const ps         = ds * 0.014;

      fanAngles.forEach((offsetDeg, idx) => {
        scene.time.delayedCall(idx * 22, () => {
          const ang   = baseDeg + offsetDeg;
          const burst = scene.add.particles(dashX, impactY, TextureKey.SPARK, {
            tint:      { onEmit: () => Phaser.Math.RND.pick([0xf0abfc, 0xe879f9, 0xfdf4ff, 0xffffff]) },
            scale:     { start: ps * (isKillShot ? 3.8 : 2.4), end: 0 },
            alpha:     { start: 0.95, end: 0 },
            speed:     { min: 340, max: isKillShot ? 540 : 400 },
            angle:     { min: ang - 5, max: ang + 5 },
            lifespan:  { min: 180, max: 360 },
            frequency: -1, quantity: isKillShot ? 5 : 3,
            blendMode: Phaser.BlendModes.ADD,
          });
          burst.explode(isKillShot ? 5 : 3);
          scene.time.delayedCall(480, () => { if (burst.scene) burst.destroy(); });
        });
      });

      onEffect?.(dashX, impactY);
      slashBurst(scene, fighter, dashX, impactY, {
        isKillShot, tints: [0xf0abfc, 0xe879f9, 0xa855f7, 0xfdf4ff],
      });
      scene.tweens.add({
        targets: fighter.sprite,
        scaleX: sc * (isKillShot ? 1.42 : 1.18), scaleY: sc * (isKillShot ? 1.42 : 1.18),
        rotation: towardBoss * 0.08, duration: isKillShot ? 62 : 46, ease: 'Power3.easeIn',
      });

      scene.projectile.spawn(dashX, impactY, AttackType.SHURIKEN, damage, maxHp, onImpact, fighter.sprite.scaleX);

      const rest = restScale(fighter);
      scene.tweens.add({
        targets: fighter.sprite, x: fx, scaleX: rest, scaleY: rest, rotation: 0,
        duration: returnDur, ease: 'Back.easeOut',
      });
    },
  });
}
