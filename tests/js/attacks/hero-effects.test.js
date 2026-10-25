import { describe, test, expect } from 'vitest';
import { HeroEffects } from '@battlefield/attacks/hero/effects.js';

/**
 * A scene whose tween clock only advances when the test flushes it, with the
 * tween manager's killTweensOf recorded.
 *
 * @return {{scene: object, flush: Function, killed: object[], timers: object[]}}
 */
function fakeScene() {
  const timers = [];
  const killed = [];
  const scene = {
    // the hero clock runs on tweens, so the kill ceremony's slow motion slows it too
    tweens: {
      addCounter: cfg => {
        const t = { ms: cfg.duration, cb: cfg.onComplete, destroyed: false, destroy() { this.destroyed = true; } };
        timers.push(t);
        return t;
      },
      killTweensOf: obj => killed.push(obj),
    },
  };
  const flush = () => {
    for (const t of timers.splice(0)) {
      if (!t.destroyed) t.cb();
    }
  };
  return { scene, flush, killed, timers };
}

/**
 * A display object that records being destroyed.
 *
 * @return {{destroyed: boolean, destroy: Function, once: Function}}
 */
function fakeObject() {
  return { destroyed: false, destroy() { this.destroyed = true; }, once() {} };
}

describe('HeroEffects', () => {
  test('clear() still lands the main contact once and drops the extras', () => {
    const { scene, flush } = fakeScene();
    const effects = new HeroEffects(scene, 120);
    const hits = [];
    effects.schedule(100, () => hits.push('extra-1'));
    effects.schedule(200, () => hits.push('main'), { main: true });
    effects.schedule(300, () => hits.push('extra-2'));

    effects.clear();
    flush();

    expect(hits).toEqual(['main']);
  });

  test('clear() stops and destroys every tracked object', () => {
    const { scene, killed } = fakeScene();
    const effects = new HeroEffects(scene, 120);
    const a = effects.track(fakeObject());
    const b = effects.track(fakeObject());

    effects.clear();

    expect([a.destroyed, b.destroyed]).toEqual([true, true]);
    expect(killed).toEqual([a, b]);
    expect(effects.live).toBe(0);
  });

  test('reports capped once the live objects reach the cap', () => {
    const { scene } = fakeScene();
    const effects = new HeroEffects(scene, 2);
    effects.track(fakeObject());
    expect(effects.capped).toBe(false);
    effects.track(fakeObject());
    expect(effects.capped).toBe(true);
  });

  test('times contacts on the tween clock, which the kill ceremony slows with the flights', () => {
    const { scene, timers } = fakeScene();
    new HeroEffects(scene, 120).schedule(540, () => {}, { main: true });

    expect(timers.map(t => t.ms)).toEqual([540]);
  });
});
