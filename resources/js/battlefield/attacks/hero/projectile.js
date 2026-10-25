import Phaser from 'phaser';
import { HERO_POINTS } from '@battlefield/config/heroes.js';
import { has } from './contacts.js';
import { fxAnim, fxFlare, fxFlash, fxLines, fxPop, fxRing, fxSparks, fxStreaks, fxWhoosh, heroFxKey, playFx } from './juice.js';

// The heroes' normal moves: one thing flies from the hero to the boss and, for
// the shield and the batarang, back (Batman's combo throws three). Ported from
// the preview harness and the demo page; each flight is timed to arrive at its
// contact.

const CYAN = 0x7fe7ff;
const GOLD = 0xffd166;
const SHIELD_HOLD_MS = 70;
const BATARANG_RELEASE_MS = 420;
const BATARANG_FLIGHT_MS = 280;
/** When the combo's three batarangs leave his hand (frames 2, 3.2 and 4.4 at 12 fps, 60 ms apart), and their flight. */
const COMBO_THROWS = [167, 327, 487];
const COMBO_FLIGHT_MS = 260;

/**
 * A small turning white cross: Captain America's glints and clangs.
 *
 * @param {object} ctx The move context (attacks/hero/index.js).
 * @param {number} x
 * @param {number} y
 * @param {number} color
 * @param {number} size
 * @param {number} ms
 * @return {void}
 */
export function star({ scene, effects }, x, y, color, size, ms) {
  const g = effects.track(scene.add.graphics({ x, y }).setDepth(31));
  g.fillStyle(color, 1);
  g.fillRect(-1, -4 * size, 2, 8 * size);
  g.fillRect(-4 * size, -1, 8 * size, 2);
  scene.tweens.add({ targets: g, angle: 45, scale: 0.2, alpha: 0, duration: ms, onComplete: () => g.destroy() });
}

/**
 * The shield's metal clang: white and blue rings, star sparks, light streaks, a frost nova.
 *
 * @param {object} ctx
 * @param {number} x
 * @param {number} y
 * @param {boolean} big The ricochet's second, stronger hit.
 * @return {void}
 */
export function clang(ctx, x, y, big) {
  const { scene, effects } = ctx;
  fxRing(ctx.fx, x, y, 6, 26, 0xffffff, 260, 2);
  for (let i = 0; i < 6; i++) {
    const a = (i / 6) * Math.PI * 2 + Math.random() * 0.4;
    const sp = effects.track(scene.add.rectangle(x, y, 3, 3, i % 2 ? 0xfff2a8 : 0xffffff).setDepth(31));
    scene.tweens.add({ targets: sp, x: x + Math.cos(a) * 30, y: y + Math.sin(a) * 30, alpha: 0, duration: 320, onComplete: () => sp.destroy() });
  }
  playFx(ctx.fx, 'frost-nova', x, y + 8, big ? 1.6 : 1.1);
  fxFlash(ctx.fx, x, y, big ? 18 : 14);
  fxRing(ctx.fx, x, y, 4, big ? 40 : 30, 0x7fc4ff, 240, 2);
  fxStreaks(ctx.fx, x, y, big ? 12 : 8, big ? 30 : 22);
  fxLines(ctx.fx, x, y, 0xffffff, 10, 16);
  star(ctx, x, y, 0xffffff, 2.4, 300);
}

/**
 * Captain America's spinning shield with its red, white and blue ghosts.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from
 * @return {{sprite: Phaser.GameObjects.Sprite, stop: Function}}
 */
export function shield(ctx, from) {
  const { scene, effects } = ctx;
  const s = effects.track(scene.add.sprite(from.x, from.y, heroFxKey('cap-shield-spin')).setScale(ctx.propScale).setDepth(28));
  s.play(fxAnim(scene, 'cap-shield-spin'));
  const tints = [0xd7263d, 0xffffff, 0x2f5fb3];
  let k = 0;
  const stop = effects.every(16, () => {
    if (!s.active) return;
    const g = effects.track(scene.add.sprite(s.x, s.y, s.texture.key, s.frame.name).setScale(ctx.propScale).setDepth(27)
      .setTintFill(tints[k++ % 3]).setAlpha(0.5));
    scene.tweens.add({ targets: g, alpha: 0, scale: ctx.propScale * 0.55, duration: 200, onComplete: () => g.destroy() });
  });
  return { sprite: s, stop };
}

