// One small canvas animation per Infinity Stone, played over ThaNode when a
// stone the viewer hasn't seen yet arrives (see stone-seen.js). Each lasts
// about a second, draws only its own short-lived objects above the boss and
// removes every one of them when it ends. Nothing here imports Phaser: the
// scene's own factories are used, so a stub scene can drive it in tests.
import { TextureKey } from '@battlefield/constants.js';
import { STONE_COLORS, STONE_NAMES } from './thanos-stones.js';

const DEPTH = 6; // just above the boss sprite (5)

/**
 * Runs one tween and resolves when it completes.
 *
 * @param {Phaser.Scene} scene
 * @param {object} config Phaser tween config (its onComplete is taken over).
 * @return {Promise<void>}
 */
function tween(scene, config) {
  return new Promise((resolve) => scene.tweens.add({ ...config, onComplete: () => resolve() }));
}

/**
 * Runs a 0→1 counter tween, calling `draw(t)` on every step, and resolves when it completes.
 *
 * @param {Phaser.Scene} scene
 * @param {number} duration ms
 * @param {function(number): void} draw
 * @param {string} [ease]
 * @return {Promise<void>}
 */
function sweep(scene, duration, draw, ease = 'Sine.easeInOut') {
  return new Promise((resolve) => scene.tweens.addCounter({
    from: 0,
    to: 1,
    duration,
    ease,
    onUpdate: (t) => draw(t.getValue()),
    onComplete: () => resolve(),
  }));
}

/**
 * Power: two purple shockwave rings and a short camera jolt.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep Registers a created object for cleanup.
 * @return {Promise<void>}
 */
async function power(scene, at, color, keep) {
  const rings = [0, 160].map((delay) => ({
    delay,
    ring: keep(scene.add.circle(at.x, at.y, 14, color, 0).setStrokeStyle(5, color, 1).setDepth(DEPTH).setScale(0.2)),
  }));
  scene.cameras.main.shake(200, 0.006);
  await Promise.all(rings.map(({ ring, delay }) => tween(scene, {
    targets: ring, scale: 7, alpha: 0, delay, duration: 750, ease: 'Cubic.easeOut',
  })));
}

/**
 * Space: a blue tesseract outline spins open, then folds into a point.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @return {Promise<void>}
 */
async function space(scene, at, color, keep) {
  const outer = keep(scene.add.rectangle(at.x, at.y, 30, 30, color, 0.15).setStrokeStyle(2, color, 1).setDepth(DEPTH).setScale(0));
  const inner = keep(scene.add.rectangle(at.x, at.y, 16, 16, color, 0).setStrokeStyle(2, 0xbfdbfe, 1).setDepth(DEPTH).setScale(0));
  await Promise.all([
    tween(scene, { targets: outer, scale: 3, angle: 135, duration: 450, ease: 'Back.easeOut' }),
    tween(scene, { targets: inner, scale: 3, angle: -135, duration: 450, ease: 'Back.easeOut' }),
  ]);
  await Promise.all([
    tween(scene, { targets: outer, scale: 0, angle: 270, duration: 380, ease: 'Cubic.easeIn' }),
    tween(scene, { targets: inner, scale: 0, angle: -270, duration: 380, ease: 'Cubic.easeIn' }),
  ]);
  const flash = keep(scene.add.circle(at.x, at.y, 10, 0xffffff, 0.9).setDepth(DEPTH));
  await tween(scene, { targets: flash, scale: 4, alpha: 0, duration: 260 });
}

/**
 * Reality: red shards scatter around the boss, jitter, then snap back together.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @return {Promise<void>}
 */
async function reality(scene, at, color, keep) {
  const shards = Array.from({ length: 14 }, (_, i) => {
    const angle = (i / 14) * Math.PI * 2;
    const radius = 55 + Math.random() * 40;
    return {
      shard: keep(scene.add.rectangle(at.x, at.y, 6, 6, i % 3 === 0 ? 0xfca5a5 : color, 1).setDepth(DEPTH)),
      x: at.x + Math.cos(angle) * radius,
      y: at.y + Math.sin(angle) * radius,
    };
  });
  await Promise.all(shards.map(({ shard, x, y }) => tween(scene, {
    targets: shard, x, y, angle: 180, duration: 380, ease: 'Cubic.easeOut',
  })));
  await Promise.all(shards.map(({ shard, x, y }) => tween(scene, {
    targets: shard, x: x + (Math.random() - 0.5) * 12, y: y + (Math.random() - 0.5) * 12, duration: 70, yoyo: true, repeat: 2,
  })));
  await Promise.all(shards.map(({ shard }) => tween(scene, {
    targets: shard, x: at.x, y: at.y, scale: 0, duration: 320, ease: 'Cubic.easeIn',
  })));
}

