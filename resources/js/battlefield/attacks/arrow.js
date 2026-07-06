import Phaser from 'phaser';
import { AttackType, TextureKey } from '@battlefield/constants.js';
import { restScale, slashBurst } from './fx.js';

/**
 * Adventurer — draw bow → hold → release arrow.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ isKillShot: boolean, damage: number, maxHp: number, onImpact: Function|null, onEffect: Function|null }} opts
 * @return {void}
 */
export function arrow(scene, fighter, { isKillShot, damage, maxHp, onImpact, onEffect }) {
  const sc         = fighter.sprite.scaleX;
  const fx         = fighter.pos.x;
  const fy         = fighter.pos.y;
  const towardBoss = fx <= scene.layout.boss.anchor.x ? 1 : -1;
  const ds         = fighter.displaySize;
  const stepBack   = ds * (isKillShot ? 0.18 : 0.12);
  const drawDur    = isKillShot ? 205 : 148;
  const holdDur    = isKillShot ? 115 : 78;
  const fireY      = fy - ds * 0.12;

  scene.tweens.add({
    targets: fighter.sprite,
    x:        fx - towardBoss * stepBack,
    rotation: -towardBoss * (isKillShot ? 0.17 : 0.12),
    scaleX:   sc * 1.07, scaleY: sc * 1.07,
    duration: drawDur, ease: 'Power2.easeOut',
    onComplete: () => {
      const bowX = fx - towardBoss * stepBack;

      const gather = scene.add.particles(bowX, fireY, TextureKey.SPARK, {
        tint:      { onEmit: () => Phaser.Math.RND.pick([0xfbbf24, 0xfde68a, 0x86efac, 0xfef3c7]) },
        scale:     { start: ds * 0.014 * 1.5, end: 0 },
        alpha:     { start: 0.72, end: 0 },
        speed:     { min: 25, max: 70 },
        angle:     { min: 0, max: 360 },
        lifespan:  { min: 130, max: 260 },
        frequency: 16, quantity: 1,
        blendMode: Phaser.BlendModes.ADD,
      });
      gather.setDepth(3);

      scene.time.delayedCall(holdDur, () => {
        gather.stop();
        scene.time.delayedCall(180, () => { if (gather.scene) gather.destroy(); });

        scene.tweens.add({
          targets:  fighter.sprite,
          x:        fx + towardBoss * ds * 0.09,
          rotation: towardBoss * 0.04,
          scaleX:   sc * 0.9, scaleY: sc * 0.9,
          duration: isKillShot ? 52 : 38, ease: 'Power3.easeIn',
          onComplete: () => {
            const toX      = scene.layout.boss.anchor.x;
            const toY      = scene.layout.boss.anchor.y;
            const angle    = Math.atan2(toY - fireY, toX - fx);
            const angleDeg = Phaser.Math.RadToDeg(angle);
            const ps       = ds * 0.014;

            const streak = scene.add.particles(fx, fireY, TextureKey.SPARK, {
              tint:      { onEmit: () => Phaser.Math.RND.pick([0xfde68a, 0xfbbf24, 0xfef3c7, 0x86efac]) },
              scale:     { start: ps * (isKillShot ? 5.2 : 3.4), end: 0 },
              alpha:     { start: 0.95, end: 0 },
              speed:     { min: 520, max: isKillShot ? 850 : 660 },
              angle:     { min: angleDeg - 4, max: angleDeg + 4 },
              lifespan:  { min: 110, max: 210 },
              frequency: -1, quantity: isKillShot ? 11 : 7,
              blendMode: Phaser.BlendModes.ADD,
            });
            streak.explode(isKillShot ? 11 : 7);
            scene.time.delayedCall(350, () => { if (streak.scene) streak.destroy(); });

            const trailLen  = isKillShot ? ds * 1.5 : ds * 1.05;
            const trailEndX = fx + Math.cos(angle) * trailLen;
            const trailEndY = fireY + Math.sin(angle) * trailLen;
            const trailG    = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD).setAlpha(0.9);
            trailG.lineStyle(isKillShot ? 6 : 4, 0xfbbf24, 0.65);
            trailG.lineBetween(fx, fireY, trailEndX, trailEndY);
            trailG.lineStyle(isKillShot ? 2 : 1, 0xffffff, 1.0);
            trailG.lineBetween(fx, fireY, trailEndX, trailEndY);
            scene.tweens.add({
              targets: trailG, alpha: 0, duration: isKillShot ? 270 : 195, ease: 'Power2.easeIn',
              onComplete: () => trailG.destroy(),
            });

            onEffect?.(fx, fireY);
            slashBurst(scene, fighter, fx + towardBoss * ds * 0.28, fireY, {
              isKillShot, tints: [0xfde68a, 0xfbbf24, 0xf97316, 0x86efac],
            });

            scene.projectile.spawn(fx, fy, AttackType.ARROW, damage, maxHp, onImpact, fighter.sprite.scaleX);

            const rest = restScale(fighter);
            scene.tweens.add({
              targets:  fighter.sprite,
              x:        fx, rotation: 0, scaleX: rest, scaleY: rest,
              duration: isKillShot ? 285 : 205, ease: 'Back.easeOut',
            });
          },
        });
      });
    },
  });
}
