import { spawnProjectile } from './projectile.js';

/**
 * Scale the fighter should settle back to after an attack. Read at
 * return-tween creation time (not captured up front) so a damage rescale
 * landing mid-attack is not undone by the return tween.
 */
function restScale(scene, fighter) {
  return scene.fighterRestScale?.(fighter) ?? fighter.sprite.scaleX;
}

// ─── Shared helpers ───────────────────────────────────────────────────────────

/**
 * Dash fighter forward, fire onPeak() at the apex, then return to base.
 * onPeak receives { dashX, dashY, towardBoss, sc } so each handler can
 * spawn effects from the exact peak position.
 */
function runDash(scene, fighter, { dashDist, runDur, returnDur, scalePeak = 1.1 }, onPeak) {
  const sc         = fighter.sprite.scaleX;
  const towardBoss = fighter.pos.x <= scene.layout.boss.anchor.x ? 1 : -1;
  const dashX      = fighter.pos.x + towardBoss * dashDist;

  if (fighter.body) {
    fighter.body.setTexture(fighter.ftype.key + '-run');
    fighter.body.play(fighter.ftype.key + '-run');
    fighter.body.setFlipX((towardBoss < 0) !== (fighter.ftype.baseFlipX ?? false));
  }

  scene.tweens.add({
    targets: fighter.sprite,
    x: dashX,
    scaleX: sc * scalePeak,
    scaleY: sc * scalePeak,
    duration: runDur,
    ease: 'Power2.easeIn',
    onComplete: () => {
      onPeak({ dashX, dashY: fighter.pos.y, towardBoss, sc });
      const rest = restScale(scene, fighter);
      scene.tweens.add({
        targets: fighter.sprite,
        x: fighter.pos.x,
        scaleX: rest, scaleY: rest, rotation: 0,
        duration: returnDur,
        ease: 'Back.easeOut',
        onComplete: () => {
          if (fighter.body) {
            fighter.body.setTexture(fighter.ftype.key + '-idle');
            fighter.body.play(fighter.ftype.key + '-idle');
            fighter.body.setFlipX(fighter.ftype.baseFlipX ?? false);
          }
        },
      });
    },
  });
}

/**
 * Draw a glowing arc at (cx, cy) pointing toward the boss.
 * colOuter / colInner are hex colours for the glow and core line.
 */