/**
 * Flies a sprite back to a point, then removes it and its trail.
 *
 * @param {object} ctx
 * @param {{sprite: Phaser.GameObjects.Sprite, stop: Function}} thrown
 * @param {{x: number, y: number}} to
 * @param {number} ms
 * @return {void}
 */
export function bringBack({ scene }, thrown, to, ms) {
  scene.tweens.add({ targets: thrown.sprite, x: to.x, y: to.y, duration: ms, ease: 'Sine.easeIn', onComplete: () => {
    thrown.stop();
    thrown.sprite.destroy();
  } });
}

/** @type {Object<string, object>} */
export const PROJECTILE_MOVES = {
  /** Iron Man's repulsor bolt: a palm charge, a cyan bolt, an electric pop. */
  repulsor: {
    contacts: [{ id: 'hit', atMs: 540, main: true }],
    uses: ['focus-charge', 'magical-projectile', 'electric-impact'],
    run(ctx) {
      const palm = i => ctx.pt(HERO_POINTS.ironManPalms[i]);
      ctx.effects.schedule(80, () => fxFlare(ctx.fx, palm(1), 0.6, CYAN));
      ctx.effects.schedule(170, () => {
        fxFlare(ctx.fx, palm(2), 0.9, CYAN);
        playFx(ctx.fx, 'focus-charge', palm(2).x, palm(2).y, 0.3);
      });
      ctx.effects.schedule(300, () => {
        const from = palm(3);
        fxFlash(ctx.fx, from.x, from.y, 6, CYAN);
        const bolt = playFx(ctx.fx, 'magical-projectile', from.x, from.y, 0.5);
        bolt.setRotation(Math.atan2(ctx.target.y - from.y, ctx.target.x - from.x));
        const stop = ctx.effects.every(20, () => {
          const g = ctx.effects.track(ctx.scene.add.circle(bolt.x, bolt.y, 3, CYAN, 0.7).setDepth(29).setBlendMode(Phaser.BlendModes.ADD));
          ctx.scene.tweens.add({ targets: g, radius: 1, alpha: 0, duration: 200, onComplete: () => g.destroy() });
        });
        ctx.scene.tweens.add({ targets: bolt, x: ctx.target.x, y: ctx.target.y, duration: 240, ease: 'Quad.easeIn', onComplete: () => {
          stop();
          bolt.destroy();
        } });
      });
    },
    land(ctx) {
      const { x, y } = ctx.target;
      fxFlash(ctx.fx, x, y, 14, CYAN);
      fxRing(ctx.fx, x, y, 6, 34, CYAN, 260, 3);
      fxSparks(ctx.fx, x, y, [0xffffff, CYAN, 0x36c7f2]);
      playFx(ctx.fx, 'electric-impact', x, y, 0.7);
    },
  },

  /** Captain America throws his shield: it clangs off the boss and comes back to his arm. */
  'shield-throw': {
    contacts: [{ id: 'hit', atMs: 620, main: true }],
    uses: ['cap-shield-spin', 'frost-nova'],
    run(ctx) {
      ctx.effects.schedule(190, () => {
        const rim = ctx.pt(HERO_POINTS.capRim);
        star(ctx, rim.x, rim.y, 0xffffff, 1.4, 260);
      });
      ctx.effects.schedule(320, () => {
        const from = ctx.pt(HERO_POINTS.capRelease);
        fxFlash(ctx.fx, from.x, from.y, 8);
        fxWhoosh(ctx.fx, from.x, from.y, ctx.dir);
        star(ctx, from.x, from.y, 0xffffff, 1.6, 220);
        ctx.thrown = shield(ctx, from);
        ctx.scene.tweens.add({ targets: ctx.thrown.sprite, x: ctx.target.x, y: ctx.target.y, duration: 300 });
      });
    },
    land(ctx) {
      clang(ctx, ctx.target.x, ctx.target.y, false);
      if (ctx.thrown) {
        const back = ctx.pt(HERO_POINTS.capCatch);
        // a beat of hit-stop: the shield hangs on the boss before it flies home
        ctx.effects.schedule(SHIELD_HOLD_MS, () => bringBack(ctx, ctx.thrown, back, 300 - SHIELD_HOLD_MS));
        ctx.effects.schedule(300, () => star(ctx, back.x, back.y, 0xfff2a8, 1.2, 220));
      }
    },
  },

  /** Batman's batarang: a low throw with a warm trail, a spark on the boss, and back to his hand. */
  batarang: {
    contacts: [{ id: 'hit', atMs: BATARANG_RELEASE_MS + BATARANG_FLIGHT_MS, main: true }],
    uses: ['bat-batarang', 'bat-spark'],
    run(ctx) {
      ctx.effects.schedule(BATARANG_RELEASE_MS, () => {
        ctx.thrown = batarang(ctx, ctx.pt(HERO_POINTS.batHand), ctx.target, BATARANG_FLIGHT_MS, 24);
      });
    },
    land(ctx) {
      const { scene } = ctx;
      const { x, y } = ctx.target;
      batHit(ctx, x, y, true);
      if (!ctx.thrown) return;
      // boomerang: it curves back into his hand
      const s = ctx.thrown.sprite;
      const back = ctx.pt(HERO_POINTS.batHand);
      const ret = new Phaser.Curves.QuadraticBezier(
        new Phaser.Math.Vector2(s.x, s.y),
        new Phaser.Math.Vector2((s.x + back.x) / 2, Math.max(s.y, back.y) + 30),
        new Phaser.Math.Vector2(back.x, back.y),
      );
      const r = { t: 0 };
      scene.tweens.add({ targets: r, t: 1, duration: 360, ease: 'Sine.easeIn', onUpdate: () => {
        const q = ret.getPoint(r.t);
        s.setPosition(q.x, q.y);
      }, onComplete: () => {
        ctx.thrown.stop();
        s.destroy();
        fxFlare(ctx.fx, back, 0.8, GOLD);
      } });
    },
  },

  /** Batman's combo: three batarangs thrown one after another, each landing a little higher or lower. */
  combo: {
    contacts: COMBO_THROWS.map((atMs, k) => ({ id: `throw-${k}`, atMs: atMs + COMBO_FLIGHT_MS, main: k === COMBO_THROWS.length - 1 })),
    uses: ['bat-batarang', 'bat-spark'],
    run(ctx, contacts) {
      COMBO_THROWS.forEach((atMs, k) => {
        if (ctx.reduced || !has(contacts, `throw-${k}`)) return;
        ctx.effects.schedule(atMs, () => {
          const thrown = batarang(ctx, ctx.pt(HERO_POINTS.batHand), comboAim(ctx, k), COMBO_FLIGHT_MS, 14 + k * 6);
          ctx.effects.schedule(COMBO_FLIGHT_MS, () => {
            thrown.stop();
            thrown.sprite.destroy();
          });
        });
      });
    },
    land(ctx, contact) {
      const k = Number(contact.id.split('-')[1]);
      const { x, y } = comboAim(ctx, k);
      batHit(ctx, x, y, contact.main);
    },
  },

  /** Superman's heat vision: twin red beams sweep across the boss, embers fly, a warm blast to finish. */
  'heat-vision': {
    contacts: [
      { id: 'burn-1', atMs: 500 },
      { id: 'burn-2', atMs: 731 },
      { id: 'burn-3', atMs: 962 },
      { id: 'hit', atMs: 1200, main: true },
    ],
    uses: ['cinder-orchid', 'warm-explosion'],
    run(ctx) {
      const eye = i => ctx.pt(HERO_POINTS.supermanEyes[i]);
      ctx.effects.schedule(80, () => fxFlare(ctx.fx, eye(1), 0.6, 0xff3b30));
      ctx.effects.schedule(240, () => fxFlare(ctx.fx, eye(2), 1, 0xff3b30));
      if (ctx.reduced) return;
      ctx.effects.schedule(500, () => beam(ctx, eye(3), 700));
    },
    land(ctx, contact) {
      if (!contact.main) return;
      playFx(ctx.fx, 'warm-explosion', ctx.target.x, ctx.target.y + 8, 1.3);
    },
  },
};

