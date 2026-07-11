import Phaser from 'phaser';
import { AttackType } from '@battlefield/constants.js';
import { restScale, slashBurst } from './fx.js';

/**
 * Redhat — magic circle → beam.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ isKillShot: boolean, damage: number, maxHp: number, onImpact: Function|null, onEffect: Function|null }} opts
 * @return {void}
 */
export function blast(scene, fighter, { isKillShot, damage, maxHp, onImpact, onEffect }) {
  const sc          = fighter.sprite.scaleX;
  const fx          = fighter.pos.x;
  const fy          = fighter.pos.y;
  const towardBoss  = fx <= scene.layout.boss.anchor.x ? 1 : -1;
  const ds          = fighter.displaySize;
  const circleX     = fx + towardBoss * ds * 0.48;
  const circleY     = fy - ds * 0.08;
  const r           = ds * (isKillShot ? 0.52 : 0.36);
  const chargeDur   = isKillShot ? 210 : 150;

  scene.tweens.add({
    targets: fighter.sprite,
    scaleX: sc * (isKillShot ? 1.22 : 1.14), scaleY: sc * (isKillShot ? 1.22 : 1.14),
    duration: chargeDur, ease: 'Power2.easeOut',
    onComplete: () => {
      scene.tweens.add({
        targets: fighter.sprite,
        scaleX: sc * (isKillShot ? 0.87 : 0.92), scaleY: sc * (isKillShot ? 0.87 : 0.92),
        duration: 75, ease: 'Power3.easeIn',
        onComplete: () => {
          const rest = restScale(fighter);
          scene.tweens.add({
            targets: fighter.sprite, scaleX: rest, scaleY: rest,
            duration: 230, ease: 'Back.easeOut',
          });
        },
      });
    },
  });

  const g = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD).setAlpha(0);
  g.setPosition(circleX, circleY);
  g.lineStyle(isKillShot ? 5 : 3, 0x7c3aed, 0.35);
  g.strokeCircle(0, 0, r);
  g.lineStyle(isKillShot ? 3 : 2, 0xa855f7, 0.9);
  g.strokeCircle(0, 0, r * 0.72);
  g.lineStyle(isKillShot ? 2 : 1, 0x22d3ee, 0.7);
  g.strokeCircle(0, 0, r * 0.44);
  g.lineStyle(1, 0xc026d3, 0.55);
  g.lineBetween(-r * 0.88, 0, r * 0.88, 0);
  g.lineBetween(0, -r * 0.88, 0, r * 0.88);
  const d = r * 0.62;
  g.lineStyle(1, 0x8b5cf6, 0.38);
  g.lineBetween(-d, -d, d, d);
  g.lineBetween(d, -d, -d, d);

  scene.tweens.add({ targets: g, alpha: isKillShot ? 0.95 : 0.82, duration: chargeDur, ease: 'Power2.easeOut' });
  scene.tweens.add({ targets: g, rotation: towardBoss * Math.PI * 2, duration: isKillShot ? 560 : 400, ease: 'Linear' });

  scene.time.delayedCall(chargeDur + 15, () => {
    onEffect?.(fighter.pos.x, fighter.pos.y);
    slashBurst(scene, fighter, circleX, circleY, {
      isKillShot, tints: [0x7c3aed, 0xa855f7, 0x22d3ee, 0xc026d3, 0xffffff],
    });

    const bossX = scene.layout.boss.anchor.x;
    const bossY = scene.layout.boss.anchor.y;
    const beamG = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD).setAlpha(0.9);
    beamG.lineStyle(isKillShot ? 10 : 6, 0x7c3aed, 0.45);
    beamG.lineBetween(circleX, circleY, bossX, bossY);
    beamG.lineStyle(isKillShot ? 5 : 3, 0xa855f7, 0.9);
    beamG.lineBetween(circleX, circleY, bossX, bossY);
    beamG.lineStyle(isKillShot ? 2 : 1, 0xffffff, 1.0);
    beamG.lineBetween(circleX, circleY, bossX, bossY);
    scene.tweens.add({
      targets: beamG, alpha: 0, duration: isKillShot ? 380 : 260, ease: 'Power2.easeIn',
      onComplete: () => beamG.destroy(),
    });

    scene.projectile.spawn(circleX, circleY, AttackType.BLAST, damage, maxHp, onImpact, fighter.sprite.scaleX);
  });

  scene.time.delayedCall(chargeDur + 55, () => {
    scene.tweens.add({
      targets: g, alpha: 0, duration: isKillShot ? 310 : 215, ease: 'Power2.easeIn',
      onComplete: () => g.destroy(),
    });
  });
}
