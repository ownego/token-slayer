import Phaser from 'phaser';
import { HERO_POINTS } from '@battlefield/config/heroes.js';
import { fxEnergy, fxFlash, fxGather, fxLines, fxRing, fxSparks, fxStreaks, heroFxKey, playFx } from './juice.js';
import { clang, star } from './projectile.js';

// Each hero's signature big move, ported from the demo page: Iron Man's
// unibeam, Captain America's Mjolnir slam, Superman's freeze breath.

const BLUE = 0x36c7f2;
const ICE = 0x96d7ff;
const UNIBEAM_FIRE_MS = 520;
const UNIBEAM_MS = 780;
/** Mjolnir: the hammer flies to his hand, then the strip's leap, spin and slam (config/heroes.js frameMs). */
const HAMMER_IN_MS = 320;
const MJOLNIR_SPIN_MS = HAMMER_IN_MS + 140 + 130 + 130 + 160;
const MJOLNIR_SLAM_MS = MJOLNIR_SPIN_MS + 150 + 150;
const MJOLNIR_END_MS = MJOLNIR_SLAM_MS + 260 + 260;
const BOLT_MS = 140;
const BREATH_START_MS = 340;
const BREATH_MS = 1050;
const ICE_MS = 600;
const FREEZE_MAIN_MS = 1500;
const MIST_COLORS = [0xffffff, 0xbfe8ff, 0x7cc8f0];

/** @type {Object<string, object>} */
export const SIGNATURE_MOVES = {
  /** Iron Man's unibeam: his arc reactor charges, a double beam fires from his chest, ticks the boss, then bursts. */
  unibeam: {
    contacts: [
      ...[0, 1, 2, 3].map(k => ({ id: `tick-${k}`, atMs: UNIBEAM_FIRE_MS + k * 190 })),
      { id: 'hit', atMs: UNIBEAM_FIRE_MS + UNIBEAM_MS, main: true },
    ],
    uses: ['focus-charge', 'electric-impact', 'beam-cutoff-burst', 'void-implosion'],
    run(ctx) {
      const chest = ctx.pt(HERO_POINTS.ironManChest);
      reactor(ctx, chest);
      ctx.effects.schedule(80, () => playFx(ctx.fx, 'focus-charge', chest.x, chest.y, 0.4));
      fxGather(ctx.fx, chest, 14, 26, 450, 0xbff4ff);
      if (ctx.reduced) return;
      ctx.effects.schedule(UNIBEAM_FIRE_MS, () => {
        fxRing(ctx.fx, chest.x, chest.y, 4, 16, 0xbff4ff, 300, 2);
        chestBeam(ctx, chest);
      });
    },
    land(ctx, contact) {
      const { x, y } = ctx.target;
      if (!contact.main) {
        playFx(ctx.fx, 'electric-impact', x, y, 0.6);
        return;
      }
      const chest = ctx.pt(HERO_POINTS.ironManChest);
      playFx(ctx.fx, 'beam-cutoff-burst', chest.x, chest.y, 0.4, { rotation: Math.atan2(y - chest.y, x - chest.x) });
      playFx(ctx.fx, 'void-implosion', x, y, 1.4);
      fxFlash(ctx.fx, x, y, 28, 0x9fe9ff);
      fxRing(ctx.fx, x, y, 8, 64, 0xe9f6ff, 320, 4);
      fxStreaks(ctx.fx, x, y, 12, 30);
      fxLines(ctx.fx, x, y, 0xbff4ff, 14, 22);
    },
  },

  /** Captain America calls Mjolnir: it flies to his hand, he leaps, spins it and slams lightning along the ground. */
  mjolnir: {
    contacts: [{ id: 'hit', atMs: MJOLNIR_SLAM_MS + BOLT_MS, main: true }],
    uses: ['cap-mjolnir', 'electric-impact', 'earth-rupture', 'frost-nova'],
    run(ctx) {
      // under reduced motion the strip's leap and slam play alone, then the hit
      if (ctx.reduced) return;
      const { effects } = ctx;
      const hand = ctx.pt(HERO_POINTS.capHammerHand);
      const sky = { x: hand.x + ctx.dir * 90, y: hand.y - 170 };
      hammer(ctx, sky, hand, HAMMER_IN_MS, -20);
      effects.schedule(HAMMER_IN_MS - 20, () => {
        star(ctx, hand.x, hand.y, 0xffffff, 1.6, 260);
        fxRing(ctx.fx, hand.x, hand.y, 4, 18, 0xcfe8ff, 220, 2);
      });
      effects.schedule(MJOLNIR_SPIN_MS, () => {
        const over = ctx.pt({ x: 40, y: 18 });
        fxRing(ctx.fx, over.x, over.y, 6, 34, 0xcfe8ff, 300, 2);
      });
      effects.schedule(MJOLNIR_SLAM_MS, () => slam(ctx));
      effects.schedule(MJOLNIR_END_MS, () => {
        const from = ctx.pt(HERO_POINTS.capHammerHand);
        hammer(ctx, from, { x: from.x + ctx.dir * 90, y: from.y - 190 }, 380, 24);
      });
    },
    land(ctx) {
      const { x, y } = ctx.target;
      playFx(ctx.fx, 'electric-impact', x, y, 1.2);
      playFx(ctx.fx, 'earth-rupture', x, ctx.ground, 1.2, { depth: 6 });
      clang(ctx, x, y, true);
    },
  },

  /** Superman's freeze breath: a deep breath, a cone of frost from his mouth, the boss ices over, the ice shatters. */
  'freeze-breath': {
    contacts: [
      { id: 'ice', atMs: BREATH_START_MS + 260 },
      { id: 'ice-2', atMs: BREATH_START_MS + 620 },
      { id: 'hit', atMs: FREEZE_MAIN_MS, main: true },
    ],
    uses: ['frost-nova'],
    run(ctx, contacts) {
      const { effects } = ctx;
      const mouth = ctx.pt(HERO_POINTS.supermanMouth);
      fxGather(ctx.fx, mouth, 10, 18, 320, 0xe6f7ff);
      // the mist is ~50 live objects: skipped, like the ice contacts, under reduced motion or on a busy field
      if (ctx.reduced || !contacts.some(c => !c.main)) return;
      effects.schedule(BREATH_START_MS, () => {
        const stop = effects.every(32, () => {
          for (let k = 0; k < 3; k++) mist(ctx, mouth);
        });
        effects.schedule(BREATH_MS, stop);
      });
      effects.schedule(ICE_MS, () => {
        ctx.ice = iceOver(ctx);
      });
    },
    land(ctx, contact) {
      const { x, y } = ctx.target;
      if (!contact.main) {
        playFx(ctx.fx, 'frost-nova', x, y, contact.id === 'ice' ? 0.9 : 1);
        return;
      }
      playFx(ctx.fx, 'frost-nova', x, y, 1.4);
      fxFlash(ctx.fx, x, y, 22, 0xe6f7ff);
      fxStreaks(ctx.fx, x, y, 12, 28, 0xe6f7ff);
      fxSparks(ctx.fx, x, y, [0xffffff, 0xbfe8ff, 0x7cc8f0], 12, 40);
      star(ctx, x, y, 0xffffff, 2.4, 300);
      // the ice shatters a moment after the hit
      ctx.effects.schedule(150, () => ctx.ice?.());
    },
  },
};

