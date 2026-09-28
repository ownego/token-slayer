import { expect, test, vi } from 'vitest';
vi.mock('phaser', () => ({
  default: {
    Math: { Between: (min) => min, Distance: { Between: () => 999 } },
  },
}));
import { Minions, POOF_SEQUENCE } from '@battlefield/minions.js';

test('the poof uses Claude Code\'s crouch-with-dust then arms-up frames', () => {
  expect(POOF_SEQUENCE[0]).toEqual(['crouch', '·']);
  expect(POOF_SEQUENCE[1]).toEqual(['crouch', '~']);
  expect(POOF_SEQUENCE.filter(f => f[0] === 'arms-up')).toHaveLength(5);
});

test('_spawnOne skips the Clawd poof entirely under reduced motion', () => {
  const chain = () => {
    const o = {
      setDepth: () => o, setAlpha: () => o, setScale: () => o, setTexture: () => o,
      setDisplaySize: () => o, play: () => o, destroy() {}, texture: { key: 'fallback' },
    };
    return o;
  };
  const images = [];
  const scene = {
    reducedMotion: true,
    add: {
      sprite: () => chain(),
      image: (...args) => { images.push(args); return chain(); },
      particles: () => ({ setDepth: () => ({ explode() {} }) }),
    },
    addSharpText: () => chain(),
    time: { delayedCall: (ms, fn) => { fn(); return { remove() {} }; } },
    tweens: { add: (cfg) => { cfg.onComplete?.(); return cfg; } },
  };
  const entry = { baseSize: 48, legH: 6, sprite: { x: 100, y: 100, scaleX: 1, active: true } };
  scene.fighters = new Map([[1, entry]]);

  new Minions(scene)._spawnOne(1, entry, 0, 1);

  // The only images created are the minion's own badge (one) — no Clawd
  // pose textures (clawd-default/-crouch/-arms) were ever requested.
  expect(images.some(([, , key]) => typeof key === 'string' && key.startsWith('clawd-'))).toBe(false);
});
