import Phaser from 'phaser';
import { HERO_POINTS } from '@battlefield/config/heroes.js';
import { fxAnim, fxFlare, fxFlash, fxLines, fxRing, heroFxKey, playFx } from './juice.js';

// Big moves with their own choreography, ported from the preview harness.

const CYAN = 0x7fe7ff;
const BLUE = 0x36c7f2;
const GOLD = 0xffd166;
const BEAM_START_MS = 490;
const BEAM_MS = 620;
const PUNCH_DASH_START_MS = 230;
const PUNCH_DASH_MS = 240;
/** The middle of Batman's body in his working cell, where his gold aura sits. */
const BATMAN_BODY = { x: 43, y: 50 };

/** @type {Object<string, object>} */
export const BESPOKE_MOVES = {
  /** Iron Man's maxed repulsor: a long pulsing beam that ticks the boss, then a blast. */
  'repulsor-beam': {
    contacts: [
      { id: 'tick-1', atMs: BEAM_START_MS },
      { id: 'tick-2', atMs: BEAM_START_MS + 205 },
      { id: 'tick-3', atMs: BEAM_START_MS + 409 },
      { id: 'hit', atMs: BEAM_START_MS + BEAM_MS, main: true },
    ],
    uses: ['focus-charge', 'lattice-beam', 'beam-cutoff-burst', 'electric-impact'],
    run(ctx) {
      const palm = i => ctx.pt(HERO_POINTS.ironManPalms[i]);
      ctx.effects.schedule(80, () => fxFlare(ctx.fx, palm(1), 0.6, CYAN));
      ctx.effects.schedule(170, () => {
        fxFlare(ctx.fx, palm(2), 1.3, CYAN);
        playFx(ctx.fx, 'focus-charge', palm(2).x, palm(2).y, 0.55);
      });
      if (ctx.reduced) return;
      ctx.effects.schedule(BEAM_START_MS, () => latticeBeam(ctx, palm(3)));
    },
    land(ctx, contact) {
      const { x, y } = ctx.target;
      if (!contact.main) {
        playFx(ctx.fx, 'electric-impact', x, y, 0.6);
        return;
      }
      fxFlash(ctx.fx, x, y, 26, 0x9fe9ff);
      fxLines(ctx.fx, x, y, 0xbff4ff, 14, 22);
      fxRing(ctx.fx, x, y, 8, 56, 0xffffff, 320, 4);
      ctx.effects.schedule(90, () => fxRing(ctx.fx, x, y, 8, 72, BLUE, 420, 2));
      playFx(ctx.fx, 'electric-impact', x, y, 1.8);
      const from = ctx.pt(HERO_POINTS.ironManPalms[3]);
      playFx(ctx.fx, 'beam-cutoff-burst', from.x, from.y, 0.4, { rotation: Math.atan2(y - from.y, x - from.x) });
    },
  },

  /** Đô La Thần Chưởng: Batman is rich. A golden palm drops from the sky onto the boss and coins burst out. */
  'dollar-palm': {
    contacts: [{ id: 'hit', atMs: 1160, main: true }],
    uses: ['dollar-palm', 'coin-spin', 'solar-shrapnel'],
    run(ctx) {
      if (ctx.reduced) return;
      const { scene, effects, target } = ctx;
      const body = ctx.pt(BATMAN_BODY);
      const aura = effects.track(scene.add.ellipse(body.x, body.y, 50, 58, GOLD, 0).setDepth(4).setBlendMode(Phaser.BlendModes.ADD));
      scene.tweens.add({ targets: aura, fillAlpha: 0.35, duration: 150, yoyo: true, hold: 900, onComplete: () => aura.destroy() });
      for (let i = 0; i < 10; i++) {
        effects.schedule(i * 50, () => coin(ctx, body.x + Phaser.Math.Between(-8, 8), body.y - 18, -Phaser.Math.Between(40, 80)));
      }
      const palm = effects.track(scene.add.container(target.x, target.y - 230).setDepth(35).setScale(0.5).setAlpha(0));
      const halo = [[46, 0.14], [36, 0.2], [27, 0.28]].map(([r, a]) => scene.add.circle(0, 0, r, GOLD, a).setBlendMode(Phaser.BlendModes.ADD));
      const ghost = scene.add.image(0, 0, heroFxKey('dollar-palm'), 0).setScale(3.7).setTintFill(0xfff2a8).setAlpha(0.35).setBlendMode(Phaser.BlendModes.ADD);
      const hand = scene.add.image(0, 0, heroFxKey('dollar-palm'), 0).setScale(3.4);
      palm.add([...halo, ghost, hand]);
      scene.tweens.add({ targets: ghost, scale: 4.1, alpha: 0.15, duration: 300, yoyo: true, repeat: -1 });
      // the ghost's pulse repeats forever and a destroyed target does not stop its tween
      palm.once('destroy', () => scene.tweens.killTweensOf(ghost));
      scene.tweens.add({ targets: palm, alpha: 1, scale: 1, y: Math.max(70, target.y - 130), duration: 450, delay: 300, ease: 'Back.easeOut' });
      effects.schedule(900, () => scene.tweens.add({ targets: palm, y: target.y - 16, duration: 260, ease: 'Quad.easeIn' }));
      ctx.palm = palm;
    },
    land(ctx) {
      const { scene, effects } = ctx;
      const { x, y } = ctx.target;
      fxFlash(ctx.fx, x, y, 34, GOLD);
      fxLines(ctx.fx, x, y, GOLD, 16, 30);
      fxRing(ctx.fx, x, y, 10, 70, GOLD, 380, 4);
      effects.schedule(80, () => fxRing(ctx.fx, x, y + 34, 8, 80, 0x85bb65, 420, 3, 0.3));
      playFx(ctx.fx, 'solar-shrapnel', x, y + 4, 1.7);
      if (ctx.reduced) return;
      for (let i = 0; i < 18; i++) coin(ctx, x, y, -Phaser.Math.Between(60, 140), Phaser.Math.Between(-90, 90));
      for (let i = 0; i < 10; i++) {
        effects.schedule(i * 40, () => coin(ctx, x + Phaser.Math.Between(-30, 30), y - 60, 20, Phaser.Math.Between(-20, 20)));
      }
      if (ctx.palm?.active) {
        scene.tweens.add({ targets: ctx.palm, y: ctx.palm.y - 6, duration: 90, yoyo: true });
        scene.tweens.add({ targets: ctx.palm, alpha: 0, delay: 420, duration: 300, onComplete: () => ctx.palm.destroy() });
      }
    },
  },

  /** Superman's super punch: a wind-up gleam, a sonic boom, a dash into the boss, a hit-stop, and home. */
  'super-punch': {
    contacts: [{ id: 'hit', atMs: PUNCH_DASH_START_MS + PUNCH_DASH_MS, main: true }],
    uses: ['warm-explosion', 'earth-rupture', 'landing-dust'],
    run(ctx) {
      if (!ctx.reduced) dash(ctx);
    },
    land(ctx) {
      const { effects, fighter } = ctx;
      const { x, y } = ctx.target;
      fxFlash(ctx.fx, x, y, 26);
      fxLines(ctx.fx, x, y, 0xffffff, 14, 22);
      fxRing(ctx.fx, x, y, 8, 54, 0xffffff, 320, 4);
      effects.schedule(90, () => fxRing(ctx.fx, x, y, 8, 70, 0x9fd3ff, 420, 2));
      effects.schedule(60, () => fxRing(ctx.fx, x, y + 30, 6, 60, 0xffffff, 380, 2, 0.3));
      playFx(ctx.fx, 'warm-explosion', x, y + 8, 1.3);
      playFx(ctx.fx, 'earth-rupture', x, ctx.ground, 1.5, { depth: 6 });
      playFx(ctx.fx, 'landing-dust', x, y + 40, 0.7);
      // a beat of hit-stop on the punch
      if (!ctx.reduced && fighter.body?.anims) {
        fighter.body.anims.pause();
        effects.schedule(110, () => fighter.body?.anims?.resume());
      }
    },
  },
};

