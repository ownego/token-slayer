import { runDash, swingArc, slashBurst } from './fx.js';

/**
 * Knight — forward dash with arc swing.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ isKillShot: boolean, damage: number, maxHp: number, onImpact: Function|null, onEffect: Function|null }} opts
 * @return {void}
 */
export function slash(scene, fighter, { isKillShot, damage, maxHp, onImpact, onEffect }) {
  runDash(scene, fighter, {
    dashDist:  fighter.displaySize * (isKillShot ? 0.55 : 0.35),
    runDur:    isKillShot ? 180 : 130,
    returnDur: isKillShot ? 320 : 240,
  }, ({ dashX, dashY, towardBoss, sc }) => {
    const cx = dashX;
    const cy = dashY - fighter.displaySize * 0.15;
    onEffect?.(cx, cy);

    swingArc(scene, fighter, cx, cy, {
      isKillShot, colOuter: 0xffffff, colInner: 0x93c5fd, onImpact,
    });
    slashBurst(scene, fighter, cx, dashY - fighter.displaySize * 0.25, {
      isKillShot, tints: [0xffffff, 0xbfdbfe, 0x93c5fd, 0x60a5fa],
    });
    scene.tweens.add({
      targets: fighter.sprite,
      scaleX: sc * (isKillShot ? 1.5 : 1.22),
      scaleY: sc * (isKillShot ? 1.5 : 1.22),
      rotation: towardBoss * 0.1,
      duration: isKillShot ? 80 : 60,
      ease: 'Power3.easeIn',
    });
  });
}