/**
 * Soul: an orange wisp spirals up around the boss, leaving fading embers.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @return {Promise<void>}
 */
async function soul(scene, at, color, keep) {
  const wisp = keep(scene.add.circle(at.x, at.y + 30, 6, color, 1).setDepth(DEPTH));
  const glow = keep(scene.add.circle(at.x, at.y + 30, 12, color, 0.3).setDepth(DEPTH));
  let lastEmber = 0;
  await sweep(scene, 1000, (t) => {
    const radius = 60 * (1 - t * 0.6);
    const x = at.x + Math.cos(t * Math.PI * 4) * radius;
    const y = at.y + 30 - t * 110;
    wisp.x = glow.x = x;
    wisp.y = glow.y = y;
    if (t - lastEmber > 0.08) {
      lastEmber = t;
      const ember = keep(scene.add.circle(x, y, 3, color, 0.8).setDepth(DEPTH));
      scene.tweens.add({ targets: ember, alpha: 0, scale: 0.2, duration: 400 });
    }
  }, 'Linear');
  await tween(scene, { targets: [wisp, glow], alpha: 0, scale: 2, duration: 200 });
}

/**
 * Time: a green clock face whose hand sweeps a full turn, filling the dial behind it.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @return {Promise<void>}
 */
async function time(scene, at, color, keep) {
  const radius = 44;
  const dial = keep(scene.add.graphics().setDepth(DEPTH));
  const top = -Math.PI / 2;
  await sweep(scene, 900, (t) => {
    const hand = top + t * Math.PI * 2;
    dial.clear();
    dial.lineStyle(2, color, 1);
    dial.strokeCircle(at.x, at.y, radius);
    dial.fillStyle(color, 0.25);
    dial.beginPath();
    dial.moveTo(at.x, at.y);
    dial.arc(at.x, at.y, radius, top, hand, false);
    dial.closePath();
    dial.fillPath();
    dial.lineStyle(3, 0xbbf7d0, 1);
    dial.lineBetween(at.x, at.y, at.x + Math.cos(hand) * radius, at.y + Math.sin(hand) * radius);
  });
  await tween(scene, { targets: dial, alpha: 0, duration: 250 });
}

/**
 * Mind: eight yellow rays burst outward from the boss and fade.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @return {Promise<void>}
 */
async function mind(scene, at, color, keep) {
  const rays = keep(scene.add.graphics().setDepth(DEPTH));
  const core = keep(scene.add.circle(at.x, at.y, 8, 0xfef9c3, 1).setDepth(DEPTH).setScale(0));
  await sweep(scene, 800, (t) => {
    rays.clear();
    rays.lineStyle(3, color, 1 - t);
    for (let i = 0; i < 12; i++) {
      const angle = (i / 12) * Math.PI * 2 + t * 0.6;
      const inner = 14 + t * 55;
      const outer = 24 + t * 95;
      rays.lineBetween(at.x + Math.cos(angle) * inner, at.y + Math.sin(angle) * inner, at.x + Math.cos(angle) * outer, at.y + Math.sin(angle) * outer);
    }
    core.setScale(Math.sin(t * Math.PI) * 1.5);
  }, 'Cubic.easeOut');
}

// Keyed by STONE_NAMES entry, in socket order.
export const STONE_EFFECTS = { Power: power, Space: space, Reality: reality, Soul: soul, Time: time, Mind: mind };

/**
 * Resolves when `signal` aborts; never, when there is no signal.
 *
 * @param {AbortSignal|undefined} signal
 * @return {Promise<void>}
 */
function whenAborted(signal) {
  return new Promise((resolve) => {
    if (signal?.aborted) {
      resolve();
    } else {
      signal?.addEventListener('abort', () => resolve(), { once: true });
    }
  });
}

/**
 * Plays the Nth stone's animation at `at` with its name floating above it,
 * resolving once it ends and everything it drew is gone. Objects still alive
 * are registered in `live` (when given) so a teardown mid-animation can
 * remove them; an unknown ordinal plays nothing. Aborting `signal` settles it
 * at once: a killed tween never fires onComplete, so without it a boss kill or
 * a rotate mid-animation would leave the caller awaiting forever.
 *
 * @param {Phaser.Scene} scene
 * @param {number} ordinal 1-based stone number.
 * @param {{x: number, y: number}} at
 * @param {Set<object>} [live]
 * @param {AbortSignal} [signal]
 * @return {Promise<void>}
 */