/**
 * Iron Man's beam: a lattice sprite and an additive core from his palm to the
 * boss, redrawn every frame, with a blue aura on him while it fires.
 *
 * @param {object} ctx
 * @param {{x: number, y: number}} from The palm.
 * @return {void}
 */
function latticeBeam(ctx, from) {
  const { scene, effects, target } = ctx;
  const body = ctx.pt({ x: 40, y: 58 });
  const aura = effects.track(scene.add.ellipse(body.x, body.y, 50, 56, BLUE, 0).setDepth(4).setBlendMode(Phaser.BlendModes.ADD));
  scene.tweens.add({ targets: aura, fillAlpha: 0.3, duration: 120, yoyo: true, hold: BEAM_MS - 150, onComplete: () => aura.destroy() });
  const pivotX = 12;
  const cell = 96;
  const b = effects.track(scene.add.sprite(from.x, from.y, heroFxKey('lattice-beam')).setOrigin(pivotX / cell, 0.5).setDepth(29));
  b.play(fxAnim(scene, 'lattice-beam'));
  const g = effects.track(scene.add.graphics().setDepth(30).setBlendMode(Phaser.BlendModes.ADD));
  const angle = Math.atan2(target.y - from.y, target.x - from.x);
  const dist = Math.hypot(target.x - from.x, target.y - from.y);
  let n = 0;
  const stop = effects.every(16, () => {
    n++;
    b.setRotation(angle).setScale(dist / (cell - pivotX), 0.6 + (n % 4 < 2 ? 0.1 : 0));
    g.clear();
    g.lineStyle(12 + (n % 3), BLUE, 0.25).lineBetween(from.x, from.y, target.x, target.y);
    g.lineStyle(3, 0xffffff, 0.9).lineBetween(from.x, from.y, target.x, target.y);
  });
  effects.schedule(BEAM_MS, () => {
    stop();
    g.destroy();
    b.destroy();
  });
}

