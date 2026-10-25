import Phaser from 'phaser';
import { HERO_POINTS } from '@battlefield/config/heroes.js';
import { has } from './contacts.js';
import { fxAnim, fxEnergy, fxFlash, fxLines, fxPop, fxRing, fxSparks, heroFxKey, playFx } from './juice.js';
import { batarang, bringBack, clang, shield, star } from './projectile.js';

// Big moves that hit the boss several times. Only the last contact is main;
// the earlier ones are visual and are dropped when the field is busy, under
// reduced motion, or after the main one on a kill shot (contacts.js).

const MISSILE_COUNT = 8;
const MISSILE_FLIGHT_MS = 620;
const SWARM_BATS = 14;
const SWARM_START_MS = 250;
const SWARM_GAP_MS = 55;
const SWARM_FLIGHT_MS = 650;
const SWARM_SPHERE_MS = 1150;
const SWARM_BLAST_MS = 1750;
const ORANGE = 0xffb347;
const GOLD = 0xffd166;
/** The ricochet: the shield leaves his arm, then four legs boss -> ground -> high point -> boss, one per LEG_MS. */
const RICOCHET_RELEASE_MS = 320;
const LEG_MS = 200;
const EXPLOSIVE_RELEASE_MS = 250;
const EXPLOSIVE_FLIGHT_MS = 320;

/** Where the I-th micro-missile leaves and lands: launches 70 ms apart, the last one main. */
const MISSILES = Array.from({ length: MISSILE_COUNT }, (_, i) => ({
  id: `missile-${i}`,
  atMs: 140 + i * 70 + MISSILE_FLIGHT_MS,
  main: i === MISSILE_COUNT - 1,
}));

/** Every fourth bat bites as it arrives; the swarm's blast is the main contact. */
const BITES = [0, 4, 8, 12].map(i => ({ id: `bite-${i}`, atMs: SWARM_START_MS + i * SWARM_GAP_MS + SWARM_FLIGHT_MS }));