export async function playStoneEffect(scene, ordinal, at, live = new Set(), signal = undefined) {
  const name = STONE_NAMES[ordinal - 1];
  const effect = STONE_EFFECTS[name];
  if (!effect) {
    return;
  }
  const color = STONE_COLORS[ordinal - 1];
  await runTracked(scene, live, signal, async (keep) => {
    const caption = keep(scene.addSharpText(at.x, at.y - 80, `${name.toUpperCase()} STONE`, captionStyle(color, 18))
      .setDepth(DEPTH).setAlpha(0).setScale(0.6));
    scene.tweens.add({ targets: caption, alpha: 1, scale: 1, y: at.y - 92, duration: 280, ease: 'Back.easeOut' });
    bloom(scene, at, color, keep, 4);
    // Additive: every effect shape brightens what's under it, so it glows on the night sky too.
    await effect(scene, at, color, (obj) => keep(obj).setBlendMode('ADD'));
    await tween(scene, { targets: caption, alpha: 0, y: at.y - 108, duration: 260, ease: 'Quad.easeIn' });
  });
}

/**
 * The finale when the sixth stone lands: all six colours orbit the boss and
 * collapse into it, then a gold burst, a shockwave and a heavier shake under
 * "GAUNTLET COMPLETE".
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {Set<object>} [live]
 * @param {AbortSignal} [signal]
 * @return {Promise<void>}
 */
export async function playGauntletComplete(scene, at, live = new Set(), signal = undefined) {
  const gold = 0xfbbf24;
  await runTracked(scene, live, signal, async (keep) => {
    const orbs = STONE_COLORS.map((color) => keep(scene.add.circle(at.x, at.y, 7, color, 1).setDepth(DEPTH).setBlendMode('ADD')));
    await sweep(scene, 1100, (t) => {
      const radius = 90 * (1 - t);
      orbs.forEach((orb, i) => {
        const angle = (i / orbs.length) * Math.PI * 2 + t * Math.PI * 3;
        orb.x = at.x + Math.cos(angle) * radius;
        orb.y = at.y + Math.sin(angle) * radius * 0.6;
      });
    }, 'Cubic.easeIn');
    const caption = keep(scene.addSharpText(at.x, at.y - 96, 'GAUNTLET COMPLETE', captionStyle(gold, 24))
      .setDepth(DEPTH).setAlpha(0).setScale(0.5));
    scene.cameras.main.shake(380, 0.01);
    bloom(scene, at, gold, keep, 7);
    const wave = keep(scene.add.circle(at.x, at.y, 16, gold, 0).setStrokeStyle(6, gold, 1).setDepth(DEPTH).setBlendMode('ADD'));
    await Promise.all([
      tween(scene, { targets: orbs, scale: 0, alpha: 0, duration: 200 }),
      tween(scene, { targets: wave, scale: 9, alpha: 0, duration: 900, ease: 'Cubic.easeOut' }),
      tween(scene, { targets: caption, alpha: 1, scale: 1, duration: 320, ease: 'Back.easeOut' }),
    ]);
    await tween(scene, { targets: caption, alpha: 0, delay: 900, duration: 400 });
  });
}

/**
 * Text style for a floating stone caption.
 *
 * @param {number} color
 * @param {number} px
 * @return {object}
 */
function captionStyle(color, px) {
  return {
    fontFamily: 'monospace',
    fontSize: `${px}px`,
    fontStyle: 'bold',
    color: `#${color.toString(16).padStart(6, '0')}`,
    stroke: '#0f172a',
    strokeThickness: 5,
  };
}

/**
 * A soft additive glow that swells and fades behind an effect; not awaited.
 *
 * @param {Phaser.Scene} scene
 * @param {{x: number, y: number}} at
 * @param {number} color
 * @param {function(object): object} keep
 * @param {number} size Peak scale of the 64px glow texture.
 * @return {void}
 */
function bloom(scene, at, color, keep, size) {
  const glow = keep(scene.add.image(at.x, at.y, TextureKey.SOFTGLOW)
    .setTint(color).setBlendMode('ADD').setDepth(DEPTH - 0.5).setScale(0).setAlpha(0.95));
  scene.tweens.add({ targets: glow, scale: size, alpha: 0, duration: 1000, ease: 'Cubic.easeOut' });
}

/**
 * Runs an animation body that registers everything it draws through `keep`,
 * settles early when `signal` aborts, and always destroys what it drew.
 *
 * @param {Phaser.Scene} scene
 * @param {Set<object>} live
 * @param {AbortSignal|undefined} signal
 * @param {function(function(object): object): Promise<void>} body
 * @return {Promise<void>}
 */
async function runTracked(scene, live, signal, body) {
  const made = [];
  const keep = (obj) => {
    made.push(obj);
    live.add(obj);
    return obj;
  };
  try {
    await Promise.race([body(keep), whenAborted(signal)]);
  } finally {
    for (const obj of made) {
      scene.tweens.killTweensOf(obj);
      obj.destroy();
      live.delete(obj);
    }
  }
}
