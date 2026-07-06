import Phaser from 'phaser';
import { AttackType, TextureKey } from '@battlefield/constants.js';
import { restScale, swingArc, slashBurst } from './fx.js';

/**
 * Shinobi — shadow dash to boss + cross-slash.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ isKillShot: boolean, damage: number, maxHp: number, onImpact: Function|null, onEffect: Function|null }} opts
 * @return {void}
 */
export function blade(scene, fighter, { isKillShot, damage, maxHp, onImpact, onEffect }) {
  const sc         = fighter.sprite.scaleX;
  const fx         = fighter.pos.x;
  const fy         = fighter.pos.y;
  const bossX      = scene.layout.boss.anchor.x;
  const bossY      = scene.layout.boss.anchor.y;
  const towardBoss = fx <= bossX ? 1 : -1;
  const ds         = fighter.displaySize;

  const dashX   = bossX + towardBoss * ds * 0.2;
  const dashY   = bossY + ds * 0.3;
  const dashDur = isKillShot ? 170 : 120;
  const retDur  = isKillShot ? 340 : 240;

  const bodyScale  = fighter.body?.scaleX ?? sc;
  const flipX      = towardBoss < 0;
  const numGhosts  = isKillShot ? 5 : 4;
  const ghostEvery = dashDur / numGhosts;
  let   ghostCount = 0;

  scene.tweens.add({
    targets: fighter.sprite, x: dashX, y: dashY,
    scaleX: sc * 1.12, scaleY: sc * 1.12,
    duration: dashDur, ease: 'Power3.easeIn',
    onUpdate: (tween) => {
      if (ghostCount < numGhosts && tween.elapsed >= ghostCount * ghostEvery) {
        const curX  = fighter.sprite.x;
        const curY  = fighter.sprite.y;
        const frame = fighter.body?.anims?.currentFrame?.textureFrame ?? `${fighter.ftype.key}-walk-0`;
        const alpha = 0.55 - ghostCount * 0.08;
        const tint  = ghostCount % 2 === 0 ? 0x4c1d95 : 0x7c3aed;
        ghostCount++;
        const ghost = scene.add.sprite(curX, curY, TextureKey.FIGHTERS, frame)
          .setScale(bodyScale)
          .setFlipX(flipX)
          .setTint(tint)
          .setAlpha(alpha)
          .setBlendMode(Phaser.BlendModes.ADD)
          .setDepth(1.5);
        scene.tweens.add({
          targets: ghost, alpha: 0, duration: 280, ease: 'Power2.easeIn',
          onComplete: () => ghost.destroy(),
        });
      }
    },
    onComplete: () => {
      const strikeX = dashX;
      const strikeY = dashY - ds * 0.2;

      onEffect?.(strikeX, strikeY);
      swingArc(scene, fighter, strikeX, strikeY, {
        isKillShot, colOuter: 0x4c1d95, colInner: 0xa855f7, delay: 0,
      });
      swingArc(scene, fighter, strikeX, strikeY, {
        isKillShot, colOuter: 0x6d28d9, colInner: 0xf0abfc, delay: 45,
      });
      slashBurst(scene, fighter, strikeX, strikeY, {
        isKillShot, tints: [0x4c1d95, 0x7c3aed, 0xa855f7, 0xf0abfc, 0x0f0020, 0xffffff],
      });

      const flash = scene.add.graphics().setDepth(4).setBlendMode(Phaser.BlendModes.ADD)
        .setAlpha(isKillShot ? 0.88 : 0.65);
      flash.fillStyle(0xffffff, 1.0);
      flash.fillCircle(strikeX, strikeY, ds * 0.28);
      scene.tweens.add({
        targets: flash, alpha: 0, duration: 160, ease: 'Power3.easeIn',
        onComplete: () => flash.destroy(),
      });

      scene.tweens.add({
        targets: fighter.sprite,
        scaleX: sc * (isKillShot ? 1.5 : 1.25), scaleY: sc * (isKillShot ? 1.5 : 1.25),
        rotation: towardBoss * 0.12, duration: isKillShot ? 70 : 52, ease: 'Power3.easeIn',
      });

      scene.projectile.spawn(strikeX, strikeY, AttackType.BLADE, damage, maxHp, onImpact, fighter.sprite.scaleX);

      const retGhosts = isKillShot ? 2 : 1;
      for (let i = 0; i < retGhosts; i++) {
        const t      = (i + 1) / (retGhosts + 1);
        const ghostX = dashX + (fx - dashX) * t;
        const ghostY = dashY + (fy - dashY) * t;
        scene.time.delayedCall(i * 30 + 55, () => {
          const g2 = scene.add.sprite(ghostX, ghostY, TextureKey.FIGHTERS, `${fighter.ftype.key}-idle-0`)
            .setScale(bodyScale)
            .setFlipX(false)
            .setTint(0x3b0764)
            .setAlpha(0.38)
            .setBlendMode(Phaser.BlendModes.ADD)
            .setDepth(1.5);
          scene.tweens.add({
            targets: g2, alpha: 0, duration: 200, ease: 'Power2.easeIn',
            onComplete: () => g2.destroy(),
          });
        });
      }

      const rest = restScale(fighter);
      scene.tweens.add({
        targets: fighter.sprite, x: fx, y: fy, scaleX: rest, scaleY: rest, rotation: 0,
        duration: retDur, ease: 'Back.easeOut',
      });
    },
  });
}