/** @type {Object<string, object>} */
export const MULTI_MOVES = {
  /** Iron Man fans micro-missiles from his shoulders; they arc up and rain onto the boss. */
  'micro-missiles': {
    contacts: MISSILES,
    uses: ['warm-explosion'],
    run(ctx, contacts) {
      ctx.ends = {};
      MISSILES.forEach((m, i) => {
        const end = { x: ctx.target.x + Phaser.Math.Between(-20, 20), y: ctx.target.y + Phaser.Math.Between(-18, 18) };
        ctx.ends[m.id] = m.main ? ctx.target : end;
        if (ctx.reduced || !has(contacts, m.id)) return;
        ctx.effects.schedule(m.atMs - MISSILE_FLIGHT_MS, () => missile(ctx, ctx.pt(HERO_POINTS.ironManShoulders[i % 2]), ctx.ends[m.id]));
      });
    },
    land(ctx, contact) {
      const { x, y } = ctx.ends[contact.id] ?? ctx.target;
      fxFlash(ctx.fx, x, y, 10, ORANGE);
      fxSparks(ctx.fx, x, y, [0xffd166, 0xff7a1a, 0xffffff], 5, 22);
      playFx(ctx.fx, 'warm-explosion', x, y + 8, contact.main ? 0.8 : 0.5);
      if (contact.main) {
        fxLines(ctx.fx, x, y, 0xffd166);
        fxRing(ctx.fx, x, y, 8, 50, 0xffd166, 320, 3);
      }
    },
  },

  /** Captain America's shield bounces boss -> the ground in front of it -> a high point -> boss, then home. */
  'shield-ricochet': {
    contacts: [{ id: 'first', atMs: RICOCHET_RELEASE_MS + LEG_MS }, { id: 'hit', atMs: RICOCHET_RELEASE_MS + 4 * LEG_MS, main: true }],
    uses: ['cap-shield-spin', 'frost-nova', 'landing-dust'],
    run(ctx, contacts) {
      if (ctx.reduced) return;
      const bounce = has(contacts, 'first');
      ctx.effects.schedule(bounce ? RICOCHET_RELEASE_MS : RICOCHET_RELEASE_MS + 3 * LEG_MS, () => {
        const from = ctx.pt(HERO_POINTS.capRelease);
        fxFlash(ctx.fx, from.x, from.y, 8);
        ctx.thrown = shield(ctx, from);
        ctx.scene.tweens.add({ targets: ctx.thrown.sprite, x: ctx.target.x, y: ctx.target.y, duration: LEG_MS });
      });
    },
    land(ctx, contact) {
      const { scene, target } = ctx;
      const floor = { x: target.x - ctx.dir * 40, y: ctx.ground - 4 };
      const top = { x: target.x - ctx.dir * 12, y: target.y - 70 };
      if (!contact.main) {
        clang(ctx, target.x, target.y, true);
        if (!ctx.thrown) return;
        const s = ctx.thrown.sprite;
        // each leg starts on the hero clock, so the last one reaches the boss on the main contact
        scene.tweens.add({ targets: s, x: floor.x, y: floor.y, duration: LEG_MS, ease: 'Quad.easeIn' });
        ctx.effects.schedule(LEG_MS, () => {
          playFx(ctx.fx, 'landing-dust', floor.x, floor.y + 8, 0.5);
          fxRing(ctx.fx, floor.x, floor.y, 4, 26, 0xe9f6ff, 260, 2, 0.4);
          star(ctx, floor.x, floor.y, 0xffffff, 1, 200);
          scene.tweens.killTweensOf(s);
          scene.tweens.add({ targets: s, x: top.x, y: top.y, duration: LEG_MS, ease: 'Quad.easeOut' });
        });
        ctx.effects.schedule(2 * LEG_MS, () => {
          fxRing(ctx.fx, top.x, top.y, 4, 22, 0xa9dcff, 240, 2);
          star(ctx, top.x, top.y, 0xffffff, 1.2, 200);
          scene.tweens.killTweensOf(s);
          scene.tweens.add({ targets: s, x: target.x, y: target.y, duration: LEG_MS, ease: 'Quad.easeIn' });
        });
        ctx.bounced = true;
        return;
      }
      clang(ctx, target.x, target.y, true);
      fxRing(ctx.fx, target.x, target.y, 8, 60, 0xd7263d, 360, 3);
      if (ctx.bounced) fxEnergy(ctx.fx, [target, floor, top, target], 500);
      if (ctx.thrown) bringBack(ctx, ctx.thrown, ctx.pt(HERO_POINTS.capCatch), 300);
    },
  },

  /** Batman's explosive batarang: it bursts on the boss, a big blast follows, then smoke. */
  explosive: {
    contacts: [{ id: 'hit', atMs: EXPLOSIVE_RELEASE_MS + EXPLOSIVE_FLIGHT_MS, main: true }],
    uses: ['bat-batarang', 'bat-burst', 'bat-explosion', 'bat-cloud'],
    run(ctx) {
      ctx.effects.schedule(EXPLOSIVE_RELEASE_MS, () => {
        ctx.thrown = batarang(ctx, ctx.pt(HERO_POINTS.batBlastHand), ctx.target, EXPLOSIVE_FLIGHT_MS, 10);
      });
    },
    land(ctx) {
      const { x, y } = ctx.target;
      const k = ctx.propScale;
      if (ctx.thrown) {
        ctx.thrown.stop();
        ctx.thrown.sprite.destroy();
      }
      fxPop(ctx.fx, 'bat-burst', x, y, 220, k * 1.2);
      fxFlash(ctx.fx, x, y, 26, ORANGE);
      fxRing(ctx.fx, x, y, 8, 60, GOLD, 360, 3);
      fxLines(ctx.fx, x, y, GOLD, 14, 22);
      ctx.effects.schedule(110, () => fxPop(ctx.fx, 'bat-explosion', x, y - 6, 460, k * 1.6));
      if (ctx.reduced) return;
      ctx.effects.schedule(380, () => fxPop(ctx.fx, 'bat-cloud', x, y - 12, 640, k * 1.5));
    },
  },

  /** Batman's ultimate: bats stream from him into a dark sphere on the boss, and it blows. */
  'bat-swarm': {
    contacts: [...BITES, { id: 'hit', atMs: SWARM_BLAST_MS, main: true }],
    uses: ['bat-bat', 'bat-sphere', 'bat-explosion', 'bat-smoke', 'void-implosion'],
    run(ctx, contacts) {
      if (ctx.reduced || !contacts.some(c => !c.main)) return;
      for (let i = 0; i < SWARM_BATS; i++) {
        ctx.effects.schedule(SWARM_START_MS + i * SWARM_GAP_MS, () => bat(ctx, i));
      }
      ctx.effects.schedule(SWARM_SPHERE_MS, () => fxPop(ctx.fx, 'bat-sphere', ctx.target.x, ctx.target.y, 650, ctx.propScale));
    },
    land(ctx, contact) {
      const { x, y } = ctx.target;
      if (!contact.main) {
        fxFlash(ctx.fx, x + Phaser.Math.Between(-12, 12), y + Phaser.Math.Between(-12, 12), 6, 0xb8b0e0);
        return;
      }
      fxPop(ctx.fx, 'bat-explosion', x, y, 500, ctx.propScale * 1.8);
      playFx(ctx.fx, 'void-implosion', x, y, 1.6);
      fxFlash(ctx.fx, x, y, 30, 0xb8b0e0);
      fxLines(ctx.fx, x, y, 0xffffff, 14, 24);
      if (ctx.reduced) return;
      ctx.effects.schedule(300, () => fxPop(ctx.fx, 'bat-smoke', x, y - 6, 600, ctx.propScale * 1.3));
    },
  },
};