function swingArc(scene, fighter, cx, cy, { isKillShot, colOuter, colInner, delay = 0, onImpact = null }) {
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

  // Crescent via fillTriangle — thick belly, sharp tips, convex toward boss.
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

  // Ghost trail
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

  // Main blade: grow at fighter position then fly through boss
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
 * Omnidirectional particle burst at (x, y) — used for the impact flash.
 */
function slashBurst(scene, fighter, x, y, { isKillShot, tints }) {
  const ps    = fighter.displaySize * 0.014;
  const count = isKillShot ? 20 : 9;
  const speed = isKillShot ? 200 : 110;
  const burst = scene.add.particles(x, y, 'spark', {
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

// ─── Per-character attack handlers ───────────────────────────────────────────
// Each handler: (scene, fighter, { isKillShot, damage, maxHp, onImpact }) => void

export const ATTACK_HANDLERS = {

  // ── Knight ──────────────────────────────────────────────────────────────────
  slash(scene, fighter, { isKillShot, damage, maxHp, onImpact }) {
    runDash(scene, fighter, {
      dashDist:  fighter.displaySize * (isKillShot ? 0.55 : 0.35),
      runDur:    isKillShot ? 180 : 130,
      returnDur: isKillShot ? 320 : 240,
    }, ({ dashX, dashY, towardBoss, sc }) => {
      const cx = dashX;
      const cy = dashY - fighter.displaySize * 0.15;

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
  },

  // ── Redhat — Magic Circle → Beam ────────────────────────────────────────────
  // Stands still: channels energy → spinning rune circle → fires purple beam
  blast(scene, fighter, { isKillShot, damage, maxHp, onImpact }) {
    const sc          = fighter.sprite.scaleX;
    const fx          = fighter.pos.x;
    const fy          = fighter.pos.y;
    const towardBoss  = fx <= scene.layout.boss.anchor.x ? 1 : -1;
    const ds          = fighter.displaySize;
    const circleX     = fx + towardBoss * ds * 0.48;
    const circleY     = fy - ds * 0.08;
    const r           = ds * (isKillShot ? 0.52 : 0.36);
    const chargeDur   = isKillShot ? 210 : 150;

    // Charge pulse: scale up → recoil → settle
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
            const rest = restScale(scene, fighter);
            scene.tweens.add({
              targets: fighter.sprite, scaleX: rest, scaleY: rest,
              duration: 230, ease: 'Back.easeOut',
            });
          },
        });
      },
    });

    // Magic circle — draw in local space so rotation works
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

    // Fire at peak charge
    scene.time.delayedCall(chargeDur + 15, () => {
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

      spawnProjectile(scene, circleX, circleY, 'blast', damage, maxHp, onImpact, fighter.sprite.scaleX);
    });

    scene.time.delayedCall(chargeDur + 55, () => {
      scene.tweens.add({
        targets: g, alpha: 0, duration: isKillShot ? 310 : 215, ease: 'Power2.easeIn',
        onComplete: () => g.destroy(),
      });
    });
  },

  // ── Ninjagirl — Teleport Dash + Shuriken Fan ─────────────────────────────────
  // Ultra-fast blink with ghost afterimages → fan of shurikens at peak
  shuriken(scene, fighter, { isKillShot, damage, maxHp, onImpact }) {
    const sc         = fighter.sprite.scaleX;
    const fx         = fighter.pos.x;
    const fy         = fighter.pos.y;
    const towardBoss = fx <= scene.layout.boss.anchor.x ? 1 : -1;
    const ds         = fighter.displaySize;
    const dashDist   = ds * (isKillShot ? 1.1 : 0.75);
    const dashX      = fx + towardBoss * dashDist;
    const dashDur    = isKillShot ? 72 : 52;   // blink-fast
    const returnDur  = isKillShot ? 235 : 165;
    const impactY    = fy - ds * 0.2;

    // Ghost afterimages spawned during blink
    const numGhosts = isKillShot ? 3 : 2;
    for (let i = 0; i < numGhosts; i++) {
      const t      = (i + 1) / (numGhosts + 1);
      const ghostX = fx + towardBoss * dashDist * t;
      const frame  = fighter.body?.anims?.currentFrame?.index ?? 0;
      scene.time.delayedCall(i * 16, () => {
        const ghost = scene.add.sprite(ghostX, fy, fighter.ftype.key + '-idle', frame)
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

    if (fighter.body) {
      fighter.body.setTexture(fighter.ftype.key + '-run');
      fighter.body.play(fighter.ftype.key + '-run');
      fighter.body.setFlipX((towardBoss < 0) !== (fighter.ftype.baseFlipX ?? false));
    }

    scene.tweens.add({
      targets: fighter.sprite, x: dashX,
      scaleX: sc * 1.08, scaleY: sc * 1.08,
      duration: dashDur, ease: 'Power3.easeIn',
      onComplete: () => {
        // Shuriken fan — spread of narrow particle jets
        const toX        = scene.layout.boss.anchor.x;
        const toY        = scene.layout.boss.anchor.y;
        const baseDeg    = Phaser.Math.RadToDeg(Math.atan2(toY - impactY, toX - dashX));
        const fanAngles  = isKillShot ? [-28, -14, 0, 14, 28] : [-20, 0, 20];
        const ps         = ds * 0.014;

        fanAngles.forEach((offsetDeg, idx) => {
          scene.time.delayedCall(idx * 22, () => {
            const ang   = baseDeg + offsetDeg;
            const burst = scene.add.particles(dashX, impactY, 'spark', {
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

        slashBurst(scene, fighter, dashX, impactY, {
          isKillShot, tints: [0xf0abfc, 0xe879f9, 0xa855f7, 0xfdf4ff],
        });
        scene.tweens.add({
          targets: fighter.sprite,
          scaleX: sc * (isKillShot ? 1.42 : 1.18), scaleY: sc * (isKillShot ? 1.42 : 1.18),
          rotation: towardBoss * 0.08, duration: isKillShot ? 62 : 46, ease: 'Power3.easeIn',
        });

        spawnProjectile(scene, dashX, impactY, 'shuriken', damage, maxHp, onImpact, fighter.sprite.scaleX);

        const rest = restScale(scene, fighter);
        scene.tweens.add({
          targets: fighter.sprite, x: fx, scaleX: rest, scaleY: rest, rotation: 0,
          duration: returnDur, ease: 'Back.easeOut',
          onComplete: () => {
            if (fighter.body) {
              fighter.body.setTexture(fighter.ftype.key + '-idle');
              fighter.body.play(fighter.ftype.key + '-idle');
              fighter.body.setFlipX(fighter.ftype.baseFlipX ?? false);
            }
          },
        });
      },
    });
  },

  // ── Shinobi — Shadow Dash to Boss + Cross-Slash ──────────────────────────────
  // Teleport-blink all the way to boss with shadow ghosts, double arc slash, return blink
  blade(scene, fighter, { isKillShot, damage, maxHp, onImpact }) {
    const sc         = fighter.sprite.scaleX;
    const fx         = fighter.pos.x;
    const fy         = fighter.pos.y;
    const bossX      = scene.layout.boss.anchor.x;
    const bossY      = scene.layout.boss.anchor.y;
    const towardBoss = fx <= bossX ? 1 : -1;
    const ds         = fighter.displaySize;

    // Dash right next to boss (accounting for both x and y distance)
    const dashX   = bossX + towardBoss * ds * 0.2;  // just beside boss
    const dashY   = bossY + ds * 0.3;               // just below boss
    const dashDur = isKillShot ? 170 : 120;
    const retDur  = isKillShot ? 340 : 240;

    const bodyScale  = fighter.body?.scaleX ?? sc;
    const flipX      = (towardBoss < 0) !== (fighter.ftype.baseFlipX ?? false);
    const numGhosts  = isKillShot ? 5 : 4;
    const ghostEvery = dashDur / numGhosts;
    let   ghostCount = 0;

    if (fighter.body) {
      fighter.body.setTexture(fighter.ftype.key + '-run');
      fighter.body.play(fighter.ftype.key + '-run');
      fighter.body.setFlipX(flipX);
    }

    scene.tweens.add({
      targets: fighter.sprite, x: dashX, y: dashY,
      scaleX: sc * 1.12, scaleY: sc * 1.12,
      duration: dashDur, ease: 'Power3.easeIn',
      onUpdate: (tween) => {
        // Spawn ghost at fighter's current position as it moves
        if (ghostCount < numGhosts && tween.elapsed >= ghostCount * ghostEvery) {
          const curX  = fighter.sprite.x;
          const curY  = fighter.sprite.y;
          const frame = fighter.body?.anims?.currentFrame?.index ?? 0;
          const alpha = 0.55 - ghostCount * 0.08;
          const tint  = ghostCount % 2 === 0 ? 0x4c1d95 : 0x7c3aed;
          ghostCount++;
          const ghost = scene.add.sprite(curX, curY, fighter.ftype.key + '-run', frame)
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

        spawnProjectile(scene, strikeX, strikeY, 'blade', damage, maxHp, onImpact, fighter.sprite.scaleX);

        // Ghost traces on return blink
        const retGhosts = isKillShot ? 2 : 1;
        for (let i = 0; i < retGhosts; i++) {
          const t      = (i + 1) / (retGhosts + 1);
          const ghostX = dashX + (fx - dashX) * t;
          const ghostY = dashY + (fy - dashY) * t;
          scene.time.delayedCall(i * 30 + 55, () => {
            const g2 = scene.add.sprite(ghostX, ghostY, fighter.ftype.key + '-idle', 0)
              .setScale(bodyScale)
              .setFlipX(fighter.ftype.baseFlipX ?? false)
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

        const rest = restScale(scene, fighter);
        scene.tweens.add({
          targets: fighter.sprite, x: fx, y: fy, scaleX: rest, scaleY: rest, rotation: 0,
          duration: retDur, ease: 'Back.easeOut',
          onComplete: () => {
            if (fighter.body) {
              fighter.body.setTexture(fighter.ftype.key + '-idle');
              fighter.body.play(fighter.ftype.key + '-idle');
              fighter.body.setFlipX(fighter.ftype.baseFlipX ?? false);
            }
          },
        });
      },
    });
  },

  // ── Adventurer — Draw Bow → Hold → Release ───────────────────────────────────
  // Steps back, draws bow with energy gathering, then fires arrow streak (no forward dash)
  arrow(scene, fighter, { isKillShot, damage, maxHp, onImpact }) {
    const sc         = fighter.sprite.scaleX;
    const fx         = fighter.pos.x;
    const fy         = fighter.pos.y;
    const towardBoss = fx <= scene.layout.boss.anchor.x ? 1 : -1;
    const ds         = fighter.displaySize;
    const stepBack   = ds * (isKillShot ? 0.18 : 0.12);
    const drawDur    = isKillShot ? 205 : 148;
    const holdDur    = isKillShot ? 115 : 78;
    const fireY      = fy - ds * 0.12;

    // Draw stance: step back + lean away from boss
    scene.tweens.add({
      targets: fighter.sprite,
      x:        fx - towardBoss * stepBack,
      rotation: -towardBoss * (isKillShot ? 0.17 : 0.12),
      scaleX:   sc * 1.07, scaleY: sc * 1.07,
      duration: drawDur, ease: 'Power2.easeOut',
      onComplete: () => {
        const bowX = fx - towardBoss * stepBack;

        // Energy gathering on the bow while holding
        const gather = scene.add.particles(bowX, fireY, 'spark', {
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

          // Release snap: lurch forward then back
          scene.tweens.add({
            targets:  fighter.sprite,
            x:        fx + towardBoss * ds * 0.09,
            rotation: towardBoss * 0.04,
            scaleX:   sc * 0.9, scaleY: sc * 0.9,
            duration: isKillShot ? 52 : 38, ease: 'Power3.easeIn',
            onComplete: () => {
              // Arrow streak — tight directional burst
              const toX      = scene.layout.boss.anchor.x;
              const toY      = scene.layout.boss.anchor.y;
              const angle    = Math.atan2(toY - fireY, toX - fx);
              const angleDeg = Phaser.Math.RadToDeg(angle);
              const ps       = ds * 0.014;

              const streak = scene.add.particles(fx, fireY, 'spark', {
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

              // Arrow trail glow line
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

              slashBurst(scene, fighter, fx + towardBoss * ds * 0.28, fireY, {
                isKillShot, tints: [0xfde68a, 0xfbbf24, 0xf97316, 0x86efac],
              });

              spawnProjectile(scene, fx, fy, 'arrow', damage, maxHp, onImpact, fighter.sprite.scaleX);

              // Return to base
              const rest = restScale(scene, fighter);
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
  },
};