/**
 * The arc reactor's glow on Iron Man's chest: it swells while he charges and fires, then fades.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} chest
 * @return {void}
 */
function reactor(ctx, chest) {
  const { scene, effects } = ctx;
  const halo = effects.track(scene.add.circle(chest.x, chest.y, 3, BLUE, 0.45).setDepth(31).setBlendMode(Phaser.BlendModes.ADD));
  const core = effects.track(scene.add.circle(chest.x, chest.y, 2, 0xeaffff, 0.95).setDepth(32).setBlendMode(Phaser.BlendModes.ADD));
  scene.tweens.add({ targets: [halo, core], scale: 3, duration: 500 });
  scene.tweens.add({ targets: [halo, core], alpha: 0, delay: UNIBEAM_FIRE_MS + UNIBEAM_MS, duration: 220, onComplete: () => {
    halo.destroy();
    core.destroy();
  } });
}

/**
 * The unibeam itself: an outer and an inner beam from his chest to the target, flickering, for UNIBEAM_MS.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from
 * @return {void}
 */
function chestBeam(ctx, from) {
  const { scene, effects, target } = ctx;
  const g = effects.track(scene.add.graphics().setDepth(30).setBlendMode(Phaser.BlendModes.ADD));
  const stop = effects.every(16, () => {
    const w = 0.8 + Math.random() * 0.4;
    g.clear();
    g.lineStyle(14 * w, BLUE, 0.4).lineBetween(from.x, from.y, target.x, target.y);
    g.lineStyle(8 * w, 0x7fe0ff, 0.8).lineBetween(from.x, from.y, target.x, target.y);
    g.lineStyle(3, 0xffffff, 1).lineBetween(from.x, from.y, target.x, target.y);
  });
  effects.schedule(UNIBEAM_MS, () => {
    stop();
    g.destroy();
  });
}

