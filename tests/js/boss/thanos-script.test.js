// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { thanosScript } from '@battlefield/boss/scripts/thanos.js';

const SEEN_KEY = 'ts:stones-seen';

// Just enough scene for the script's lifecycle: Phaser's clock is replaced by
// handles that record their own removal, and reduced motion skips the canvas
// effects so a reveal only touches the DOM sockets.
function fakeScene({ reducedMotion = true } = {}) {
  const timers = [];
  const timer = (cfg) => {
    const t = { cfg, removed: false, remove() { this.removed = true; } };
    timers.push(t);
    return t;
  };
  return {
    timers,
    reducedMotion,
    time: { addEvent: timer, delayedCall: (delay, callback) => timer({ delay, callback }) },
    tweens: { killTweensOf() {} },
    layout: { boss: { anchor: { x: 0, y: 0 } } },
  };
}

const ticker = (scene) => scene.timers.find((t) => t.cfg.loop).cfg.callback;
const lit = () => [...document.querySelectorAll('.bf-stones .stone')].map((g) => g.classList.contains('on'));
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

beforeEach(() => {
  document.body.innerHTML = '<div id="bf-hud"><div class="bf-plate"></div></div>';
  localStorage.clear();
});

afterEach(() => vi.restoreAllMocks());

describe('thanos script', () => {
  test('a stone another tab already revealed lights here on the next tick, without a reload', () => {
    const scene = fakeScene();
    const bossState = { number: 58, script: { stones: 2, stoneSchedule: '' } };
    localStorage.setItem(SEEN_KEY, JSON.stringify({ boss: 58, seen: 0 }));
    const handle = thanosScript.create(scene, bossState);
    expect(lit()).toEqual([false, false, false, false, false, false]);

    localStorage.setItem(SEEN_KEY, JSON.stringify({ boss: 58, seen: 2 })); // tab A watched both
    ticker(scene)();

    expect(lit()).toEqual([true, true, false, false, false, false]);
    thanosScript.destroy(scene, handle);
  });

  test('a stone another tab revealed lights here as soon as that tab writes it', () => {
    const scene = fakeScene();
    const bossState = { number: 58, script: { stones: 1, stoneSchedule: '' } };
    localStorage.setItem(SEEN_KEY, JSON.stringify({ boss: 58, seen: 0 }));
    const handle = thanosScript.create(scene, bossState);

    localStorage.setItem(SEEN_KEY, JSON.stringify({ boss: 58, seen: 1 }));
    window.dispatchEvent(new StorageEvent('storage', { key: SEEN_KEY }));

    expect(lit()[0]).toBe(true);
    thanosScript.destroy(scene, handle);
  });

  test('under reduced motion a revealed stone lights without the pop ring, which would never clear', async () => {
    const scene = fakeScene({ reducedMotion: true });
    const bossState = { number: 59, script: { stones: 1, stoneSchedule: '' } };
    const handle = thanosScript.create(scene, bossState);

    await thanosScript.reveal(scene, bossState, handle);

    const gem = document.querySelector('.bf-stones .stone');
    expect(gem.classList.contains('on')).toBe(true);
    expect(gem.classList.contains('pop')).toBe(false);
    thanosScript.destroy(scene, handle);
  });

  test('destroy releases the ticker, the first-reveal timer, both window listeners and the socket row', async () => {
    const scene = fakeScene();
    const removeDoc = vi.spyOn(document, 'removeEventListener');
    const removeWin = vi.spyOn(window, 'removeEventListener');
    const handle = thanosScript.create(scene, { number: 60, script: { stones: 1, stoneSchedule: '' } });

    thanosScript.destroy(scene, handle);
    await flush();

    expect(scene.timers.every((t) => t.removed)).toBe(true);
    expect(removeDoc).toHaveBeenCalledWith('visibilitychange', handle.onVisibility);
    expect(removeWin).toHaveBeenCalledWith('storage', handle.onStorage);
    expect(document.querySelector('.bf-stones')).toBeNull();
  });
});
