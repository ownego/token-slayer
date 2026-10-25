import { describe, test, expect, vi } from 'vitest';

vi.mock('phaser', () => {
  class Vector2 { constructor(x, y) { this.x = x; this.y = y; } }
  class QuadraticBezier {
    getPoint() { return { x: 0, y: 0 }; }
    getTangent() { return { x: 1, y: 0 }; }
  }
  return {
    default: {
      BlendModes: { ADD: 1 },
      Math: { Between: a => a, Linear: (a, b, t) => a + (b - a) * t, Vector2 },
      Curves: { QuadraticBezier },
      Utils: { Array: { GetRandom: list => list[0] } },
      Animations: { Events: { ANIMATION_COMPLETE: 'animationcomplete' } },
    },
  };
});

const { HeroEffects } = await import('@battlefield/attacks/hero/effects.js');
const { HERO_MOVES, heroAttack } = await import('@battlefield/attacks/hero/index.js');
const { HERO_MAX_CONTACT_MS } = await import('@battlefield/config/heroes.js');
const { FIGHTER_TYPES } = await import('@battlefield/config.js');
const { HERO_FX } = await import('@battlefield/config/hero-fx.js');

/**
 * Any Phaser call returns this same no-op object, so a move can draw freely;
 * numbers read off it are 0.
 *
 * @return {object}
 */
function noop() {
  const target = function () {};
  const stub = new Proxy(target, {
    get: (_, key) => {
      if (key === Symbol.toPrimitive) return () => 0;
      if (['x', 'y', 'scaleX', 'scaleY', 'width', 'height', 'alpha', 'radius'].includes(key)) return 0;
      if (key === 'then') return undefined;
      return stub;
    },
    apply: () => stub,
  });
  return stub;
}

/**
 * A scene that draws nothing and runs its clock only when flushed.
 *
 * @return {{scene: object, flush: Function}}
 */
function fakeScene() {
  const timers = [];
  const scene = new Proxy({
    time: { delayedCall: () => noop(), addEvent: () => noop() },
    tweens: {
      addCounter: cfg => {
        const t = { ms: cfg.duration, cb: cfg.onComplete, destroyed: false, destroy() { this.destroyed = true; } };
        if (cfg.onComplete) timers.push(t);
        return t;
      },
      add: () => noop(),
      killTweensOf: () => {},
    },
    layout: { boss: { anchor: { x: 600, y: 300 } } },
    reducedMotion: false,
  }, { get: (t, key) => (key in t ? t[key] : noop()) });
  scene.heroEffects = new HeroEffects(scene, 120);
  const flush = () => {
    for (let guard = 0; timers.length && guard < 50; guard++) {
      for (const t of timers.splice(0).sort((a, b) => a.ms - b.ms)) {
        if (!t.destroyed) t.cb();
      }
    }
  };
  return { scene, flush };
}

const fighterOf = key => ({
  id: 1,
  ftype: FIGHTER_TYPES.find(ft => ft.key === key),
  pos: { x: 300, y: 400 },
  sprite: Object.assign(noop(), {}),
  body: noop(),
  displaySize: 45,
});

const HEROES = FIGHTER_TYPES.filter(ft => ft.attackType === 'hero');
const MOVES = HEROES.flatMap(h => h.attacks.map(a => [a.move, h.key]));

describe('hero moves', () => {
  test('every move a hero can roll has a handler', () => {
    for (const [move] of MOVES) {
      expect(HERO_MOVES[move], move).toBeDefined();
    }
  });

  test('every effect sheet the game ships is played by some move, and every sheet a move names exists', () => {
    const used = new Set(Object.values(HERO_MOVES).flatMap(m => m.uses));
    expect([...used].filter(k => !(k in HERO_FX))).toEqual([]);
    expect(Object.keys(HERO_FX).filter(k => !used.has(k))).toEqual([]);
  });

  test.each(MOVES)('%s declares exactly one main contact, within the contact-time cap', move => {
    const contacts = HERO_MOVES[move].contacts;
    expect(contacts.filter(c => c.main)).toHaveLength(1);
    expect(contacts.find(c => c.main).atMs).toBeLessThanOrEqual(HERO_MAX_CONTACT_MS);
  });

  test.each(MOVES)('%s lands its hit exactly once, even when the ceremony clears it mid-move', (move, key) => {
    const { scene, flush } = fakeScene();
    const onImpact = vi.fn();

    heroAttack(scene, fighterOf(key), { move, isKillShot: false, onImpact, target: null });
    scene.heroEffects.clear();
    flush();

    expect(onImpact).toHaveBeenCalledOnce();
  });

  test.each(MOVES)('%s lands its hit exactly once when it plays out', (move, key) => {
    const { scene, flush } = fakeScene();
    const onImpact = vi.fn();

    heroAttack(scene, fighterOf(key), { move, isKillShot: false, onImpact, target: null });
    flush();

    expect(onImpact).toHaveBeenCalledOnce();
  });

  test('a super punch cut short puts Superman back on his spot at his own depth', () => {
    const { scene } = fakeScene();
    const fighter = fighterOf('superman');
    const calls = [];
    fighter.sprite = { x: 300, y: 400, depth: 2, active: true, scaleX: 1, scaleY: 1,
      setDepth(d) { calls.push(['depth', d]); this.depth = d; return this; },
      setPosition(x, y) { calls.push(['pos', x, y]); this.x = x; this.y = y; return this; } };

    heroAttack(scene, fighter, { move: 'super-punch', isKillShot: false, onImpact: () => {}, target: null });
    fighter.heroMoveCancel();

    expect(calls.slice(-2)).toEqual([['pos', 300, 400], ['depth', 2]]);
    expect(fighter.heroMoveCancel).toBeNull();
  });

  test('the freeze breath ices the boss with its own copy and never tints the boss sprite, whose tint every hit clears', () => {
    const { scene, flush } = fakeScene();
    const tints = [];
    scene.bossSprite = {
      active: true, x: 600, y: 300, originX: 0.5, originY: 0.5, depth: 5, scaleX: 1, scaleY: 1, flipX: false,
      texture: { key: 'boss' }, frame: { name: 'boss-idle-0' },
      setTint: () => tints.push('setTint'), setTintFill: () => tints.push('setTintFill'), clearTint: () => tints.push('clearTint'),
      getBottomCenter: () => ({ x: 600, y: 350 }),
    };
    const onImpact = vi.fn();

    heroAttack(scene, fighterOf('superman'), { move: 'freeze-breath', isKillShot: false, onImpact, target: null });
    flush();

    expect(onImpact).toHaveBeenCalledOnce();
    expect(tints).toEqual([]);
  });
});