/**
 * Mjolnir in flight between two points on a slight arc, then gone.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {number} ms
 * @param {number} rise
 * @return {void}
 */
function hammer(ctx, from, to, ms, rise) {
  const { scene, effects } = ctx;
  const h = effects.track(scene.add.image(from.x, from.y, heroFxKey('cap-mjolnir'), 0).setScale(ctx.propScale * 1.1).setDepth(28));
  const o = { t: 0 };
  scene.tweens.add({ targets: o, t: 1, duration: ms, ease: 'Sine.easeInOut', onUpdate: () => {
    h.setPosition(Phaser.Math.Linear(from.x, to.x, o.t), Phaser.Math.Linear(from.y, to.y, o.t) - Math.sin(o.t * Math.PI) * rise);
  }, onComplete: () => h.destroy() });
}

/**
 * The slam: a flash and a ring where the hammer hits the ground, and lightning running along it to the boss.
 *
 * @param {object} ctx
 * @return {void}
 */
function slam(ctx) {
  const { target } = ctx;
  const at = ctx.pt({ x: 48, y: 79 });
  fxFlash(ctx.fx, at.x, at.y, 16, 0xe9f6ff);
  fxRing(ctx.fx, at.x, at.y, 6, 44, 0xe9f6ff, 320, 3, 0.35);
  fxStreaks(ctx.fx, at.x, at.y, 10, 22);
  const pts = [at];
  for (let k = 1; k < 6; k++) {
    pts.push({ x: Phaser.Math.Linear(at.x, target.x, k / 6), y: ctx.ground - 4 - (k % 2 ? 8 : 0) - Math.random() * 8 });
  }
  pts.push(target);
  fxEnergy(ctx.fx, pts, 420);
}

/**
 * One frost particle: from his mouth, along a cone toward the target, stretching as it goes.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} mouth
 * @return {void}
 */
function mist(ctx, mouth) {
  const { scene, effects, target } = ctx;
  const a = Math.atan2(target.y - mouth.y, target.x - mouth.x) + (Math.random() - 0.5) * 0.42;
  const d = Math.hypot(target.x - mouth.x, target.y - mouth.y) * (0.85 + Math.random() * 0.25);
  const size = Math.random() < 0.2 ? 3 : 2;
  const p = effects.track(scene.add.rectangle(mouth.x, mouth.y, size, size, MIST_COLORS[Math.floor(Math.random() * 3)]).setDepth(30).setAlpha(0.9));
  scene.tweens.add({ targets: p, x: mouth.x + Math.cos(a) * d, y: mouth.y + Math.sin(a) * d, scaleX: 3,
    duration: 380 + Math.random() * 160, onComplete: () => p.destroy() });
}

/**
 * Ices the boss over: an icy copy of its current frame on top of it, following
 * its frame, place and facing, and ice spikes at its feet. The boss sprite is
 * never tinted itself (every hit clears its tint). The copy is a hero effect,
 * so the kill ceremony's clear() removes it too.
 *
 * @param {object} ctx
 * @return {Function} Shatters the ice.
 */
function iceOver(ctx) {
  const { scene, effects, target } = ctx;
  const boss = scene.bossSprite;
  const spikes = effects.track(scene.add.graphics().setDepth(6));
  for (const [dx, h] of [[-36, 10], [-21, 16], [-6, 9], [12, 18], [27, 12], [39, 8]]) {
    spikes.fillStyle(0xe6f7ff, 0.95).fillTriangle(target.x + dx - 4, ctx.ground, target.x + dx, ctx.ground - h, target.x + dx + 4, ctx.ground);
  }
  let ice = null;
  let stop = () => {};
  if (boss?.active) {
    ice = effects.track(scene.add.sprite(boss.x, boss.y, boss.texture.key, boss.frame.name)
      .setOrigin(boss.originX, boss.originY).setDepth(boss.depth + 0.1).setTintFill(ICE).setAlpha(0.55));
    const follow = () => {
      if (!boss.active || !ice.active) return;
      ice.setTexture(boss.texture.key, boss.frame.name).setPosition(boss.x, boss.y).setScale(boss.scaleX, boss.scaleY).setFlipX(boss.flipX);
    };
    follow();
    stop = effects.every(16, follow);
  }
  return () => {
    stop();
    spikes.destroy();
    ice?.destroy();
  };
}
