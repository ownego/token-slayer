import Phaser from 'phaser';
import { TextureKey } from '@battlefield/constants.js';

/**
 * Returns the resting scale for a fighter after an attack.
 *
 * @param {{ displaySize: number, baseSize: number, damageScale?: number }} fighter
 * @return {number}
 */
export function restScale(fighter) {
  return (fighter.displaySize / fighter.baseSize) * (fighter.damageScale ?? 1);
}

/**
 * Dashes a fighter forward, fires onPeak at the apex, then returns to base.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {{ dashDist: number, runDur: number, returnDur: number, scalePeak?: number }} opts
 * @param {Function} onPeak
 * @return {void}
 */
export function runDash(scene, fighter, { dashDist, runDur, returnDur, scalePeak = 1.1 }, onPeak) {
  const sc         = fighter.sprite.scaleX;
  const towardBoss = fighter.pos.x <= scene.layout.boss.anchor.x ? 1 : -1;
  const dashX      = fighter.pos.x + towardBoss * dashDist;

  scene.tweens.add({
    targets: fighter.sprite,
    x: dashX,
    scaleX: sc * scalePeak,
    scaleY: sc * scalePeak,
    duration: runDur,
    ease: 'Power2.easeIn',
    onComplete: () => {
      onPeak({ dashX, dashY: fighter.pos.y, towardBoss, sc });
      const rest = restScale(fighter);
      scene.tweens.add({
        targets: fighter.sprite,
        x: fighter.pos.x,
        scaleX: rest, scaleY: rest, rotation: 0,
        duration: returnDur,
        ease: 'Back.easeOut',
      });
    },
  });
}

/**
 * Draws a glowing arc at (cx, cy) pointing toward the boss.
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {number} cx
 * @param {number} cy
 * @param {{ isKillShot: boolean, colOuter: number, colInner: number, delay?: number, onImpact?: Function|null }} opts
 * @return {void}
 */
export function swingArc(scene, fighter, cx, cy, { isKillShot, colOuter, colInner, delay = 0, onImpact = null }) {
  const toX       = scene.layout.boss.anchor.x;
  const toY       = scene.layout.boss.anchor.y;
  const baseAngle = Math.atan2(toY - cy, toX - cx);
  const r         = fighter.displaySize * (isKillShot ? 0.44 * 1.25 : 0.44);
  const arcSpan   = Math.PI * (isKillShot ? 1.05 * 1.15 : 1.05);
  const startA    = baseAngle - arcSpan / 2;
  const endA      = baseAngle + arcSpan / 2;
  const maxThick  = r * (isKillShot ? 0.38 * 1.15 : 0.38);
  const STEPS     = 24;
  const dx        = toX - cx;
  const dy        = toY - cy;

  function drawCrescent(g, outerR, thick, fillColor, fillAlpha) {
    g.fillStyle(fillColor, fillAlpha);
    const t = outerR / r;
    for (let i = 0; i < STEPS; i++) {
      const t1 = i / STEPS, t2 = (i + 1) / STEPS;
      const a1 = startA + (endA - startA) * t1;
      const a2 = startA + (endA - startA) * t2;
      const rI1 = outerR - thick * t * Math.sin(t1 * Math.PI);
      const rI2 = outerR - thick * t * Math.sin(t2 * Math.PI);
      const ox1 = Math.cos(a1) * outerR, oy1 = Math.sin(a1) * outerR;
      const ox2 = Math.cos(a2) * outerR, oy2 = Math.sin(a2) * outerR;
      const ix1 = Math.cos(a1) * rI1,    iy1 = Math.sin(a1) * rI1;
      const ix2 = Math.cos(a2) * rI2,    iy2 = Math.sin(a2) * rI2;
      g.fillTriangle(ox1, oy1, ox2, oy2, ix1, iy1);
      g.fillTriangle(ox2, oy2, ix2, iy2, ix1, iy1);
    }
  }

  function drawBlade(g, outerR) {
    drawCrescent(g, outerR * 1.12, maxThick * 1.2, colOuter,  0.25);
    drawCrescent(g, outerR,        maxThick,        colInner,  0.95);
    drawCrescent(g, outerR * 0.97, maxThick * 0.35, 0xffffff,  0.75);
  }

  const trailCount = isKillShot ? 4 : 3;
  for (let i = 0; i < trailCount; i++) {
    const ghostR = r * (0.28 + i * 0.22);
    const ghostG = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD).setAlpha(0);
    ghostG.x = cx; ghostG.y = cy;
    drawBlade(ghostG, ghostR);
    scene.time.delayedCall(delay + i * 18, () => {
      scene.tweens.add({
        targets: ghostG, alpha: 0.45, duration: 25, ease: 'Power2.easeOut',
        onComplete: () => scene.tweens.add({
          targets: ghostG, alpha: 0, duration: isKillShot ? 220 : 150, ease: 'Power2.easeIn',
          onComplete: () => ghostG.destroy(),
        }),
      });
    });
  }

  const g = scene.add.graphics().setDepth(3).setBlendMode(Phaser.BlendModes.ADD).setAlpha(0);
  g.x = cx; g.y = cy;
  drawBlade(g, r);
  g.setScale(0.08);

  scene.time.delayedCall(delay + trailCount * 18, () => {
    scene.tweens.add({
      targets: g, scaleX: 1, scaleY: 1,
      alpha: isKillShot ? 1.0 : 0.9,
      duration: isKillShot ? 360 : 300, ease: 'Power3.easeOut',
      onComplete: () => {
        scene.tweens.add({
          targets: g,
          x: cx + dx * 1.0,
          y: cy + dy * 1.0,
          scaleX: isKillShot ? 1.3 : 1.1,
          scaleY: isKillShot ? 1.3 : 1.1,
          alpha: isKillShot ? 1.0 : 0.85,
          duration: isKillShot ? 380 : 320, ease: 'Power2.easeIn',
          onComplete: () => {
            onImpact?.();
            scene.tweens.add({
              targets: g, alpha: 0, scaleX: 1.6, scaleY: 1.6,
              duration: 120, ease: 'Power2.easeOut',
              onComplete: () => g.destroy(),
            });
          },
        });
      },
    });
  });
}

/**
 * Spawns an omnidirectional particle burst at (x, y).
 *
 * @param {Phaser.Scene} scene
 * @param {object} fighter
 * @param {number} x
 * @param {number} y
 * @param {{ isKillShot: boolean, tints: number[] }} opts
 * @return {void}
 */
export function slashBurst(scene, fighter, x, y, { isKillShot, tints }) {
  const ps    = fighter.displaySize * 0.014;
  const count = isKillShot ? 20 : 9;
  const speed = isKillShot ? 200 : 110;
  const burst = scene.add.particles(x, y, TextureKey.SPARK, {
    tint: { onEmit: () => Phaser.Math.RND.pick(tints) },
    scale: { start: ps * (isKillShot ? 4.5 : 2.8), end: 0 },
    alpha: { start: 0.92, end: 0 },
    speed: { min: speed * 0.35, max: speed },
    angle: { min: 0, max: 360 },
    lifespan: { min: 220, max: isKillShot ? 550 : 380 },
    frequency: -1, quantity: count,
    blendMode: Phaser.BlendModes.ADD,
  });
  burst.explode(count);
  scene.time.delayedCall(700, () => { if (burst.scene) burst.destroy(); });
}