/**
 * One micro-missile: a tiny body with a smoke trail on a high arc.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} end
 * @return {void}
 */
function missile(ctx, from, end) {
  const { scene, effects } = ctx;
  fxFlash(ctx.fx, from.x, from.y, 4, ORANGE);
  const m = effects.track(scene.add.container(from.x, from.y).setDepth(29));
  m.add([scene.add.rectangle(0, 0, 6, 2, 0xe6e6f0), scene.add.rectangle(-4, 0, 2, 2, 0xff7a1a)]);
  const ctrl = { x: from.x + (end.x - from.x) * 0.3 + Phaser.Math.Between(-40, 40), y: Math.min(from.y, end.y) - Phaser.Math.Between(90, 160) };
  const curve = new Phaser.Curves.QuadraticBezier(
    new Phaser.Math.Vector2(from.x, from.y), new Phaser.Math.Vector2(ctrl.x, ctrl.y), new Phaser.Math.Vector2(end.x, end.y),
  );
  const smoke = effects.every(30, () => {
    const pf = effects.track(scene.add.circle(m.x, m.y, 2, 0xb8b0c8, 0.7).setDepth(28));
    scene.tweens.add({ targets: pf, radius: 5, alpha: 0, duration: 420, onComplete: () => pf.destroy() });
  });
  const o = { t: 0 };
  scene.tweens.add({ targets: o, t: 1, duration: MISSILE_FLIGHT_MS, ease: 'Sine.easeIn', onUpdate: () => {
    const q = curve.getPoint(o.t);
    const tg = curve.getTangent(o.t);
    m.setPosition(q.x, q.y).setRotation(Math.atan2(tg.y, tg.x));
  }, onComplete: () => {
    smoke();
    m.destroy();
  } });
}

/**
 * One bat of the swarm: from above Batman's head, on a small arc, into the boss.
 *
 * @param {object} ctx
 * @param {number} i
 * @return {void}
 */
function bat(ctx, i) {
  const { scene, effects, target } = ctx;
  const from = ctx.pt({ x: 30 + Phaser.Math.Between(0, 30), y: 10 + Phaser.Math.Between(0, 20) });
  const spread = Phaser.Math.Between(-24, 24);
  const to = { x: target.x + spread, y: target.y + spread / 2 };
  const b = effects.track(scene.add.sprite(from.x, from.y, heroFxKey('bat-bat')).setScale(ctx.propScale * 1.1).setDepth(25));
  b.play({ key: fxAnim(scene, 'bat-bat'), startFrame: i % 3 });
  b.setFlipX(to.x < from.x);
  const o = { t: 0 };
  scene.tweens.add({ targets: o, t: 1, duration: SWARM_FLIGHT_MS, ease: 'Sine.easeIn', onUpdate: () => {
    b.setPosition(Phaser.Math.Linear(from.x, to.x, o.t), Phaser.Math.Linear(from.y, to.y, o.t) - Math.sin(o.t * Math.PI) * 30);
  }, onComplete: () => b.destroy() });
}