/**
 * The heat-vision beams, redrawn every frame for `ms`, sweeping across the target.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from The eyes.
 * @param {number} ms
 * @return {void}
 */
function beam(ctx, from, ms) {
  const { scene, effects, target } = ctx;
  const g = effects.track(scene.add.graphics().setDepth(29).setBlendMode(Phaser.BlendModes.ADD));
  const scorch = effects.track(scene.add.circle(target.x, target.y, 9, 0xff7a1a, 0.7).setDepth(28).setBlendMode(Phaser.BlendModes.ADD));
  let n = 0;
  effects.sweep(ms, k => {
    n++;
    const to = { x: target.x - 16 + 32 * k, y: target.y - 4 + Math.sin(k * 9) * 5 };
    const pulse = n % 4 < 2 ? 1 : 0;
    g.clear();
    for (const dy of [-1.5, 1.5]) {
      g.lineStyle(9 + pulse * 2, 0xb3121f, 0.35).lineBetween(from.x, from.y + dy, to.x, to.y + dy * 2);
      g.lineStyle(4 + pulse, 0xff3b30, 1).lineBetween(from.x, from.y + dy, to.x, to.y + dy * 2);
      g.lineStyle(1.5, 0xfff1e6, 1).lineBetween(from.x, from.y + dy, to.x, to.y + dy * 2);
    }
    scorch.setPosition(to.x, to.y).setRadius(8 + pulse * 3);
    if (n % 2 === 0) ember(ctx, to.x, to.y);
    if (n % 6 === 1) playFx(ctx.fx, 'cinder-orchid', to.x, to.y + 10, 0.4);
  });
  effects.schedule(ms, () => {
    g.destroy();
    scene.tweens.add({ targets: scorch, alpha: 0, scale: 2, duration: 300, onComplete: () => scorch.destroy() });
  });
}

