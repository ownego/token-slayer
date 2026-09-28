import { expect, test, vi } from 'vitest';
import { createHpCounter } from '@battlefield/shared/hp-counter.js';

function fakeTweens() {
  const live = [];
  return {
    live,
    startTween({ from, to, onUpdate }) {
      const t = { from, to, onUpdate, stopped: false, stop() { this.stopped = true; } };
      live.push(t);
      return t;
    },
  };
}

test('a new target stops the running tween instead of stacking another', () => {
  const tw = fakeTweens();
  const render = vi.fn();
  const hp = createHpCounter({ startTween: tw.startTween, render, initial: 1000 });

  hp.set(900);
  hp.set(800);
  hp.set(700);

  expect(tw.live.filter(t => !t.stopped)).toHaveLength(1);
  expect(tw.live.at(-1).to).toBe(700);
});

test('the new tween starts from the value currently shown, not the old target', () => {
  const tw = fakeTweens();
  const hp = createHpCounter({ startTween: tw.startTween, render: () => {}, initial: 1000 });

  hp.set(500);
  tw.live[0].onUpdate(750);   // halfway through the first tween
  hp.set(400);

  expect(tw.live[1].from).toBe(750);
});

test('reset on respawn stops the killing blow\'s tween and shows full HP at once', () => {
  const tw = fakeTweens();
  const render = vi.fn();
  const hp = createHpCounter({ startTween: tw.startTween, render, initial: 100 });
  hp.set(0);
  hp.reset(5000);
  expect(tw.live[0].stopped).toBe(true);
  expect(render).toHaveBeenLastCalledWith(5000);
  hp.set(4000);
  expect(tw.live[1].from).toBe(5000);
});

test('render only fires when the rounded value changes', () => {
  const tw = fakeTweens();
  const render = vi.fn();
  const hp = createHpCounter({ startTween: tw.startTween, render, initial: 1000 });

  hp.set(990);
  tw.live[0].onUpdate(999.6);
  tw.live[0].onUpdate(999.9);
  tw.live[0].onUpdate(998.2);

  expect(render.mock.calls.map(c => c[0])).toEqual([1000, 998]);
});