/**
 * A spinning coin thrown up with gravity.
 *
 * @param {object} ctx
 * @param {number} x
 * @param {number} y
 * @param {number} vy Initial vertical speed, px/s (negative is up).
 * @param {number} [vx]
 * @return {void}
 */
function coin(ctx, x, y, vy, vx = Phaser.Math.Between(-30, 30)) {
  const { scene, effects } = ctx;
  const c = effects.track(scene.add.sprite(x, y, heroFxKey('coin-spin')).setScale(1.4).setDepth(34));
  c.play({ key: fxAnim(scene, 'coin-spin'), startFrame: Phaser.Math.Between(0, 7) });
  const o = { t: 0 };
  const T = 0.9;
  scene.tweens.add({ targets: o, t: T, duration: T * 1000, onUpdate: () => c.setPosition(x + vx * o.t, y + vy * o.t + 0.5 * 260 * o.t * o.t), onComplete: () => c.destroy() });
  scene.tweens.add({ targets: c, alpha: 0, delay: T * 700, duration: T * 300 });
}

/** A fighter's container depth (fighter/index.js's addFighter). */
const FIGHTER_DEPTH = 2;

/** When the punch is over: the dash, the hit-stop, the recoil and the flight home. */
const PUNCH_END_MS = PUNCH_DASH_START_MS + PUNCH_DASH_MS + 110 + 160 + 480;

/**
 * Superman dashes from his spot into the boss and, after the hit-stop, flies
 * back. It moves the fighter's own container, which a hit or a click-to-move
 * may take over at any moment (they kill its tweens without completing them),
 * so the move leaves `fighter.heroMoveCancel` on the entry: they call it first,
 * and it puts the fighter back on `fighter.pos` at its own depth. The move's
 * own end does the same, on the hero clock, whatever happened to its tweens.
 *
 * @param {object} ctx
 * @return {void}
 */
function dash(ctx) {
  const { scene, effects, fighter, target, dir } = ctx;
  const s = fighter.sprite;
  if (!s?.active) return;
  const stop = { x: target.x - dir * 34, y: target.y + 28 };
  const alive = () => Boolean(fighter.sprite?.active) && fighter.heroMoveCancel === finish;
  function finish() {
    fighter.heroMoveCancel = null;
    if (!s.active) return;
    scene.tweens.killTweensOf(s);
    s.setPosition(fighter.pos.x, fighter.pos.y);
    s.setDepth(FIGHTER_DEPTH);
  }
  fighter.heroMoveCancel?.();
  fighter.heroMoveCancel = finish;
  s.setDepth(34);
  scene.tweens.add({ targets: s, x: s.x - dir * 8, y: s.y - 8, duration: 220, ease: 'Quad.easeOut' });
  effects.schedule(120, () => fxFlare(ctx.fx, ctx.pt(HERO_POINTS.supermanFist), 1.2, 0xffffff));
  effects.schedule(PUNCH_DASH_START_MS, () => {
    if (!alive()) return;
    const angle = Math.atan2(stop.y - s.y, stop.x - s.x);
    const boom = effects.track(scene.add.ellipse(s.x, s.y - 20, 10, 30).setStrokeStyle(3, 0xffffff, 1).setRotation(angle).setDepth(6));
    scene.tweens.add({ targets: boom, scaleX: 3, scaleY: 1.6, alpha: 0, duration: 320, onComplete: () => boom.destroy() });
    const trail = effects.every(25, () => {
      if (!alive()) return;
      const ln = effects.track(scene.add.rectangle(s.x - dir * 10, s.y - 20 + Phaser.Math.Between(-10, 10), 16, 1, 0xffffff, 0.8).setRotation(angle).setDepth(4));
      scene.tweens.add({ targets: ln, alpha: 0, scaleX: 0.3, duration: 180, onComplete: () => ln.destroy() });
    });
    effects.schedule(PUNCH_DASH_MS, trail);
    scene.tweens.add({ targets: s, x: stop.x, y: stop.y, duration: PUNCH_DASH_MS, ease: 'Quad.easeIn' });
  });
  // after the hit-stop: a small recoil, then home
  effects.schedule(PUNCH_DASH_START_MS + PUNCH_DASH_MS + 110, () => {
    if (!alive()) return;
    scene.tweens.add({ targets: s, x: s.x - dir * 10, y: s.y - 6, duration: 160, ease: 'Quad.easeOut' });
  });
  effects.schedule(PUNCH_DASH_START_MS + PUNCH_DASH_MS + 270, () => {
    if (!alive()) return;
    scene.tweens.add({ targets: s, x: fighter.pos.x, y: fighter.pos.y, duration: 480, ease: 'Sine.easeInOut' });
  });
  effects.schedule(PUNCH_END_MS, () => {
    if (fighter.heroMoveCancel === finish) finish();
  });
}