/**
 * One rising ember where the beam burns.
 *
 * @param {object} ctx
 * @param {number} x
 * @param {number} y
 * @return {void}
 */
function ember({ scene, effects }, x, y) {
  const e = effects.track(scene.add.rectangle(x + Phaser.Math.Between(-4, 4), y, 2, 2, Phaser.Utils.Array.GetRandom([GOLD, 0xff7a1a, 0xff3b30])).setDepth(30));
  scene.tweens.add({ targets: e, x: e.x + Phaser.Math.Between(-10, 10), y: y - Phaser.Math.Between(16, 34), alpha: 0, duration: 520, onComplete: () => e.destroy() });
}

/**
 * A batarang spinning along a low arc from his hand to a point, with a warm
 * ghost trail (it is black on a night map).
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from
 * @param {{x: number, y: number}} to
 * @param {number} ms
 * @param {number} rise How high the arc goes above the straight line.
 * @return {{sprite: Phaser.GameObjects.Sprite, stop: Function}}
 */
export function batarang(ctx, from, to, ms, rise) {
  const { scene, effects } = ctx;
  const s = effects.track(scene.add.sprite(from.x, from.y, heroFxKey('bat-batarang')).setScale(ctx.propScale).setDepth(28));
  s.play(fxAnim(scene, 'bat-batarang'));
  const curve = new Phaser.Curves.QuadraticBezier(
    new Phaser.Math.Vector2(from.x, from.y),
    new Phaser.Math.Vector2((from.x + to.x) / 2, Math.min(from.y, to.y) - rise),
    new Phaser.Math.Vector2(to.x, to.y),
  );
  const stop = effects.every(30, () => {
    if (!s.active) return;
    const g = effects.track(scene.add.sprite(s.x, s.y, s.texture.key, s.frame.name).setScale(ctx.propScale).setDepth(27)
      .setTintFill(GOLD).setAlpha(0.55));
    scene.tweens.add({ targets: g, alpha: 0, scale: ctx.propScale * 0.6, duration: 220, onComplete: () => g.destroy() });
  });
  const o = { t: 0 };
  scene.tweens.add({ targets: o, t: 1, duration: ms, onUpdate: () => {
    const q = curve.getPoint(o.t);
    s.setPosition(q.x, q.y);
  } });
  return { sprite: s, stop };
}

/**
 * Where the combo's K-th batarang lands: around the target, the middle one on it.
 *
 * @param {object} ctx
 * @param {number} k
 * @return {{x: number, y: number}}
 */
function comboAim(ctx, k) {
  return { x: ctx.target.x, y: ctx.target.y + (k - 1) * 12 };
}

/**
 * A batarang's hit: the sheet's spark, a gold flash and, on the main hit, impact lines.
 *
 * @param {object} ctx
 * @param {number} x
 * @param {number} y
 * @param {boolean} strong
 * @return {void}
 */
function batHit(ctx, x, y, strong) {
  fxPop(ctx.fx, 'bat-spark', x, y, 220, ctx.propScale * 1.2);
  fxFlash(ctx.fx, x, y, strong ? 12 : 8, GOLD);
  fxSparks(ctx.fx, x, y, [GOLD, 0xffffff], strong ? 8 : 5);
  if (strong) fxLines(ctx.fx, x, y, GOLD, 10, 16);
}
